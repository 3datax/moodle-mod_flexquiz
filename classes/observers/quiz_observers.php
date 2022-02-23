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
 * Quiz observers of the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_flexquiz\observers;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/questionlib.php');

class quiz_observers {


    /**
     * Static function which submits event data the ai, if usesai flag is set in the
     * specific context, and triggers the creation of a new child quiz.
     *
     * @param \mod_quiz\event\attempt_submitted $event event triggered by quiz attempt completion
     */
    public static function handle_quiz_completion(\mod_quiz\event\attempt_submitted $event) {
        global $DB;

        // Process event data.
        $eventdata = $event->get_data();
        $eventid = $eventdata['objectid'];
        $eventrecord = $event->get_record_snapshot('quiz_attempts', $eventid);
        $quizid = $eventrecord->quiz;

        $sql = "SELECT qas.id, quea.questionid
            FROM {quiz} q
            INNER JOIN {quiz_attempts} qa ON qa.quiz=q.id
            INNER JOIN {question_attempts} quea ON quea.questionusageid=qa.uniqueid
            INNER JOIN {question_attempt_steps} qas ON qas.questionattemptid=quea.id
            WHERE q.id=:quizid
            AND qas.state='complete'
            ORDER BY qas.id DESC
    ";
        $params = array('quizid' => $quizid);

        // Get completion order.
        $attempts = $DB->get_records_sql($sql, $params);
        $sortedids = array_reverse(array_unique(array_column($attempts, 'questionid')));

        // Get child quiz section.
        $cmid = $eventdata['contextinstanceid'];
        $sql = "SELECT cs.* FROM {course_sections} cs
            INNER JOIN {course_modules} cm ON cm.section=cs.id
            WHERE cm.id=?";
        $params = [$cmid];
        $section = $DB->get_record_sql($sql, $params, MUST_EXIST);

        if (\mod_flexquiz\childcreation\flexquiz::is_child_quiz($quizid)) {
            $transaction = $DB->start_delegated_transaction();
            // Get relevant data from the database.
            $childquiz = $DB->get_record('flexquiz_children', array('quizid' => $quizid));
            $studentid = $eventrecord->userid;
            $fqsitem = $DB->get_record(
                'flexquiz_student',
                array('id' => $childquiz->flexquiz_student_item),
                '*',
                MUST_EXIST
            );
            $flexquiz = $DB->get_record(
                'flexquiz',
                array('id' => $fqsitem->flexquiz),
                '*',
                MUST_EXIST
            );
            $uniqueid = $eventrecord->uniqueid;

            // Fetch question pool ids for this flex quiz.
            $questionpool = $DB->get_fieldset_select(
                'quiz_slots',
                'questionid',
                'quizid=:quizid',
                array('quizid' => $flexquiz->parentquiz)
            );

            $questionrecords = array();
            if ($questionpool && !empty($questionpool)) {
                // Fetch question records from the most recent quiz attempt.
                $likegraded = $DB->sql_like('s.state', ':graded');
                $likegaveup = $DB->sql_like('s.state', ':gaveup');
                list($insql, $inparams) = $DB->get_in_or_equal($questionpool, SQL_PARAMS_NAMED);

                $sql = "SELECT s.id,
                               a.questionid,
                               qs.questionid AS metaid,
                               q.qtype,
                               COALESCE(s.fraction, 0) AS fraction
                        FROM {question_attempt_steps} s
                        INNER JOIN {question_attempts} a ON s.questionattemptid=a.id
                        INNER JOIN {quiz_slots} qs ON qs.slot=a.slot
                        INNER JOIN {question} q ON q.id=qs.questionid
                        WHERE a.questionusageid=:uniqueid
                        AND qs.quizid=:quizid
                        AND s.userid=:studentid
                        AND ($likegraded OR $likegaveup)
                        AND qs.questionid $insql
                ";
                $params = array(
                    'uniqueid' => $uniqueid,
                    'quizid' => $quizid,
                    'studentid' => $studentid,
                    'graded' => '%graded%',
                    'gaveup' => '%gaveup%'
                );
                $params += $inparams;
                $questionrecords = $DB->get_records_sql($sql, $params);
            }

            $fqs = \mod_flexquiz\childcreation\flexquiz_student_item::create($fqsitem);
            $time = time();
            $questions = array();

            // Process attempt data.
            $count = count($questionrecords);
            $sumscores = 0;
            foreach ($questionrecords as $record) {
                $sumscores += floatval($record->fraction);
                $position = array_search($record->metaid, $sortedids);
                array_push($questions, array(
                    'taskId' => $record->metaid,
                    'score' => floatval($record->fraction),
                    'qtype' => $record->qtype,
                    'position' => $position + 1
                ));
            }

            $newquestions = array();

            // Check if the flexquiz end date allows another child quiz.
            $cycleinfo = \mod_flexquiz\childcreation\flexquiz::get_cycle_info($flexquiz, $time);
            $currentcycle = intval($cycleinfo->cyclenumber);
            $hasended = $cycleinfo->hasended;
            $samecycle = boolval(intval($fqsitem->cyclenumber) === $currentcycle);

            // Create or update statistics record if no ai is used.
            if (!$flexquiz->usesai) {
                $fqs->update_stats($currentcycle, $count, $sumscores, $time);
            }

            $last = false;
            $updated = false;
            $instances = intval($fqsitem->instances_this_cycle) + 1;

            $DB->update_record('flexquiz_student', array(
                'id' => $fqsitem->id,
                'instances_this_cycle' => $instances
            ));

            if (!$hasended) {
                  $start = null;
                  $maxcountreached = false;

                  // Trigger cycle transition if necessary.
                if ($samecycle) {
                    $maxcountreached = boolval($flexquiz->maxquizcount > 0 &&
                    $instances >= $flexquiz->maxquizcount);
                } else {
                    $start = $fqs->trigger_transition($currentcycle, $time, 1);
                }

                // If usesai flag is set, have ai determine the questions for the quiz to be created.
                if ($flexquiz->usesai) {
                        $newquestions = $fqs->query_questions_from_ai(
                            $uniqueid,
                            $currentcycle,
                            $quizid,
                            $questions,
                            $time,
                            'continue',
                            $questionpool,
                            true
                        );
                } else {
                    // Grading and question selection are done differently in non-AI mode.
                    $fqs->update_question_grades($questions, $time);
                    $newquestions = $fqs->get_new_questions();
                }
                if ($maxcountreached) {
                    $lastcycle = $fqs->cycles_are_overflowing($currentcycle + 1);
                    if ($lastcycle) {
                        // Set $last to true to mark the flex quiz as completed for this student.
                        $last = true;
                        $newquestions = [];
                    } else {
                        $start = intval($flexquiz->startdate) + (($currentcycle + 1) * intval($flexquiz->cycleduration));
                    }
                }
                $updated = true;
            } else {
                // Set $last to true to mark the flex quiz as completed for this student.
                $last = true;
                // Trigger transition to the last cycle, which must be $currentcycle in this case.
                if (!$samecycle) {
                    $fqs->trigger_transition($currentcycle, $time, 1);
                }
            }

            // Create the new quiz, if there are eligible questions.
            if (!empty($newquestions)) {
                $section = $DB->get_record(
                    'course_sections',
                    array('id' => $flexquiz->sectionid),
                    '*',
                    MUST_EXIST
                );
                if (!$DB->record_exists('course_sections', array('id' => $section->id))) {
                    $section = \mod_flexquiz\childcreation\flexquiz::create_child_quiz_section($flexquiz);
                }
                $fqs->create_child($newquestions, $time, $section, $start);
            } else if ($fqs->get_ccarinfo()->isroundupcycle) {
                $last = true;
            }

            // If no new quiz creation has been triggered, update grades now.
            if (!$updated) {
                if ($flexquiz->usesai) {
                    $fqs->query_questions_from_ai(
                        $uniqueid,
                        $currentcycle,
                        $quizid,
                        $questions,
                        $time,
                        'continue',
                        $questionpool,
                        true
                    );
                } else {
                    $fqs->update_question_grades($questions, $time);
                }
            }

            // Set completed quiz inactive.
            $DB->set_field('flexquiz_children', 'active', 0, array('quizid' => $quizid));

            // Update description of the old quiz.
            $description = \mod_flexquiz\childcreation\flexquiz_student_item::create_availability_info($time - 1, $time - 1, $time);
            $DB->update_record('quiz', array('id' => $quizid, 'intro' => $description));

            // Update flexquiz grade.
            $fqs->update_sum_grade($time, $last);

            $transaction->allow_commit();
        }
    }
}
