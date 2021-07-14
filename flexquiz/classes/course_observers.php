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
 * Course observers of the flexquiz module
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_flexquiz;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot. '/mod/flexquiz/classes/child_creation.php');

class course_observers
{

    /**
     * Static function which deletes all recently completed quizzes and updates
     * quiz 'intro' fields
     * 
     * @param \core\event\course_viewed $event object which contains the relevant event data
     */
    public static function delete_completed_quizzes(\core\event\course_viewed $event) {
        global $DB;

        // get relevant info for the child quizzes which are part of the current course
        $courseid = $event->get_data()['courseid'];
        $sql = 'SELECT cm.id,
                       cm.instance,
                       cm.section AS sectionid,
                       ac.quizid,
                       ac.active,
                       q.timeopen,
                       q.timeclose,
                       q.intro
                FROM {course_modules} AS cm
                INNER JOIN {modules} AS m ON m.id=cm.module
                INNER JOIN {flexquiz_children} AS ac ON ac.quizid=cm.instance
                INNER JOIN {quiz} AS q ON q.id=ac.quizid
                WHERE cm.course=? AND m.name=?
        ';

        $params=[$courseid, 'quiz'];
        $coursemodules = $DB->get_records_sql($sql, $params);
        
        $reload = false;
        foreach ($coursemodules as $cm) {
            if (!$cm->active) {
                // delete inactive child quizzes and remove them from the course view
                $reload = true;

                $transaction = $DB->start_delegated_transaction();

                delete_mod_from_section($cm->id, $cm->sectionid);
                $DB->delete_records('course_modules', array('id' => $cm->id));
                quiz_delete_instance($cm->instance);

                $transaction->allow_commit();
            } else {
                // check if quiz descriptions are up-to-date and update them if necessary
                $description = \mod_flexquiz\child_creation\flexquiz_student_item::create_availability_info($cm->timeopen, $cm->timeclose, time());
                if (strcmp($cm->intro, $description) !== 0) {
                    $DB->update_record('quiz', array('id' => $cm->quizid, 'intro' => $description));
                    rebuild_course_cache($courseid, false);
                    $reload = true;
                }
            }
        }
        if($reload) {
            // this is not ideal and should be replaced with a better solution
            redirect(new \moodle_url('/course/view.php', array('id' => $courseid)));
        }
    }


    /**
     * Static function which triggers all the actions necessary to include the newly
     * enrolled student in all flex quizzes in the given course.
     *
     * @param \core\event\role_assigned $event object which contains the relevant event data
     */
    public static function handle_user_enrolment(\core\event\role_assigned $event) {
        global $DB;

        $eventdata = $event->get_data();
        $userid = $eventdata['relateduserid'];
        $courseid = $eventdata['courseid'];

        $hascapability = has_capability('mod/quiz:attempt', \context_course::instance($courseid), $userid);

        // if the enrolled student now has the attempt quiz capability, add fqs items for all fqs in the course
        // and trigger creation of the initial child quizzes
        if ($hascapability) {
            $activefqs = $DB->get_records('flexquiz', array('course' => $courseid));

            foreach ($activefqs as $activefq) {
                if (!$DB->record_exists('flexquiz_student', array('student' => $userid, 'flexquiz' => $activefq->id))) {
                    $sql = "SELECT cm.id
                            FROM {course_modules} AS cm
                            INNER JOIN {modules} AS m ON m.id=cm.module
                            WHERE cm.instance=? AND m.name='flexquiz'
                            ";
                    $params = [$activefq->id];
                    $cmid = $DB->get_record_sql($sql, $params)->id;

                    $activefq->cmid = $cmid;
                    $fq = \mod_flexquiz\child_creation\flex_quiz::create($activefq);

                    $transaction = $DB->start_delegated_transaction();
                    $fq->create_initial_data_for_student($userid, $activefq->sectionid);
                    $transaction->allow_commit();
                }
            }
        }
    }

    /**
     * Static function which deletes student data associated with flex quizzes from a course
     * on unenrolling a student from said course
     *
     * @param \core\event\role_unassigned $event object which contains the relevant event data
     */
    public static function handle_user_unenrolment(\core\event\role_unassigned $event) {
        global $DB;

        $eventdata = $event->get_data();
        $userid = $eventdata['relateduserid'];
        $courseid = $eventdata['courseid'];

        $hascapability = has_capability('mod/quiz:attempt', \context_course::instance($courseid), $userid);

        // if the unenrolled user does not have the attempt quiz capability any more, delete his fqs data
        if (!$hascapability) {
            $coursefqs = $DB->get_records('flexquiz', array('course' => $courseid));

            $transaction = $DB->start_delegated_transaction();
            foreach ($coursefqs as $fq) {
                $fqsitem = $DB->get_record(
                    'flexquiz_student',
                    array('student' => $userid, 'flexquiz' => $fq->id),
                    'id, groupid'
                );
                if ($fqsitem) {
                    // delete stashed records
                    $stashids = $DB->get_fieldset_select(
                        'flexquiz_stash',
                        'id',
                        'flexquiz_student_item = ?',
                        array($fqsitem->id)
                    );
                    if ($stashids && !empty($stashids)) {
                      list($insql, $inparams) = $DB->get_in_or_equal($stashids);
                      $DB->delete_records_select('flexquiz_stash_questions', "stashid $insql", $inparams);
                      $DB->delete_records('flexquiz_stash', array('flexquiz_student_item' => $fqsitem->id));
                    }

                    // delete course group of user
                    groups_delete_group($fqsitem->groupid);

                    // delete children and fqs item
                    $sql = 'SELECT cm.id,
                                   cm.instance,
                                   cm.section
                            FROM {course_modules} AS cm
                            INNER JOIN {modules} AS m ON m.id=cm.module
                            INNER JOIN {flexquiz_children} AS ac ON ac.quizid=cm.instance
                            WHERE ac.flexquiz_student_item=? AND m.name=?
                    ';
                    $params=[$fqsitem->id, 'quiz'];
                    $coursemodules = $DB->get_records_sql($sql, $params);

                    foreach($coursemodules as $cm) {
                        delete_mod_from_section($cm->id, $cm->section);
                        $DB->delete_records('course_modules', array('id' => $cm->id));
                        quiz_delete_instance($cm->instance);
                    }

                    $DB->delete_records('flexquiz_children', array('flexquiz_student_item' => $fqsitem->id));
                    $DB->delete_records('flexquiz_student', array('id' => $fqsitem->id));
                }
            }
            $transaction->allow_commit();
        }
    }
}
