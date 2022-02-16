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
 * Children cleanup task of the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_flexquiz\task;

require_once($CFG->dirroot . '/mod/flexquiz/classes/child_creation.php');

use moodle_exception;

class clean_up_children extends \core\task\scheduled_task
{

    public function get_name()
    {
        return get_string('cleanupchildren', 'flexquiz');
    }

    public function execute()
    {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/lib/gradelib.php');

        // find fqs items with active children
        $sql = "SELECT ac.id,
                       ac.flexquiz_student_item AS fqsitem,
                       a.enddate
                FROM {flexquiz_children} AS ac
                INNER JOIN {flexquiz_student} AS fqs ON ac.flexquiz_student_item=fqs.id
                INNER JOIN {flexquiz} AS a ON fqs.flexquiz=a.id
                WHERE ac.active=?
                ORDER BY ac.id ASC";
        $params = array(1);

        $children = $DB->get_records_sql($sql, $params);
        $activechildren = array_column($children, 'fqsitem');

        $time = time();

        // get all flex quiz student items
        $sql = "SELECT fqs.id,
                       fqs.cyclenumber,
                       fqs.student,
                       fqs.instances,
                       fqs.flexquiz,
                       a.startdate,
                       a.enddate,
                       a.cycleduration,
                       a.usesai,
                       a.parentquiz,
                       a.sectionid
                FROM {flexquiz_student} AS fqs
                INNER JOIN {flexquiz} AS a ON a.id=fqs.flexquiz
                WHERE fqs.graded=:graded
        ";
        $params = array('graded' => '0');
        $fqs = $DB->get_records_sql($sql, $params);

        // create a new child quiz if necessary
        foreach ($fqs as $item) {
            // check if end reached or cycle transition necessary
            $cyclenumber = intval($item->cyclenumber);
            $cycleinfo = \mod_flexquiz\child_creation\flex_quiz::get_cycle_info($item, $time);
            $currentcycle = intval($cycleinfo->cyclenumber);
            $hasended = $cycleinfo->hasended;
            $isnewcycle = boolval($currentcycle > $cyclenumber);

            $fqs = \mod_flexquiz\child_creation\flexquiz_student_item::create($item->id);

            $transaction = $DB->start_delegated_transaction();

            // only continue if there is enough time left before the end date
            if (!$hasended) {
                $hasactivechildren = boolval(in_array($item->id, $activechildren));
                // trigger cycle transition if necessary
                if($isnewcycle) {
                    $fqs->trigger_transition($currentcycle, $time);
                }

                // only continue if the fqs item does not have active children
                if (!$hasactivechildren) {
                    if ($item->usesai) {
                        $stashedrecords = $DB->get_records('flexquiz_stash', array('flexquiz_student_item' => $item->id));
                    }
                    // only continue if a new child quiz is due
                    if ($isnewcycle || (isset($stashedrecords) && !empty($stashedrecords)) || intval($item->instances) === 0) {
                        try {
                            $fqs->trigger_child_creation($time, $stashedrecords);
                        } catch (moodle_exception $e) {
                            mtrace($e->errorcode);
                            try {
                                $transaction->rollback($e);
                            } catch (moodle_exception $e) {
                                // Catch the re-thrown exception.
                            }
                            continue;
                        }
                    }
                } else if ($isnewcycle && $item->usesai) {
                    $fqs->query_questions_from_ai(
                        'DummyId',
                        $currentcycle,
                        $item->parentquiz,
                        [],
                        $time,
                        'continue'
                    );
                }
            } else if (!$samecycle) {
                $fqs->trigger_transition($currentcycle, $time, 1);
                if ($item->usesai) {
                    $fqs->query_questions_from_ai(
                        'DummyId',
                        $currentcycle,
                        $item->parentquiz,
                        [],
                        $time,
                        'continue'
                    );
                }
            }

            $fqs->update_sum_grade($time, $hasended);
            $transaction->allow_commit();
        }
    }
}
