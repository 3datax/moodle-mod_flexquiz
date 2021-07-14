<?php
// This file is part of Moodle - https://moodle.org/
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
 * Library functions of the flexquiz module called from outside of the module
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds an instance of flexquiz
 *
 * @param stdClass $flexquiz data from the settings form
 * @return int instance id
 */
function flexquiz_add_instance($flexquiz)
{
  global $DB, $COURSE, $CFG;
  require_once($CFG->dirroot . '/mod/flexquiz/classes/child_creation.php');;

  $flexquiz->timecreated = time();
  $flexquiz->timemodified = $flexquiz->timecreated;
  $flexquiz->course = $COURSE->id;
  $flexquiz->introformat = FORMAT_MOODLE;

  $flexquiz->id = $DB->insert_record('flexquiz', $flexquiz, true);
  $flexquiz->cmid = $flexquiz->coursemodule;

  // create children
  $fq = \mod_flexquiz\child_creation\flex_quiz::create($flexquiz);
  $fq->create_first_children();

  return $flexquiz->id;
}

/**
 * Updates an instance of flexquiz
 *
 * @param stdClass $flexquiz data from the settings form
 * @return bool true on success
 */
function flexquiz_update_instance($flexquiz) {
  global $DB;

  $flexquiz->id = $flexquiz->instance;
  $flexquiz->timemodified = time();

  $DB->update_record('flexquiz', $flexquiz);

  return true;
}

/**
 * Deletes an instance of flexquiz
 *
 * @param int $flexquizid id of the flexQuiz to be deleted
 * @return bool true on success
 */
function flexquiz_delete_instance($flexquizid) {
  global $DB, $CFG;

  require_once($CFG->dirroot . '/group/lib.php');

  // get flexquiz record from db
  $flexquiz = $DB->get_record('flexquiz', array('id' => $flexquizid));
  if(!$flexquiz) {
    return false;
  }

  $transaction = $DB->start_delegated_transaction();

  // delete groups
  $fqsitems = $DB->get_records('flexquiz_student', array('flexquiz' => $flexquizid));
  foreach ($fqsitems as $item) {
    $sql = "SELECT *
            FROM {flexquiz_student} AS fqs
            INNER JOIN {flexquiz} AS a ON a.id=fqs.flexquiz
            WHERE fqs.groupid=?
            AND fqs.id != ?
            AND a.course=?";
    $params = [$item->groupid, $item->id, $flexquiz->course];

    if (!$DB->record_exists_sql($sql, $params)) {
      groups_delete_group($item->groupid);
    }

    // remove data from plugin db tables
    $stashids = $DB->get_fieldset_select('flexquiz_stash', 'id', 'flexquiz_student_item = ?', array($item->id));
    if ($stashids && !empty($stashids)) {
      list($insql, $inparams) = $DB->get_in_or_equal($stashids);
      $DB->delete_records_select('flexquiz_stash_questions', "stashid $insql", $inparams);
      $DB->delete_records('flexquiz_stash', array('flexquiz_student_item' => $item->id));
    }
    $DB->delete_records('flexquiz_children', array('flexquiz_student_item' => $item->id));
    $DB->delete_records('flexquiz_student', array('id' => $item->id));
    $DB->delete_records('flexquiz_grades_question', array('flexquiz_student_item' => $item->id));
  }

  // remove grade item
  grade_update('mod/flexquiz', $flexquiz->course, 'mod', 'flexquiz', $flexquizid, 0, null, ['deleted' => 1]);

  // remove child quiz sections and reorder course sections
  $section = $DB->get_field('course_sections', 'section', array('id' => $flexquiz->sectionid));
  if ($section) {
    course_delete_section($flexquiz->course, $section);
    $format = course_get_format($flexquiz->course);
    $sectionsinfo = $format->get_sections();

    $sectionstoreorder = array_filter(array_column($sectionsinfo, 'section'), function($num) use ($section) {
      return $num >= intval($section);
    });

    if ($sectionstoreorder) { 
      list($insql, $params) = $DB->get_in_or_equal($sectionstoreorder, SQL_PARAMS_NAMED);
        $sql = "UPDATE {course_format_options}
                SET `value` = `value` - 1
                WHERE `value` $insql
                AND courseid=:courseid
                AND `format`='flexsections'
                AND `name`='parent'   
      ";

      $params += array('courseid' => $flexquiz->course);
      $DB->execute($sql, $params);
    }
  }

  $DB->delete_records('flexquiz', array('id' => $flexquizid));
  
  $transaction->allow_commit();
  
  return true;
}
