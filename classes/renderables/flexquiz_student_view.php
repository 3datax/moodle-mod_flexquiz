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
 * Student renderable object for the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_flexquiz\renderables;

 /**
  * The student view object for flex quizzes
  *
  */
class flexquiz_student_view implements \renderable {

    /** @var int the id of the flex quiz. */
    public $id;
    /** @var int the course module id. */
    public $cmid;
    /** @var string the row from the flexquiz table. */
    public $flexquiz;
    /** @var stdClass|null object containing the quiz cm id and quiz name. */
    public $quizdata;
    /** @var boolean true if the number of quizzes completed has reached the maximum. */
    public $maxcountreached;
    /** @var int|null the number of the current cycle. */
    public $currentcycle;
    /** @var int|null the start date/time for the next cycle if there is one. */
    public $nextcyclestart;
    /** @var string the tab currently active. */
    public $currenttab;
    /** @var stdClass[] the questiongrades of the flexquiz. */
    public $questiongrades;
    /** @var float the current average grade for this student. */
    public $currentstanding;
    /** @var int consecutive correct answers required setting. */
    public $ccar;

    /**
     * Constructor for renderable.
     *
     * @param object $data for the flex quiz
     * @param string $currenttab current tab
     */
    public function __construct($data, $currenttab) {
        global $DB, $USER;

        $this->currenttab = $currenttab;
        $this->id = $data->cm->id;
        $flexquiz = $data->flexquiz;
        $this->flexquiz = $flexquiz;
        $this->nextcylestart = null;
        $this->quizdata = null;

        if (!$DB->record_exists('flexquiz_student', array('flexquiz' => $flexquiz->id, 'student' => $USER->id))) {
            $sql = "SELECT cm.id
                    FROM {course_modules} cm
                    INNER JOIN {modules} m ON m.id=cm.module
                    WHERE cm.instance=? AND m.name='flexquiz'
            ";
            $params = [$flexquiz->id];
            $flexquiz->cmid = $DB->get_record_sql($sql, $params)->id;

            $fq = \mod_flexquiz\childcreation\flexquiz::create($flexquiz);

            $transaction = $DB->start_delegated_transaction();
            $fq->create_initial_data_for_student($USER->id, $flexquiz->sectionid);
            $transaction->allow_commit();
        }

        // Get fqsitem from database.
        $fqsdata = $DB->get_record('flexquiz_student', array('flexquiz' => $flexquiz->id, 'student' => $USER->id));

        $this->maxcountreached = boolval($flexquiz->maxquizcount > 0 &&
                        $fqsdata->instances_this_cycle >= $flexquiz->maxquizcount);

        $fqsitem = \mod_flexquiz\childcreation\flexquiz_student_item::create($fqsdata);
        $time = time();
        $cyclenumber = $fqsdata->cyclenumber;
        $currentcycle = $cyclenumber;

        if ($flexquiz->cycleduration > 0) {
            $transaction = $DB->start_delegated_transaction();
            $cycleinfo = \mod_flexquiz\childcreation\flexquiz::get_cycle_info($flexquiz, $time);
            $currentcycle = intval($cycleinfo->cyclenumber);
            $hasended = $cycleinfo->hasended;

            if ($transition = boolval(intval($currentcycle) > intval($cyclenumber)) && !$fqsdata->graded) {
                $select = array('flexquiz_student_item' => $fqsdata->id, 'active' => 1);
                $fqsitem->trigger_transition($currentcycle, $time);
                if (!$hasended) {
                    if ($DB->record_exists('flexquiz_children', $select)) {
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
                    } else {
                        $fqsitem->trigger_child_creation($time);
                    }
                } else if ($flexquiz->usesai) {
                    $fqsitem->query_questions_from_ai(
                        'DummyId',
                        $currentcycle,
                        $flexquiz->parentquiz,
                        [],
                        $time,
                        'continue'
                    );
                }
            }
            if ($transition || (!$fqsdata->graded && $hasended)) {
                $fqsitem->update_sum_grade($time, $hasended);
            }
            $transaction->allow_commit();
        }

        $this->questiongrades = $fqsitem->get_grades();
        $this->ccar = intval($fqsitem->get_ccarinfo()->ccar);
        $this->currentstanding = $fqsitem->get_sum_grade();
        $this->ccasleft = array_reduce($this->questiongrades, [$this, 'get_ccas_left'], 0);

        // Query child quiz data.
        $sql = "SELECT cm.id, q.name
            FROM {flexquiz_children} ac
            INNER JOIN {quiz} q ON q.id=ac.quizid
            INNER JOIN {course_modules} cm ON cm.instance=ac.quizid
            INNER JOIN {modules} m ON m.id=cm.module
            WHERE ac.active=?
            AND ac.flexquiz_student_item=?
            AND m.name=?";
        $params = ['1', $fqsdata->id, 'quiz'];

        $quizdata = $DB->get_records_sql($sql, $params);
        if ($quizdata && !empty($quizdata)) {
            $this->quizdata = array_values($quizdata)[0];
        }

        // Compute start of next cycle.
        if ($flexquiz->cycleduration > 0) {
            if (!$fqsitem->cycles_are_overflowing($currentcycle)) {
                $this->currentcycle = $currentcycle + 1;
            }
            if (!$fqsitem->cycles_are_overflowing($currentcycle + 1)) {
                $this->nextcyclestart = intval($flexquiz->startdate) + intval(($currentcycle + 1) * $flexquiz->cycleduration);
            }
        }
    }

    /**
     * Reduce callback function for calculation of the consecutive correct answers left
     * to meet the requirement
     *
     * @param int $last to which to add
     * @param stdClass $item containing the the ccas_this_cycle value
     * @return float new $ccasleft value
     */
    private function get_ccas_left($last, $item) {
        $ccasleft = $this->ccar - $item->ccas_this_cycle;
        if ($ccasleft < 0) {
            $ccasleft = 0;
        }
        return $last + $ccasleft;
    }
}
