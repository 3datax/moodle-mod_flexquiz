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
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');

/**
 * A class encapsulating an flexquiz_student_item created by an flexquiz
 *
 * @copyright
 * @license
 * @since
 */
class flexquiz_student_item {
    /** The penalty on cycle change */
    const NEW_CYCLE_PENALTY = 0.5;
    /** A factor to calculate grades */
    const GRADE_MULTIPLIER = 10;

    /** @var stdClass the flexquiz to which this child quiz belongs. */
    protected $flexquiz;

    /** @var stdClass[] of question objects including grade data from the flexquiz_grades_question table. */
    protected $gradedata = array();

    /** @var int $studentid id of the student for whom this child quiz is created. */
    protected $studentid;

    /** @var stdClass the row from the flexquiz_student table. */
    protected $fqsdata;

    /**
     * Constructor.
     *
     * @param stdClass $flexquiz the row from the flexquiz table.
     * @param stdClass[] $gradedata the questions pool including performance data
     * from flexquiz_grades_question.
     * @param int $studentid id of the student for whom this quiz was created.
     * @param stdClass[] $fqsdata row from the flexquiz_student table.
     */
    public function __construct($flexquiz, $gradedata, $studentid, $fqsdata) {
        $this->flexquiz = $flexquiz;
        $this->gradedata = $gradedata;
        $this->studentid = $studentid;
        $this->fqsdata = $fqsdata;
    }

    /**
     * Static function to create a new flexquiz_student_item object for a specific user.
     *
     * @param stdClass|int $fqsitemorid the row from the flexquiz_student table or its id.
     *
     * @return stdClass $fqsitem the new flexquiz_student_item object.
     */
    public static function create($fqsitemorid) {
        global $DB;

        if (!is_object($fqsitemorid)) {
            $fqsdata = $DB->get_record('flexquiz_student', array('id' => $fqsitemorid), '*', MUST_EXIST);
        } else {
            $fqsdata = $fqsitemorid;
        }
        $studentid = $fqsdata->student;

        $flexquiz = $DB->get_record('flexquiz', array('id' => $fqsdata->flexquiz));

        $sql = 'SELECT DISTINCT q.id AS question,
                            q.name,
                            q.qtype,
                            q.category,
                            s.includingsubcategories,
                            s.slot,
                            COALESCE(p.ccas_this_cycle, 0) AS ccas_this_cycle,
                            COALESCE(p.rating, 0) AS rating,
                            COALESCE(p.fraction, 0) AS fraction,
                            COALESCE(p.attempts, 0) AS attempts,
                            COALESCE(p.timemodified, 0) AS timemodified,
                            COALESCE(p.roundupcomplete, 0) AS roundupcomplete
            FROM {question} q
            INNER JOIN {quiz_slots} s ON s.questionid=q.id
            INNER JOIN {flexquiz} a ON a.parentquiz=s.quizid
            INNER JOIN {flexquiz_student} fqs ON fqs.flexquiz=a.id
            LEFT JOIN {flexquiz_grades_question} p ON p.question=s.questionid AND p.flexquiz_student_item=fqs.id
            WHERE s.quizid=? AND fqs.student=? AND a.id=?
            ORDER BY q.id ASC';

        $params = [$flexquiz->parentquiz, $studentid, $flexquiz->id];
        $gradedata = $DB->get_records_sql($sql, $params);

        $fqsitem = new flexquiz_student_item($flexquiz, $gradedata, $studentid, $fqsdata);

        return $fqsitem;
    }

    /**
     * Getter for the current flexquiz object.
     *
     * @return stdClass the current flexquiz object.
     */
    public function get_flexquiz() {
        return $this->flexquiz;
    }

    /**
     * Getter for the current gradedata array.
     *
     * @return stdClass[] the current grade.
     */
    public function get_grades() {
        return $this->gradedata;
    }

    /**
     * Getter for the current module grade.
     *
     * @return float the current grade.
     */
    public function get_sum_grade() {
        $grades = grade_get_grades(
            $this->flexquiz->course,
            'mod',
            'flexquiz',
            $this->flexquiz->id,
            $this->studentid
        );

        return floatval($grades->items[0]->grades[$this->studentid]->grade) * (100.0 / self::GRADE_MULTIPLIER);
    }

    /**
     * Creates a child quiz.
     *
     * @param stdClass[] $questions array of objects containing data for the questions to be added to
     * the quiz to be created.
     * @param int $time of creation
     * @param stdClass|null $section in which this quiz should be created.
     * @param int $starttime of the newly created quiz. If no value is given here the standard calculation
     * quiz starttime is applied.
     * @return int $newmoduleid id of the corresponding course module.
     */
    public function create_child(
        $questions,
        $time,
        $section = null,
        $starttime = 0
    ) {
        global $DB;

        // Fetch section data if not given.
        if (!$section) {
            $sql = "SELECT cs.*
              FROM {course_sections} cs
              INNER JOIN {course_modules} cm ON cm.section=cs.id
              INNER JOIN {modules} m ON m.id=cm.module
              WHERE m.name=? AND cm.instance=?";

            $params = array('flexquiz', $this->flexquiz->id);
            $section = $DB->get_record_sql($sql, $params);
        }

        $activechild = $DB->get_record(
            'flexquiz_children',
            array('flexquiz_student_item' => $this->fqsdata->id, 'active' => 1)
        );

        // Update instances and temporal boundaries.
        $instance = intval($this->fqsdata->instances) + 1;

        $start = $time;
        if ($activechild) {
            $start += $this->flexquiz->pauseduration;
        }
        if ($starttime && $starttime > $start) {
            $start = $starttime;
        }
        $end = $this->flexquiz->enddate;
        $timelimit = $this->flexquiz->customtimelimit;

        // Persist child quiz.
        $quizname = get_string('pluginname', 'flexquiz') .
        ' ' .
        $this->flexquiz->name .
        ' ' .
        get_string('iteration', 'flexquiz') .
        ' ' .
        $instance;
        $quizid = $this->persist_child($quizname, $start, $end, $timelimit, $time);

        // Add questions to new quiz.
        if (!empty($questions)) {
            $this->add_questions_to_child($quizid, $questions);
        }

        // Add course module to section.
        $module = $DB->get_record('modules', array('name' => 'quiz'));
        $newmoduleid = $this->add_child_cm_info($module->id, $quizid, $section->id);
        course_add_cm_to_section($this->flexquiz->course, $newmoduleid, $section->section, null);

        // Add group information and restrictions to child quiz.
        $student = $DB->get_record(
            'user',
            array('id' => $this->studentid),
            'firstname, lastname',
            MUST_EXIST
        );

        $groupname = 'FQ - ' . $student->firstname . ' ' . $student->lastname;

        $this->add_child_group_and_restrictions($groupname, $newmoduleid);

        // Add flexquiz child quiz information to db.
        if ($activechild) {
            $select = 'flexquiz_student_item = ? AND NOT quizid = ?';
            $params = [$this->fqsdata->id, $quizid];
            $DB->set_field_select('flexquiz_children', 'active', 0, $select, $params);
        }
        $data = array(
            'id' => $this->fqsdata->id,
            'instances' => $instance,
            'cyclenumber' => $this->fqsdata->cyclenumber
        );
        $DB->update_record('flexquiz_student', $data);

        $childdata = array(
            'quizid' => $quizid,
            'active' => 1,
            'flexquiz_student_item' => $this->fqsdata->id
        );

        $newquiz = $DB->insert_record('flexquiz_children', $childdata);
        rebuild_course_cache($this->flexquiz->course, true);

        return $newmoduleid;
    }

    /**
     * Function to persist child data.
     *
     * @param string $quizname the name of the quiz to be persisted.
     * @param int $start the starting time of the quiz to be persisted.
     * @param int $end the time the quiz to be persisted closes.
     * @param int $timelimit the time limit of the quiz to be persisted.
     * @param int $time of flex quiz creation.
     * @return int $quizid id of the quiz which has been persisted.
     */
    private function persist_child($quizname, $start, $end, $timelimit, $time) {
        global $DB;

        $description = self::create_availability_info($start, $end, $time);

        $quizdata = array(
            'course' => $this->flexquiz->course,
            'name' => $quizname,
            'intro' => $description,
            'introformat' => FORMAT_HTML,
            'timeopen' => $start,
            'timeclose' => $end,
            'timelimit' => $timelimit,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 0,
            'preferredbehaviour' => 'deferredfeedback',
            'canredoquestions' => 0,
            'attempts' => 1,
            'attemptonlast' => 0,
            'grademethod' => 1,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'reviewattempt' => '69888',
            'reviewcorrectness' => '4352',
            'reviewmarks' => '4352',
            'reviewspecificfeedback' => '4352',
            'reviewgeneralfeedback' => '4352',
            'reviewrightanswer' => '4352',
            'reviewoverallfeedback' => '4352',
            'questionsperpage' => 1,
            'navmethod' => 'free',
            'shuffleanswers' => 1,
            'sumgrades' => 0,
            'grade' => 0,
            'timecreated' => $time,
            'timemodified' => $time,
            'password' => '',
            'subnet' => '',
            'browsersecurity' => '-',
            'delay1' => 0
        );
        $quizid = $DB->insert_record('quiz', $quizdata);

        return $quizid;
    }

    /**
     * Static function to restrict quiz access to the given user. Creates a group
     * for a specific user/flexquiz combination if such a group does not exist yet.
     *
     * @param string $groupname name of the group to be created.
     * @param int $newmoduleid id of the newly created module to which the restrictions will be added.
     */
    private function add_child_group_and_restrictions($groupname, $newmoduleid) {
        global $DB;

        if ($this->fqsdata->groupid > 0) {
            $gid = $this->fqsdata->groupid;
        } else {
            // If group does not exist, create group.
            $sql = "SELECT fqs.groupid
              FROM {flexquiz_student} fqs
              INNER JOIN {flexquiz} a ON a.id=fqs.flexquiz
              WHERE fqs.student=?
              AND fqs.groupid > 0
              AND a.course=?
            ";
            $params = [$this->studentid, $this->flexquiz->course];
            $gid = $DB->get_field_sql($sql, $params, IGNORE_MULTIPLE);

            if (!$gid) {
                $groupdata = new stdClass();
                $groupdata->courseid = $this->flexquiz->course;
                $groupdata->name = $groupname;

                if ($DB->record_exists('groups', array('name' => $groupname, 'courseid' => $this->flexquiz->course))) {
                    // Disambiguation is necessary if there are two students with the same name.
                    $groupdata->name .= '_' . time();
                }

                $gid = groups_create_group($groupdata);
            }
            $DB->set_field('flexquiz_student', 'groupid', $gid, array('id' => $this->fqsdata->id));
        }

        // Add student as a member if needed.
        if (!groups_is_member($gid, $this->studentid)) {
            groups_add_member($gid, $this->studentid);
        }
        // Restrict child quiz to fqs-item group.
        $restriction = \core_availability\tree::get_root_json([\availability_group\condition::get_json($gid)]);
        $restriction->showc[0] = false;
        $DB->set_field('course_modules', 'availability', json_encode($restriction), ['id' => $newmoduleid]);
    }

    /**
     * Get array of new questions for the next child quiz.
     *
     * @return stdClass[] $result array of question objects to be used in the next quiz
     */
    public function get_new_questions() {
        $newquestions = $this->get_question_candidates();

        // Sort candidates array and choose new questions.
        $scores = array_map('floatval', array_column($newquestions, 'score'));
        $timemodified = array_map('intval', array_column($newquestions, 'timemodified'));
        $ccamodifier = array_column($newquestions, 'ccamodifier');
        array_multisort(
            $ccamodifier, SORT_ASC,
            $scores, SORT_ASC,
            $timemodified, SORT_ASC,
            $newquestions
        );

        $max = $this->flexquiz->maxquestions;
        if (!$max) {
            $max = flexquiz::MAX_QUESTIONS_DEFAULT;
        }
        $min = $this->flexquiz->minquestions;
        if ($max && $min > $max) {
            $min = $max;
        }

        $count = 1;
        $result = array();

        foreach ($newquestions as $newquestion) {
            $last = $newquestion->score;
            $ccareached = boolval($newquestion->ccamodifier >= 1);
            if (!$ccareached || $last < 1.0 || ($min > 0 && $count <= $min)) {
                $entry = new stdClass();
                $entry->id = $newquestion->id;
                $entry->qtype = $newquestion->qtype;
                array_push($result, $entry);
            } else {
                return $result;
            }
            $count++;
            if ($max > 0 && $count > $max) {
                return $result;
            }
        }
        return $result;
    }

    /**
     * Get question candidates for the next child quiz.
     *
     * @return stdClass[] $newQuestions array of question objects to be used in the next quiz
     */
    private function get_question_candidates() {
        global $DB;

        $newquestions = array();

        // Add questions to candidates array.
        foreach (array_column($this->gradedata, 'question') as $question) {
            $data = $this->gradedata[$question];

            $roundupcomplete = $data->roundupcomplete;
            if (!$roundupcomplete) {
                // Create new question candidate object and set defaults.
                $rating = new stdClass();
                $rating->id = $question;
                $rating->score = $data->rating;
                $rating->timemodified = $data->timemodified;
                $rating->qtype = $data->qtype;

                $ccas = $data->ccas_this_cycle;
                $forcereuse = boolval($ccas < $this->get_ccarinfo()->ccar);
                $rating->ccamodifier = $forcereuse ? 0 : 1;
                array_push($newquestions, $rating);
            }
        }
        return $newquestions;
    }

    /**
     * Get the consecutive correct answers required setting. Database field cyclenumber needs to be
     * up-to-date for this to work.
     *
     * @return stdClass $result object containing the ccar info
     */
    public function get_ccarinfo() {
        $lastcycle = false;
        $result = new stdClass();
        $result->isroundupcycle = false;
        $result->ccar = intval($this->flexquiz->ccar);

        if ($this->flexquiz->roundupcycle) {
            $lastcycle = boolval($this->cycles_are_overflowing(intval($this->fqsdata->cyclenumber) + 1));
            $result->isroundupcycle = $lastcycle;
            $result->ccar = $lastcycle ? 1 : intval($this->flexquiz->ccar);
        }
        return $result;
    }

    /**
     * Static function to create availability info string to be shown
     * in the quiz 'intro' field.
     *
     * @param int $start the starting time of the quiz.
     * @param int $end the time the quiz closes.
     * @param int $time of flex quiz creation.
     * @return string $description of the quiz.
     */
    public static function create_availability_info($start, $end, $time) {
        $dateformat = 'D d-m-Y H:i:s';
        if ($time < $start) {
            $alert = '';
            $availability = get_string('availablefrom', 'flexquiz', date($dateformat, $start));
        } else if ($end) {
            if ($time < $end) {
                $alert = get_string('alertactive', 'flexquiz');
                $availability = get_string('closesat', 'flexquiz', date($dateformat, $end));
            } else {
                $alert = '';
                $availability = get_string('quizclosed', 'flexquiz');
            }
        } else {
            $alert = get_string('alertactive', 'flexquiz');
            $availability = get_string('nodeadline', 'flexquiz');
        }

        $description = \html_writer::tag(
            'span',
            $alert . ' ' . \html_writer::tag(
                'span',
                $availability,
                array('class' => 'flexquizTimer')
            ),
            array('class' => 'flexquizInfo')
        );

        return $description;
    }

    /**
     * Static function to add questions to a given quiz.
     *
     * @param int $quizid id of the quiz to which to add the questions.
     * @param stdClass[] $questions array of questions to be added.
     * @return int $count of questions added.
     */
    private static function add_questions_to_child($quizid, $questions) {
        global $DB;

        $positions = array_column($questions, 'position');

        if (!empty($positions)) {
            array_multisort($positions, SORT_ASC, $questions);
        }
        $newquiz = $DB->get_record('quiz', array('id' => $quizid), '*', MUST_EXIST);
        $count = 0;

        foreach ($questions as $question) {
            $count++;
            if ($question->qtype !== 'random') {
                quiz_add_quiz_question($question->id, $newquiz, $count);
            } else {
                self::add_random_question($question->id, $newquiz, $count);
            }
        }

        $DB->insert_record('quiz_sections', array(
            'quizid' => $quizid,
            'firstslot' => 1,
            'heading' => '',
            'shufflequestions' => empty($positions) ? 1 : 0
        ));
        return $count;
    }

    /**
     * Static function to add questions of type 'random' to a given quiz.
     *
     * @param int $questionid id of the question to be added.
     * @param stdClass $quiz object to which the question is to be added.
     * @param int $slotid slot in which the question is to be added.
     */
    private static function add_random_question($questionid, $quiz, $slotid) {
        global $DB;

        $data = $DB->get_record('question', array('id' => $questionid), 'category, questiontext', MUST_EXIST);

        $DB->insert_record('quiz_slots', array(
            'slot' => $slotid,
            'quizid' => $quiz->id,
            'page' => $slotid,
            'questionid' => $questionid,
            'questioncategoryid' => $data->category,
            'includingsubcategories' => $data->questiontext,
            'maxmark' => 1.0
        ));
    }

    /**
     * Creates and persists module info for a child quiz
     *
     * @param int $moduleid id of the quiz module.
     * @param int $quizid id of the given quiz.
     * @param int $sectionid id of the section of which the quiz is part
     * @return int $newModuleId id of the newly created course module
     */
    private function add_child_cm_info($moduleid, $quizid, $sectionid) {
        global $DB;
        $moduleinfo = array(
            'course' => $this->flexquiz->course,
            'module' => $moduleid,
            'instance' => $quizid,
            'section' => $sectionid,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'groupmode' => 1,
            'idnumber' => '',
            'added' => time(),
            'showdescription' => 1
        );
        $newmoduleid = $DB->insert_record('course_modules', $moduleinfo);

        return $newmoduleid;
    }

    /**
     *
     * Fetches new questions from the ai.
     *
     * @param int $cyclenumber the number of the cycle the flex quiz is currently in.
     * @param int $parentquizid of the quiz which provides the question pool.
     * @param stdClass[] $tasks array of question data from the last quiz attempt.
     * @param int $timestamp to be included in the request.
     * @param string $type of the request (e.g. 'initialize', 'continue')
     * @param int[] $taskpool array of questions eligible for the quiz to be created.
     * Must not be null if $quizid does not provide the question pool.
     *
     * @return array of questions returned by the AI.
     */
    public function get_new_tasks(
        $cyclenumber,
        $parentquizid,
        $tasks,
        $timestamp,
        $type,
        $taskpool = null
    ) {
        global $DB;

        // If questionpool is not given, extract the question pool from the parentquiz.
        if (!$taskpool) {
            $taskpool = $DB->get_fieldset_select(
                'quiz_slots',
                'questionid',
                'quizid=:quizid',
                array('quizid' => $parentquizid)
            );
        }

        // Build json object to be sent to the ai api.
        if (!$tasks) {
            $tasks = array();
        }
        $min = intval($this->flexquiz->minquestions);
        $max = intval($this->flexquiz->maxquestions);
        if ($min === 0) {
            $min = null;
        }
        if ($max === 0) {
            $max = null;
        }

        $isroundupcycle = boolval(
            $this->flexquiz->roundupcycle &&
            $this->cycles_are_overflowing(intval($this->fqsdata->cyclenumber) + 1)
        );

        $quizdata = array(array(
            'type' => $type,
            'courseId' => $this->flexquiz->course,
            'poolId' => $parentquizid,
            'userId' => $this->studentid,
            'cycle' => intval($cyclenumber),
            'tasks' => $tasks,
            'timestamp' => $timestamp,
            'taskPool' => array_values($taskpool),
            'limits' => array('min' => $min, 'max' => $max),
            'ccar' => intval($this->flexquiz->ccar),
            'roundupCycle' => $isroundupcycle
        ));

        $data = array('uniqueIdentifier' => 'abcde', 'requests' => $quizdata);
        $jsondata = json_encode($data);

        $fqsettings = get_config('mod_flexquiz');
        $apikey = $fqsettings->aiapikey;
        $url = $fqsettings->aiurl . '/api/v1/danube/get-tasks';

        $options = array(
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_HTTPGET' => 0
        );

        $curl = new \curl();
        $curl->setHeader('Content-Type:application/json');
        $curl->setHeader('Authorization:Bearer/' . $apikey);
        $response = $curl->post($url, $jsondata, $options);
        $errno = $curl->get_errno();
        if ($errno) {
            debugging(get_string('faultyairesponse', 'flexquiz'), DEBUG_DEVELOPER);
            // AI connection failure should only cancel child quiz creation here, so we do not propagate the exception
            // in order to prevent a transaction rollback.
        } else {
            $questiondata = json_decode($response);
            $gradedata = array();

            $questions = array_reduce($questiondata, function($carry, $item) use($DB, &$gradedata) {
                $question = new stdClass();
                $question->id = $item->taskId;
                $question->position = $item->position;
                $question->qtype = $DB->get_field('question', 'qtype', array('id' => $item->taskId), MUST_EXIST);
                $question->grade = $item->grade;
                array_push($gradedata, $question);

                if ($item->useInNextTaskGroup && $item->useInNextTaskGroup !== 'false') {
                    $carry[$item->taskId] = $question;
                };
                return $carry;
            }, array());

            // Grading needs to be done for ALL questions.
            if (!empty($gradedata)) {
                $this->update_ai_grades($gradedata, $timestamp);
            }
            return $questions;
        }
        return array();
    }

    /**
     * Function to stash curl post data for later use
     * in case the ai is unavailable for some reason.
     *
     * @param string $uniqueid id required by the ai to identify the request.
     * @param int $cycle the number of the cycle the data was for.
     * @param int $quizid id of the quiz which had been attempted last.
     * @param int $timestamp to be included in the request.
     * @param stdClass[] $questions array of question data.
     *
     */
    public function stash_postdata(
        $uniqueid,
        $cycle,
        $quizid,
        $timestamp,
        $questions
    ) {
        global $DB;

        $data = array(
            'flexquiz_student_item' => $this->fqsdata->id,
            'minquestions' => $this->flexquiz->minquestions,
            'maxquestions' => $this->flexquiz->maxquestions,
            'courseid' => $this->flexquiz->course,
            'uniqueid' => $uniqueid,
            'cycle' => $cycle,
            'quizid' => $quizid,
            'parentquizid' => $this->flexquiz->parentquiz,
            'studentid' => $this->studentid,
            'timecreated' => $timestamp,
            'timemodified' => $timestamp
        );
        $stashid = $DB->insert_record('flexquiz_stash', $data);
        $questiondata = array();
        foreach ($questions as $question) {
            array_push($questiondata, array(
                'stashid' => $stashid,
                'questionid' => $question['taskId'],
                'score' => $question['score'],
                'qtype' => $question['qtype']
            ));
        }
        $DB->insert_records('flexquiz_stash_questions', $questiondata);
    }

    /**
     * Trigger the cycle transition for the current flexquiz/student item
     * @param int $cycle the new cycle to transition to
     * @param int $time the update was triggered.
     * @param int $instances this cycle value with which to start the cycle
     *
     * @return int $start the start time for the next child quiz
     */
    public function trigger_transition($cycle, $time, $instances = 0) {
        global $DB;

        $this->notify_new_cycle();
        $oldcycle = intval($this->fqsdata->cyclenumber);
        $DB->update_record(
            'flexquiz_student',
            array(
            'id' => $this->fqsdata->id,
            'cyclenumber' => $cycle,
            'instances_this_cycle' => $instances
        )
        );
        $this->fqsdata->cyclenumber = $cycle;
        $this->modify_ratings_new_cycle($oldcycle, $cycle, $time);

        $this->fqsdata->instances_this_cycle = $instances;
        $start = $this->flexquiz->startdate + ($cycle * $this->flexquiz->cycleduration);
        if ($start < $time) {
            $start = $time;
        }

        return $start;
    }

    /**
     * Check if the given cycle exceeds the end date of the flex quiz
     *
     * @param int $cycle number of the cycle for which to do the check.
     *
     * @return bool true if the limits do not allow the given cycle, false else.
     */
    public function cycles_are_overflowing($cycle) {
        return flexquiz::cycles_are_overflowing($this->flexquiz, $cycle);
    }

    /**
     * TODO: implement function
     */
    private function notify_new_cycle() {
        return true;
    }

    /**
     * Modifies ratings in the flexquiz_grades_question table when a cycle transition
     * has happened and returns the updated ratings.
     *
     * @param int $oldcycle the cycle in which the student completed his last quiz
     * @param int $cycle the new cycle
     * @param int $time the update was triggered.
     *
     * @return stdClass[] $result array of new ratings for an flexquiz/student item.
     */
    private function modify_ratings_new_cycle($oldcycle, $cycle, $time) {
        global $DB;
        $questionperformance = $DB->get_records_select(
            'flexquiz_grades_question',
            'flexquiz_student_item = ?',
            [$this->fqsdata->id],
            'question ASC',
            'id, question, attempts, rating, ccas_this_cycle, roundupcomplete'
        );

        $ccarinfo = $this->get_ccarinfo();
        $ccar = $ccarinfo->ccar;
        $isroundupcycle = $ccarinfo->isroundupcycle;
        $result = array();
        foreach ($questionperformance as $record) {
            // Apply penalty to rating an ccas_this_cycle.
            $multiplier = $cycle - $oldcycle;
            $ccas = $record->ccas_this_cycle;
            $record->ccas_this_cycle = !$isroundupcycle && $ccar && $ccar > 0 ? $ccas - $multiplier : 0;
            if ($record->ccas_this_cycle > $ccar - $multiplier) {
                $record->ccas_this_cycle = $ccar - $multiplier;
            }
            if ($record->ccas_this_cycle < 0) {
                $record->ccas_this_cycle = 0;
            }
            if ($isroundupcycle) {
                $record->rating = 0.0; // Roundupcycles are handled differently.
            } else {
                $record->rating = $record->rating - ($multiplier * self::NEW_CYCLE_PENALTY);
                if ($record->rating < 0.0) {
                    $record->rating = 0.0;
                }
            }

            // Only reset fraction in non-AI mode. grading in AI mode is done by the AI.
            if (!$this->flexquiz->usesai) {
                $record->fraction = 0;
            }

            $record->timemodified = $time;
            $DB->update_record('flexquiz_grades_question', $record);
            $result[$record->question] = $record;

            $this->update_grade_data($result);
        }
    }

    /**
     * Update the gradedata object for this fqsitem object.
     *
     * @param stdClass[] $questiondata object containing data with which to update grade data.
     */
    public function update_grade_data($questiondata) {
        if (!empty($questiondata)) {
            foreach (array_keys($questiondata) as $item) {
                $vars = get_object_vars($questiondata[$item]);
                foreach (array_keys($vars) as $key) {
                    $this->gradedata[$item]->$key = $vars[$key];
                }
            }
        }
    }

    /**
     * Update the grade for a specific flexquiz/student/question combination.
     *
     * @param stdClass[] $questiondata containing the question attempt data from the last quiz.
     * @param int $time this update was triggered.
     */
    public function update_question_grades($questiondata, $time) {
        global $DB;

        $result = array();

        foreach ($questiondata as $question) {
            $record = new stdClass();
            $roundupcomplete = $this->get_ccarinfo()->isroundupcycle ? 1 : 0;
            $record->roundupcomplete = $roundupcomplete;
            $record->timemodified = $time;
            $record->rating = $question['score'];
            $record->fraction = $question['score'];

            $oldrecord = $DB->get_record('flexquiz_grades_question', array(
                'flexquiz_student_item' => $this->fqsdata->id,
                'question' => $question['taskId']
            ));

            if ($oldrecord) {
                  $record->attempts = $oldrecord->attempts;
                  $record->ccas_this_cycle = $question['score'] < 1.0 ? 0 : intval($oldrecord->ccas_this_cycle) + 1;
                  $updatedata = array(
                    'id' => $oldrecord->id,
                    'attempts' => $record->attempts + 1,
                    'fraction' => $record->fraction,
                    'rating' => $record->rating,
                    'ccas_this_cycle' => $record->ccas_this_cycle,
                    'timemodified' => $time,
                    'roundupcomplete' => $roundupcomplete
                  );
                  $DB->update_record('flexquiz_grades_question', $updatedata);
            } else {
                $record->attempts = 1;
                $record->ccas_this_cycle = $question['score'] < 1.0 ? 0 : 1;
                $insertdata = array(
                    'flexquiz_student_item' => $this->fqsdata->id,
                    'question' => $question['taskId'],
                    'attempts' => $record->attempts,
                    'fraction' => $record->fraction,
                    'rating' => $record->rating,
                    'ccas_this_cycle' => $record->ccas_this_cycle,
                    'timecreated' => $time,
                    'timemodified' => $time,
                    'roundupcomplete' => $roundupcomplete
                );
                $DB->insert_record('flexquiz_grades_question', $insertdata);
            }
            $result[$question['taskId']] = $record;
        }
        $this->update_grade_data($result);
    }

    /**
     * Update the grade for a specific flex quiz/student item.
     *
     * @param int $time this update was triggered.
     * @param bool $last true if the last quiz for this flex quiz/student
     * item has been completed.
     *
     */
    public function update_sum_grade($time, $last = false) {
        global $DB;

        // Prepare variables and fetch old grade data.
        $grades = array();
        $grade = array(
            'userid' => $this->studentid,
            'datesubmitted' => $time,
            'dategraded' => $time
        );

        $sql = 'SELECT sum(p.fraction) AS fractionsum, sum(p.ccas_this_cycle) AS ccasum
            FROM {flexquiz_grades_question} p
            WHERE p.flexquiz_student_item=?';
        $params = [$this->fqsdata->id];
        $questiongrades = $DB->get_record_sql($sql, $params);

        // Calculate new grade.
        $count = count($this->gradedata);
        $sum = $questiongrades->fractionsum;
        $ccafactor = 1;

        if (!$this->flexquiz->usesai) {
            $ccarinfo = $this->get_ccarinfo();
            $ccar = $ccarinfo->ccar;
            if ($ccar > 0) {
                $sum = $questiongrades->ccasum;
                $ccafactor = $ccar;
            }
        }

        // If the fq has ended, flag fq as graded and set child quizzes to inactive.
        if ($last) {
            $DB->set_field('flexquiz_student', 'graded', 1, array('id' => $this->fqsdata->id));
            $DB->set_field('flexquiz_children', 'active', 0, array('flexquiz_student_item' => $this->fqsdata->id));
        }

        if ($count) {
            $grade['rawgrade'] = (self::GRADE_MULTIPLIER * (($sum / $count) / $ccafactor));
        } else {
            $grade['rawgrade'] = 0;
        }
        if ($grade['rawgrade'] > self::GRADE_MULTIPLIER) {
            $grade['rawgrade'] = self::GRADE_MULTIPLIER;
        }

        // Update grade record in the database.
        $grades[$this->studentid] = $grade;
        $itemdetails = array('itemname' => $this->flexquiz->name, 'grademax' => 10.0);
        grade_update(
            'mod/flexquiz',
            $this->flexquiz->course,
            'mod',
            'flexquiz',
            $this->flexquiz->id,
            0,
            $grades,
            $itemdetails
        );

    }

    /**
     * Update the the overall stats for a specific flexquiz/student combination.
     *
     * @param int $cycle of which the child quiz attempted was part
     * @param int $count number of new questions attempts submitted
     * @param float $sumscores sum of scores over all new question attempts
     * @param int $time this update was triggered.
     */
    public function update_stats($cycle, $count, $sumscores, $time) {
        global $DB;

        $stats = $DB->get_record('flexquiz_stats', array('student' => $this->studentid));
        $lastcycle = boolval($this->cycles_are_overflowing(intval($cycle) + 1));
        $roundupcycle = boolval($lastcycle && $this->flexquiz->roundupcycle > 0);

        if ($stats) {
            $attempts = intval($stats->attempts) + $count;
            $fraction = ((floatval($stats->fraction) * intval($stats->attempts)) + $sumscores) / $attempts;
            $oldra = $stats->roundupattempts ? intval($stats->roundupattempts) : 0;
            $roundupfraction = $stats->roundupfraction ? floatval($stats->roundupfraction) : 0.0;
            if ($roundupcycle) {
                $roundupattempts = $oldra + $count;
                $roundupfraction = (($roundupfraction * $oldra) + $sumscores) / $roundupattempts;
            } else {
                $roundupattempts = $oldra;
            }

            $DB->update_record(
                'flexquiz_stats',
                array(
                    'id' => $stats->id,
                    'fraction' => $fraction,
                    'attempts' => $attempts,
                    'roundupattempts' => $roundupattempts,
                    'roundupfraction' => $roundupfraction,
                    'timemodified' => $time
                )
            );
        } else {
            $DB->insert_record('flexquiz_stats', array(
                'student' => $this->studentid,
                'fraction' => $sumscores / $count,
                'attempts' => $count,
                'roundupattempts' => $roundupcycle ? $count : 0,
                'roundupfraction' => $roundupcycle ? $sumscores / $count : 0.0,
                'timecreated' => $time,
                'timemodified' => $time
            ));
        }
    }

    /**
     * Queries the ai for questions to use in the next quiz and returns them as an array.
     *
     * @param int $uniqueid id of the question attempt to which the data belongs.
     * @param int $cycle to which the new quiz should belong.
     * @param int $quizid id of the quiz from which the attempt data is taken.
     * @param stdClass[] $questions array of questions used in quiz attempt from which the data is taken.
     * @param int $time timestamp of the quiz attempt.
     * @param string $type of the request (e.g. 'initialize', 'continue')
     * @param int[] $questionpool the pool of questions eligible.
     * @param bool $stashonfail true if the data should be stashed in the flexquiz_stash table in case
     * the request fails, false else.
     *
     * @return stdClass[] array of question objects
     */
    public function query_questions_from_ai(
        $uniqueid,
        $cycle,
        $quizid,
        $questions,
        $time,
        $type,
        $questionpool = [],
        $stashonfail = false
    ) {
        global $DB;
        $fqsettings = get_config('mod_flexquiz');
        $url = $fqsettings->aiurl . '/api/v1/danube/get-tasks';

        if (empty($questionpool)) {
            $questionpool = $DB->get_fieldset_select(
                'quiz_slots',
                'questionid',
                'quizid=:quizid',
                array('quizid' => $this->flexquiz->parentquiz)
            );
        }
        $questions = $this->get_new_tasks(
            $cycle,
            $this->flexquiz->parentquiz,
            $questions,
            $time,
            $type,
            $questionpool
        );

        if (!$questions || empty($questions)) {
            if ($stashonfail) {
                $this->stash_postdata(
                    $uniqueid,
                    $cycle,
                    $quizid,
                    $time,
                    $questions
                );
            }
        }
        return $questions;
    }

    /**
     * Starts the child creation process
     *
     * @param int $time the child creation was triggered
     * @param array|null $stashedrecords to send to the ai - if an ai is used and if there are any
     */
    public function trigger_child_creation($time, $stashedrecords = null) {
        global $DB;

        // If usesai flag is set, have ai determine the questions for the quiz to be created.
        if ($this->flexquiz->usesai) {
            $questions = array();
            $type = intval($this->fqsdata->instances) === 0 ? 'initialize' : 'continue';
            // Use stashed records if they exist.
            if ($stashedrecords && !empty($stashedrecords)) {
                foreach ($stashedrecords as $record) {
                    $uniqueid = $record->uniqueid;
                    $quizid = $record->quizid;
                    $stashedquestions = $DB->get_records('flexquiz_stash_questions', array('stashid' => $record->id));
                    foreach ($stashedquestions as $question) {
                        array_push($questions, array(
                            'taskId' => $question->questionid,
                            'score' => floatval($question->score),
                            'qtype' => $question->qtype
                        ));
                    }
                    $DB->delete_records('flexquiz_stash', array('id' => $record->id));
                    $DB->delete_records('flexquiz_stash_questions', array('stashid' => $record->id));
                }
            } else {
                // No stashed records.
                $uniqueid = 'DummyId';
                $quizid = $this->flexquiz->parentquiz;
            }
            // Try fetching new questions from the ai.
            $newquestions = $this->query_questions_from_ai(
                $uniqueid,
                $this->fqsdata->cyclenumber,
                $quizid,
                $questions,
                $time,
                $type,
                [],
                true
            );
        } else {
            // If no ai is used, have the plugin determine the questions for the next quiz.
            $newquestions = $this->get_new_questions();
        }

        // Create a new child quiz.
        if (!empty($newquestions)) {
            $section = $DB->get_record(
                'course_sections',
                array('id' => $this->flexquiz->sectionid),
                '*'
            );
            if (!$DB->record_exists('course_sections', array('id' => $section->id))) {
                $flexquiz = $DB->get_record('flexquiz', array('id' => $this->flexquiz->id));
                $section = \mod_flexquiz\childcreation\flexquiz::create_child_quiz_section($flexquiz);
            }
            $this->create_child($newquestions, $time, $section);
        } else {
            // Empty responses from the ai should not trigger quiz creation.
            mtrace(get_string('emptyquiz', 'flexquiz'));
        }
    }

    /**
     * Update the grade for a specific flexquiz/student/question combination
     * using ai data.
     *
     * @param stdClass[] $questions containing the question grades sent by the ai.
     * @param int $time this update was triggered.
     */
    public function update_ai_grades($questions, $time) {
        global $DB;

        foreach ($questions as $question) {
            $grade = $question->grade;

            $oldrecord = $DB->get_record(
                'flexquiz_grades_question',
                array(
                    'flexquiz_student_item' => $this->fqsdata->id,
                    'question' => $question->id
                )
            );

            if ($oldrecord) {
                  $updatedata = array(
                    'id' => $oldrecord->id,
                    'fraction' => $grade,
                    'timemodified' => $time
                  );
                  $DB->update_record('flexquiz_grades_question', $updatedata);
            } else {
                $newdata = array(
                    'flexquiz_student_item' => $this->fqsdata->id,
                    'question' => $question->id,
                    'attempts' => 0,
                    'rating' => 0.0,
                    'fraction' => $grade,
                    'ccas_this_cycle' => 0,
                    'timecreated' => $time,
                    'timemodified' => $time,
                    'roundupcomplete' => 0
                );
                $DB->insert_record('flexquiz_grades_question', $newdata);
            }
            $this->gradedata[$question->id]->timemodified = $time;
            $this->gradedata[$question->id]->fraction = $grade;
        }
    }
}
