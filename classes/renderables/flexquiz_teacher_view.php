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
 * Teacher renderable object for the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_flexquiz\renderables;

/**
 * The teacher view object for flex quizzes
 *
 */
class flexquiz_teacher_view implements \renderable {

    /** @var int the id of the flex quiz. */
    public $id;
    /** @var int the id of the course module. */
    public $cmid;
    /** @var string the tab currently active. */
    public $currenttab;
    /** @var stdClass[] array of gradedata for all students. */
    public $gradedata;
    /** @var stdClass[] records of the students participating in this FQ. */
    public $studentrecords;
    /** @var int|null the number of the current cycle. */
    public $currentcycle;
    /** @var int|null the start date/time for the next cycle if there is one. */
    public $nextcyclestart;
    /** @var int consecutive correct answers required setting. */
    public $ccar;

    /**
     * Constructor for renderable.
     *
     * @param object $data for the flex quiz
     * @param string $currenttab current tab
     * @param array $context list of context path
     */
    public function __construct($data, $currenttab, $context) {
        global $DB;

        $this->currenttab = $currenttab;
        $this->id = $data->cm->id;
        $flexquiz = $data->flexquiz;
        $this->flexquiz = $flexquiz;

        $moduleid = $DB->get_field('modules', 'id', array('name' => 'flexquiz'), MUST_EXIST);
        $this->cmid = $DB->get_field(
            'course_modules',
            'id',
        array(
            'course' => $flexquiz->course,
            'module' => $moduleid,
            'instance' => $flexquiz->id
        ),
        MUST_EXIST
        );

        // Get students data.
        $capjoin = get_enrolled_with_capabilities_join($context, '', 'mod/quiz:attempt');
        $students = $DB->get_records_sql("SELECT u.id FROM {user} AS u $capjoin->joins WHERE $capjoin->wheres", $capjoin->params);

        $records = array();
        if ($students && !empty($students)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_column($students, 'id'), SQL_PARAMS_QM);
            $sql = "SELECT fqs.id,
                    fqs.graded,
                    u.id AS userid,
                    u.firstname,
                    u.lastname,
                    fqs.cyclenumber
              FROM {user} u
              LEFT JOIN {flexquiz_student} fqs ON fqs.student=u.id
              WHERE u.id $insql
              AND fqs.flexquiz=?
              ORDER BY u.lastname ASC, u.firstname ASC
            ";

            $params = $inparams;
            array_push($params, $flexquiz->id);
            $records = $DB->get_records_sql($sql, $params);
        }

        // Get question data for students.
        $studentrecords = array();
        $time = time();
        foreach ($records as $record) {
            $fqsitem = \mod_flexquiz\childcreation\flexquiz_student_item::create($record->id);
            $cyclenumber = $record->cyclenumber;
            $currentcycle = $cyclenumber;
            $select = array('flexquiz_student_item' => $record->id, 'active' => 1);
            $hasactivechild = $DB->record_exists('flexquiz_children', $select);
            if ($flexquiz->cycleduration > 0) {
                $transaction = $DB->start_delegated_transaction();
                $cycleinfo = \mod_flexquiz\childcreation\flexquiz::get_cycle_info($flexquiz, $time);
                $currentcycle = intval($cycleinfo->cyclenumber);

                $hasstarted = boolval($currentcycle >= 0);
                $hasended = $cycleinfo->hasended;
                if ($transition = boolval(intval($currentcycle) > intval($cyclenumber)) && !$record->graded) {
                    $fqsitem->trigger_transition($currentcycle, $time);
                }
                if (!$hasended) {
                    if ($hasactivechild) {
                        if ($flexquiz->usesai) {
                            $fqsitem->query_questions_from_ai(
                                'DummyId',
                                $currentcycle,
                                $flexquiz->parentquiz,
                                [],
                                $time,
                                'continue'
                            );
                        }
                    } else if ($hasstarted) {
                        $fqsitem->trigger_child_creation($time);
                    }
                } else if ($hasstarted && $flexquiz->usesai) {
                    $fqsitem->query_questions_from_ai(
                        'DummyId',
                        $currentcycle,
                        $flexquiz->parentquiz,
                        [],
                        $time,
                        'continue'
                    );
                }
                if ($transition || (!$record->graded && $hasended)) {
                    $fqsitem->update_sum_grade($time, $hasended);
                }
                $transaction->allow_commit();
            }
            if (!$this->ccar) {
                $this->ccar = $fqsitem->get_ccarinfo()->ccar;
            }
            $questiongrades = $fqsitem->get_grades();
            $attempts = $DB->get_record(
                'flexquiz_student',
                array('id' => $record->id),
                'COALESCE(instances, 0) AS attemptstotal, COALESCE(instances_this_cycle, 0) AS attemptscycle'
            );
            $record->attemptstotal = $attempts->attemptstotal;
            $record->attemptscycle = $attempts->attemptscycle;
            if ($hasactivechild) {
                $record->attemptstotal -= 1;
                $record->attemptscycle -= 1;
                if ($record->attemptscycle < 0) {
                    $record->attemptscycle = 0;
                }
            }
            $record->questiongrades = $questiongrades;
            $record->percentage = $fqsitem->get_sum_grade();
            array_push($studentrecords, $record);
        }

        $this->studentrecords = $studentrecords;
        if ($currentcycle >= 0) {
            // Compute start of next cycle.
            if ($flexquiz->cycleduration > 0) {
                if (!$fqsitem->cycles_are_overflowing($currentcycle)) {
                    $this->currentcycle = $currentcycle + 1;
                }
                if (!$fqsitem->cycles_are_overflowing($currentcycle + 1)) {
                    $this->nextcyclestart = intval($flexquiz->startdate) + intval(($cyclenumber + 1) * $flexquiz->cycleduration);
                }
            }
        }
    }
}
