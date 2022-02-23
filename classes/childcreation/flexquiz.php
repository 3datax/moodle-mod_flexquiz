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
 * The flexquiz_student_item class used by the flexquiz module
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_flexquiz\childcreation;

defined('MOODLE_INTERNAL') || die();

use stdClass;

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');

/**
 * A class encapsulating an flexquiz
 *
 * @copyright
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since
 */
class flexquiz {
    /** The default value for max question count */
    const MAX_QUESTIONS_DEFAULT = 10;

    /** @var stdClass the course_module settings from the database. */
    protected $cm;
    /** @var stdClass the flexquiz settings from the database. */
    protected $flexquiz;
    /** @var stdClass the course_section settings from the database. */
    protected $section;

    /**
     * Constructor.
     *
     * @param object $flexquiz the row from the flexquiz table.
     * @param object $cm the course_module object for this flexquiz.
     * @param object $section the course_section object for this flexquiz.
     */
    public function __construct($flexquiz, $cm, $section) {
        $this->flexquiz = $flexquiz;
        $this->cm = $cm;
        $this->section = $section;
    }

    /**
     * Static function to create a new flexquiz object.
     *
     * @param stdClass $flexquiz the flexquiz object.
     * @return stdClass $fquiz the new flexquiz object.
     */
    public static function create($flexquiz) {
        global $DB;

        $cm = $DB->get_record('course_modules', array('id' => $flexquiz->cmid));
        if (property_exists($flexquiz, 'section')) {
            $section = $DB->get_record('course_sections', array('course' => $flexquiz->course, 'section' => $flexquiz->section));
        } else {
            $sql = 'SELECT cs.*
                    FROM {course_sections} cs
                    INNER JOIN {course_modules} cm ON  cm.section=cs.id
                    WHERE cm.id=?
            ';
            $params = [$cm->id];
            $section = $DB->get_record_sql($sql, $params);
        }

        $fquiz = new flexquiz($flexquiz, $cm, $section);

        return $fquiz;
    }

    /**
     * Getter for the section variable
     * @return stdClass section
     */
    public function get_section() {
        return $this->section;
    }

    /**
     * Creates the initial batch of child quizzes. Exactly one quiz per eligible user is created.
     * Eligibility requires enrolment in the given course and the capability 'mod/quiz:attempt'.
     */
    public function create_first_children() {
        global $DB, $CFG;

        // Get eligible users.
        $context = \context_module::instance($this->cm->id);
        $capjoin = get_enrolled_with_capabilities_join($context, '', 'mod/quiz:attempt');
        $users = $DB->get_records_sql("SELECT u.id FROM {user} u $capjoin->joins WHERE $capjoin->wheres", $capjoin->params);

        $transaction = $DB->start_delegated_transaction();

        // Create flexquiz_student_items and initial quizzes.
        $newsection = $this->create_child_quiz_section($this->flexquiz, $this->section);

        foreach ($users as $student) {
            $this->create_initial_data_for_student($student, $newsection);
        }

        // Update grade.
        grade_update(
            'mod/flexquiz',
            $this->flexquiz->course,
            'mod',
            'flexquiz',
            $this->flexquiz->id,
            0,
            null,
            array('itemname' => $this->flexquiz->name, 'grademax' => 10.0)
        );

        $transaction->allow_commit();
    }

    /**
     * Creates the flexquiz student item (if such an item does not exist yet) and the initial child quiz
     * for a specific FQ/student combination.
     *
     * @param stdClass|int $studentorid the user object or user id of the student.
     * @param stdClass|int $sectionorid the object or id of the section in which the child quiz shall be created.
     */
    public function create_initial_data_for_student($studentorid, $sectionorid) {
        global $DB;

        if (is_object($studentorid)) {
            $studentid = $studentorid->id;
        } else {
            $studentid = $studentorid;
        }
        if (is_object($sectionorid)) {
            $section = $sectionorid;
        } else {
            $section = $DB->get_record('course_sections', array('id' => $sectionorid));
        }

        $time = time();
        $cycle = self::get_cycle_info($this->flexquiz, $time)->cyclenumber;

        $fqsitemid = $DB->get_record(
            'flexquiz_student',
            array('student' => $studentid, 'flexquiz' => $this->flexquiz->id)
        );
        if (!$fqsitemid) {
            $fqsitemid = $DB->insert_record('flexquiz_student', array(
                'flexquiz' => $this->flexquiz->id,
                'student' => $studentid,
                'groupid' => 0,
                'instances' => 0,
                'instances_this_cycle' => 0,
                'cyclenumber' => intval($cycle)
            ));
        }

        $fqsitem = flexquiz_student_item::create($fqsitemid);
        $questions = array();

        // If ai is used, get new questions from said ai.
        if ($this->flexquiz->usesai) {
            $questions = $fqsitem->get_new_tasks(
                $cycle,
                $this->flexquiz->parentquiz,
                null,
                $time,
                'initialize'
            );
        } else {
            // If no ai is used, randomly choose questions.
            $questions = $this->get_random_questions();
        }
        if (!empty($questions)) {
            $moduleid = $fqsitem->create_child($questions, $time, $section);
        } else {
            // Empty responses from the ai should not trigger quiz creation.
            debugging(get_string('emptyquiz', 'flexquiz'), DEBUG_DEVELOPER);
        }
    }

    /**
     * Creates the section in which to dump the child quizzes for a
     * specific flexquiz.
     *
     * @param stdClass $flexquiz row from the database
     * @param stdClass $section the flexquiz is in
     * @param stdClass $course the flexquiz is in
     *
     * @return stdClass the newly created section
     */
    public static function create_child_quiz_section($flexquiz, $section = null, $course = null) {
        global $DB;

        if (!$course) {
            $course = $DB->get_record('course', array('id' => $flexquiz->course));
        }
        if (!$section) {
            $sql = "SELECT cs.*
                    FROM {flexquiz} fq
                    INNER JOIN {course_modules} cm ON cm.instance=fq.id
                    INNER JOIN {modules} m ON cm.module=m.id
                    INNER JOIN {course_sections} cs ON cs.id=cm.section
                    WHERE fq.id=? AND cm.module='flexquiz'
            ";
            $params = [$flexquiz->id];
            $section = $DB->get_record_sql($sql, $params);
        }

        // Add new section for child quizzes.
        $format = course_get_format($course);
        $newsectionname = $flexquiz->name . ' - ' . get_string('sectionname', 'flexquiz');

        if ($course->format === 'flexsections') {
            // Check for 'flexsections' format plugin first.
            $newsectionnum = $format->create_new_section($section->section);
            $newsection = $DB->get_record('course_sections', array('course' => $course->id, 'section' => $newsectionnum));

            $options = array(
                'id' => $newsection->id,
                'parent' => $section->section,
                'visibleold' => true,
                'collapsed' => true
            );
            $format->update_section_format_options($options);
        } else {
            $newsectionnum = self::get_child_quiz_section_num($section);
            $newsection = course_create_section($course, $newsectionnum);
        }

        $DB->update_record(
            'flexquiz',
            array('id' => $flexquiz->id, 'sectionid' => $newsection->id)
        );
        course_update_section($course, $newsection, array('name' => $newsectionname));
        return $newsection;
    }

    /**
     * Finds the section number which equals the last flexquiz child quiz section
     * number + 1 (of only those flex quizzes which share the same section.)
     *
     * @param stdClass $section the flexquiz is in
     * @return int the new section number
     */
    private static function get_child_quiz_section_num($section) {
        global $DB;

        $lastsection = intval($section->section);

        $sql = "SELECT fq.id, COALESCE(fq.sectionid, -1) AS sectionid
                FROM {flexquiz} fq
                INNER JOIN {course_modules} cm ON cm.instance=fq.id
                INNER JOIN {modules} m ON m.id=cm.module
                INNER JOIN {course_sections} cs ON cs.id=cm.section
                WHERE cs.id=? AND m.name='flexquiz'
        ";
        $params = [$section->id];
        $record = $DB->get_records_sql($sql, $params);

        if ($record && !empty($record)) {
            list($insql, $params) = $DB->get_in_or_equal(array_column($record, 'sectionid'), SQL_PARAMS_NAMED);
            $sql = "SELECT COALESCE(MAX(cs.section), :defaultsectionnum) AS lastsection
                    FROM {course_sections} cs
                    WHERE cs.id $insql
            ";
            $params += array('defaultsectionnum' => $lastsection);
            $cs = $DB->get_record_sql($sql, $params);
            $lastsection = intval($cs->lastsection);
        }

        return intval($lastsection) + 1;
    }

    /**
     * Picks a given number of questions randomly from a pool
     * of questions.
     *
     * @return stdClass[] containing question ids and question types
     */
    private function get_random_questions() {
        global $DB;

        // Get eligible questions.
        $sql = 'SELECT q.id, q.qtype
                FROM {question} q
                INNER JOIN {quiz_slots} qs ON qs.questionid=q.id
                WHERE qs.quizid=?';
        $params = [$this->flexquiz->parentquiz];
        $questions = $DB->get_records_sql($sql, $params);

        // Randomly choose questions out of the pool.
        $total = count($questions);
        $number = $this->flexquiz->maxquestions;
        if (!$number) {
            $number = self::MAX_QUESTIONS_DEFAULT;
        }
        if ($total < $number) {
            $number = $total;
        }
        $limit = $total - $number;

        $newquestions = array();
        while ($total > $limit) {
            $index = rand(0, $total - 1);
            array_push($newquestions, array_splice($questions, $index, 1));
            $total--;
        }

        return array_map('end', $newquestions);
    }

    /**
     * Static function which checks if a given quiz is a child of an flex quiz.
     *
     * @param stdClass|int $quizorid quiz object or id for which the check shall be performed.
     * @return bool true if the given quiz is a child quiz, false else.
     */
    public static function is_child_quiz($quizorid) {
        global $DB;
        if (is_object($quizorid)) {
            $quizid = $quizorid->id;
        } else {
            $quizid = $quizorid;
        }
        return $DB->record_exists('flexquiz_children', array('quizid' => $quizid));
    }

    /**
     * Get the number of the cycle active at a specific point in time and the info
     * if the end of the flexquiz has been reached.
     *
     * @param int $time timestamp for which this check should be performed.
     * @param stdClass $fqData object containing cycleduration, startdate and enddate
     *  from the flexquiz table
     *
     * @return stdClass $result containing the cyclenumber and the hasended boolean.
     */
    public static function get_cycle_info($fqdata, $time) {
        $result = new stdClass();

        $end = intval($fqdata->enddate);
        $hasended = boolval($end > 0 && $end < $time);
        $checkpoint = $time;

        if (!$fqdata->cycleduration) {
            $cyclenumber = 0;
        } else {
            if ($hasended) {
                $checkpoint = $end;
            }

            $cyclenumber = intval(
                floor(($checkpoint - intval($fqdata->startdate)) / $fqdata->cycleduration)
            );
        }

        if ($cyclenumber > 0) {
            if ($hasended = self::cycles_are_overflowing($fqdata, $cyclenumber)) {
                $cyclenumber -= 1;
            }
        }
        $result->cyclenumber = $cyclenumber;
        $result->hasended = $hasended;

        return $result;
    }

    /**
     * Check if the given cycle exceeds the end date of the flex quiz.
     *
     * @param stdClass $flexquiz
     * @param int $cycle number of the cycle for which to do the check.
     *
     * @return bool true if the limits do not allow the given cycle, false else.
     */
    public static function cycles_are_overflowing($flexquiz, $cycle) {
        if (!$flexquiz->cycleduration) {
            return boolval($cycle > 0);
        } else if (!$flexquiz->enddate) {
            return false;
        } else {
            return boolval(($flexquiz->startdate + (($cycle + 1) * $flexquiz->cycleduration)) > $flexquiz->enddate);
        }
    }
}
