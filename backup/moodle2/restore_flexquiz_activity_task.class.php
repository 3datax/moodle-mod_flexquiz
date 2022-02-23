<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Restore task file of the flexquiz module
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/flexquiz/backup/moodle2/restore_flexquiz_stepslib.php');

/**
 * Flexquiz restore task
 */
class restore_flexquiz_activity_task extends restore_activity_task {

    /**
     * Define flexquiz restore settings
     */
    protected function define_my_settings() {
        // No settings yet.
    }

    /**
     * Define restore steps for flexquiz
     */
    protected function define_my_steps() {
        $this->add_step(
          new restore_flexquiz_activity_structure_step('flexquiz_structure', 'flexquiz.xml')
        );
    }

    /**
     * Decoding rules for flexquiz content
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('flexquiz', array('intro'), 'flexquiz');

        return $contents;
    }

    /**
     * Decoding rules for flexquiz links
     */
    public static function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('FLEXQUIZINDEX', '/mod/flexquiz/index.php?id=$1', 'course');
        $rules[] = new restore_decode_rule('FLEXQUIZVIEWBYID', '/mod/flexquiz/view.php?id=$1', 'course_module');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * flexquiz logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('flexquiz', 'add', 'view.php?id={course_module}', '{flexquiz}');
        $rules[] = new restore_log_rule('flexquiz', 'update', 'view.php?id={course_module}', '{flexquiz}');
        $rules[] = new restore_log_rule('flexquiz', 'view', 'view.php?id={course_module}', '{flexquiz}');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('flexquiz', 'view all', 'index.php?id={course}', null);

        return $rules;
    }

    public function after_restore() {
        global $DB;

        $activityid = $this->get_activityid();
        $flexquiz = $DB->get_record('flexquiz', array('id' => $activityid));

        // Connect the flexquiz with its parentquiz.
        $mappedparentquiz = restore_dbops::get_backup_ids_record(
            $this->get_restoreid(),
            'quiz',
            $flexquiz->parentquiz
        );

        if (!$mappedparentquiz) {
            // A flexquiz cannot exist without its parent quiz.
            flexquiz_delete_instance($flexquiz->id);
            $this->get_logger()->process("Failed to restore dependency in flexquiz module '$flexquiz->name'. " .
                "Backup and restore will not work correctly unless you include the parent quiz.",
                backup::LOG_ERROR);
        } else {
            $flexquiz->parentquiz = $mappedparentquiz->newitemid;
            $DB->update_record('flexquiz', $flexquiz);

            // Connect the child quizzes to our flexquiz.
            $sql = "SELECT c.*
                        FROM {flexquiz_children} c
                        INNER JOIN {flexquiz_student} fqs ON fqs.id=c.flexquiz_student_item
                    WHERE fqs.flexquiz=?
            ";
            $params = array($activityid);
            $children = $DB->get_records_sql($sql, $params);

            $courseid = $this->get_courseid();
            $course = $DB->get_record('course', array('id' => $courseid));
            $cm = get_coursemodule_from_instance('flexquiz', $flexquiz->id);
            $parentsection = $DB->get_record('course_sections', array('id' => $cm->section));

            $sectionid = 0;
            foreach ($children as $child) {
                $mappedrecord = restore_dbops::get_backup_ids_record(
                    $this->get_restoreid(),
                    'quiz',
                    $child->quizid
                );

                if ($mappedrecord && $mappedrecord->newitemid) {
                    // The child quiz has been restored and will be used as a child again.
                    $newid = $mappedrecord->newitemid;
                    $DB->update_record('flexquiz_children', array('id' => $child->id, 'quizid' => $newid));
                    if (!$sectionid) {
                        // At this point the child quiz section is not set yet.
                        // We extract its id from the child we're currently processing.
                        $childcm = get_coursemodule_from_instance('quiz', $newid);
                        $childsection = $DB->get_record('course_sections', array('id' => $childcm->section));
                        $sectionid = $childsection->id;

                        // Write the sectionid to the DB immediately, as the next child might need it.
                        $DB->update_record('flexquiz', array('id' => $flexquiz->id, 'sectionid' => $sectionid));
                    }
                } else if ($child->active) {
                    // Create a new child quiz, as there is no mapped id for the old child.
                    // Any leftover child quizzes need to be removed manually.
                    $DB->delete_records('flexquiz_children', array('id' => $child->id));

                    if (!$sectionid) {
                        // Create a new child quiz section, as the old one got lost in the restoring process.
                        $childsection = \mod_flexquiz\childcreation\flexquiz::create_child_quiz_section(
                            $flexquiz,
                            $parentsection,
                            $course
                        );
                        $sectionid = $childsection->id;
                    }

                    $fqs = $DB->get_record('flexquiz_student', array('id' => $child->flexquiz_student_item));
                    $fqsitem = \mod_flexquiz\childcreation\flexquiz_student_item::create($fqs);
                    $fqsitem->trigger_child_creation(time());
                } else {
                    // Inactive children are of no use and should be removed.
                    $DB->delete_records('flexquiz_children', array('id' => $child->id));
                }
            }

            // If there were no children to process, create the child quiz section here.
            if (!$sectionid && !$DB->get_field('flexquiz', 'sectionid', array('id' => $flexquiz->id))) {
                \mod_flexquiz\childcreation\flexquiz::create_child_quiz_section($flexquiz, $parentsection, $course);
            }

            // There is no way to safely tell whether there was an active child quiz or not.
            // Hence we create a child for every ungraded flexquiz_student_item.
            $sql = "SELECT *
                        FROM {flexquiz_student}
                    WHERE id NOT IN (SELECT flexquiz_student_item
                                        FROM {flexquiz_children}
                                     WHERE active=1)
                    AND flexquiz=?
                    AND graded=0
            ";
            $params = array($flexquiz->id);
            $childlessfqsitems = $DB->get_records_sql($sql, $params);

            foreach ($childlessfqsitems as $item) {
                $fqsitem = \mod_flexquiz\childcreation\flexquiz_student_item::create($item);
                $fqsitem->trigger_child_creation(time());
            }
        }
    }
}
