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
 * Renderers for the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/tablelib.php');

class mod_flexquiz_renderer extends plugin_renderer_base {

    /**
     * Teacher view renderer for the flexquiz module
     *
     * @param stdClass $data object to render
     */
    protected function render_flexquiz_teacher_view(object $data) {
        global $CFG;

        // Process data.
        $flexquiz = $data->flexquiz;
        $dateformat = get_string('dateformat', 'flexquiz');
        $start = $flexquiz->startdate;
        $end = $flexquiz->enddate;
        $courseid = $flexquiz->course;
        $currenttab = $data->currenttab;

        $startdate = date($dateformat, intval($start));
        $enddate = date($dateformat, intval($end));
        // Prepare ccs for centered elements.
        $centerclass = array('class' => 'centered');

        // Add heading.
        $out = $this->output->heading(html_writer::tag('h1', $flexquiz->name, array('class' => 'header')));

        // Add tabs.
        if (!empty($currenttab)) {
            ob_start();
            include($CFG->dirroot.'/mod/flexquiz/classes/view/tabs.php');
            $out .= ob_get_contents();
            ob_end_clean();
        }

        // Add content.
        switch($currenttab) {
            case 'general':
                // Add start and end dates.
                $out .= $this->output->container(
                    html_writer::tag('div', get_string('startedat', 'flexquiz', $startdate), $centerclass)
                );

                if ($end) {
                    $out .= $this->output->container(
                        html_writer::tag('div', get_string('endsat', 'flexquiz', $enddate), $centerclass)
                    );
                }
                if ($data->flexquiz->cycleduration) {
                    if ($data->currentcycle) {
                        $out .= html_writer::tag(
                            'div',
                            get_string('currentcycle', 'flexquiz', $data->currentcycle),
                            $centerclass
                        );
                    }
                    if ($data->nextcyclestart) {
                        $out .= html_writer::tag(
                            'div',
                            get_string('nextcyclestart', 'flexquiz', date($dateformat, intval($data->nextcyclestart))),
                            $centerclass
                        );
                    }
                }
                // Add back button.
                $url = new moodle_url('/course/view.php', array('id' => $courseid));
                $backbutton = $this->single_button(
                    $url,
                    get_string('backtocourse', 'flexquiz'),
                    'get',
                    array('class' => 'continuebutton')
                );
                $out .= $this->box($backbutton);

                // Output everything.
                echo $this->output->container($out, 'teacher view');

                break;
            case 'performance':
                // Output the above.
                echo $this->output->container($out, 'teacher view');

                // Add table.
                // Prepare table contents.
                $studentdata = array();
                $ccar = $data->ccar;
                foreach ($data->studentrecords as $item) {
                    $tabledata = new stdClass();
                    $tabledata->id = $item->id;
                    $tabledata->name = $item->lastname . ' ' . $item->firstname;
                    $tabledata->attemptstotal = $item->attemptstotal;
                    $tabledata->attemptscycle = $item->attemptscycle;
                    $tabledata->percentage = round($item->percentage, 2);
                    $tabledata->percentagecolor = $this->percentage_rgbacode($tabledata->percentage);

                    // Prepare questions tables contents.
                    $questiondata = array();
                    $count = 1;
                    foreach ($item->questiongrades as $grade) {
                        $subtabledata = new stdClass();
                        $subtabledata->id = $grade->question;
                        $subtabledata->qtype = $grade->qtype;
                        $subtabledata->questionname = $grade->name;
                        $subtabledata->questionnum = $count;
                        $subtabledata->percentage = round(($grade->fraction * 100), 2);
                        $subtabledata->standing = $subtabledata->percentage;

                        $includecca = boolval(!$flexquiz->usesai && $ccar > 0);
                        if ($includecca) {
                            $subtabledata->correctattempts = $grade->ccas_this_cycle ? $grade->ccas_this_cycle : 0;
                            $subtabledata->standing = round(($subtabledata->correctattempts / $ccar) * 100, 2);
                        }
                        $subtabledata->percentagecolor = $this->percentage_rgbacode($subtabledata->standing);
                        // Add question data to questions table.
                        array_push($questiondata, $subtabledata);
                        $count++;
                    }
                    // Add student data to students table.
                    $tabledata->questiondata = $questiondata;
                    array_push($studentdata, $tabledata);
                }

                // Print table.
                $this->print_students_table($studentdata, $ccar, $includecca);

                break;
            default:
                break;
        }
    }

    /**
     * Student view renderer for the flexquiz module
     *
     * @param stdClass $data object to render
     */
    protected function render_flexquiz_student_view(object $data) {
        global $CFG;

        // Process data.
        $currenttab = $data->currenttab;
        $ccar = $data->ccar;

        // Prepare ccs for centered elements.
        $centerclass = array('class' => 'centered');

        // Add heading.
        $out = $this->output->heading(html_writer::tag('h1', $data->flexquiz->name, array('class' => 'header')));

        // Add tabs.
        if (!empty($currenttab)) {
            ob_start();
            include($CFG->dirroot.'/mod/flexquiz/classes/view/tabs.php');
            $out .= ob_get_contents();
            ob_end_clean();
        }

        // Add content.
        switch($currenttab) {
            case 'general':
                $out .= $this->output->box_start();

                // Add current average.
                $standing = $data->currentstanding;
                $color = $this->percentage_rgbacode($standing);
                $out .= html_writer::tag(
                    'div',
                    html_writer::tag(
                        'span',
                        get_string('currentstanding', 'flexquiz',
                            html_writer::tag(
                                'span',
                                strval(round($standing, 2)) . '%',
                                array('style' => "color: $color; font-weight: bold", 'font-weight' => 'bold')
                            )
                        )
                    ),
                    $centerclass
                );

                if ($ccar > 0 && ($data->quizdata || $data->nextcyclestart)) {
                    $ccasleft = get_string('noccasleft', 'flexquiz');
                    if ($data->maxcountreached) {
                        $ccasleft = get_string('maxcountreached', 'flexquiz');
                    } else if ($data->ccasleft > 0 ) {
                        if ($data->flexquiz->usesai) {
                            if ($data->ccasleft > 0) {
                                $ccasleft = get_string('approximately', 'flexquiz', strval($data->ccasleft));
                            } else {
                                $ccasleft = get_string('fewccasleft', 'flexquiz', strval($data->ccasleft));
                            }
                        } else {
                            $ccasleft = get_string('ccasleft', 'flexquiz', strval($data->ccasleft));
                        }
                    }

                    // Add attempts this cycle left info.
                    $out .= html_writer::tag(
                        'div',
                        html_writer::tag('span', $ccasleft),
                        $centerclass
                    );
                }
                // Add currently active quiz or information about the next quiz.
                if ($data->quizdata) {
                    $out .= html_writer::tag(
                        'div',
                        get_string('currentlyactivequiz', 'flexquiz', html_writer::link(
                            new moodle_url('/mod/quiz/view.php',
                            array('id' => $data->quizdata->id)),
                            $data->quizdata->name
                        )),
                        $centerclass
                    );
                } else {
                    $out .= html_writer::tag('div', get_string('noactivequizzes', 'flexquiz'), $centerclass);
                }

                if ($data->flexquiz->cycleduration) {
                    if ($data->currentcycle) {
                        $out .= html_writer::tag(
                            'div',
                            get_string('currentcycle', 'flexquiz', $data->currentcycle),
                            $centerclass
                        );
                    }
                    if ($data->nextcyclestart) {
                        $dateformat = get_string('dateformat', 'flexquiz');
                        $out .= html_writer::tag(
                            'div',
                            get_string('nextcyclestart', 'flexquiz', date($dateformat, intval($data->nextcyclestart))),
                            $centerclass
                        );
                    } else {
                        $out .= html_writer::tag('div', get_string('lastcycle', 'flexquiz'), $centerclass);
                    }
                }

                if (!$data->quizdata && !$data->nextcyclestart) {
                    $out .= html_writer::tag('div', get_string('activitycompleted', 'flexquiz'), $centerclass);
                }
                $out .= $this->output->box_end();

                // Output everything.
                echo $this->output->container($out, 'student view');
                break;
            case 'performance':
                // Output the above.
                echo $this->output->container($out, 'student view');

                // Prepare table contents.
                $questiondata = array();
                $count = 1;
                foreach ($data->questiongrades as $item) {
                    $tabledata = new stdClass();
                    $tabledata->id = $item->question;
                    $tabledata->qtype = $item->qtype;
                    $tabledata->questionname = $item->name;
                    $tabledata->questionnum = $count;
                    $tabledata->percentage = round($item->fraction * 100, 2);
                    $tabledata->standing = $tabledata->percentage;

                    $includecca = boolval(!$data->flexquiz->usesai && $ccar > 0);
                    if ($includecca) {
                        $tabledata->correctattempts = $item->ccas_this_cycle ? $item->ccas_this_cycle : 0;
                        $tabledata->standing = round(($tabledata->correctattempts / $ccar) * 100, 2);
                    }
                    $tabledata->percentagecolor = $this->percentage_rgbacode($tabledata->standing);
                    array_push($questiondata, $tabledata);
                    $count++;
                }

                // Print table.
                $this->print_questions_table($questiondata, $ccar, $includecca);

                break;
            default:
                break;
        }
    }

    /**
     * Default view renderer for the flexquiz module.
     *
     * @param stdClass $flexQuiz object to render.
     */
    protected function render_flexquiz_default_view(object $flexquiz) {
        $centerclass = array('class' => 'centered');
        $out = $this->output->container(html_writer::tag('div', get_string('noaccess', 'flexquiz'), $centerclass));

        echo $this->output->container($out, 'default view');
    }

    /**
     * Returns a rgb color code for a number between 0 and 100 (inclusive).
     *
     * @param float $number given
     * @return string $rgba
     */
    private function percentage_rgbacode(float $number, float $opacity = 1.0) {
        if ($number < 0 || $number > 100) {
            $rgba = "rgba(0, 0, 0, $opacity)";
            return $rgba;
        }

        $bluevalue = 0;
        if ($number <= 50) {
            $greenvalue = 0;
            $redvalue = 200;
        } else if ($number <= 80 ) {
            $greenvalue = 50 + intval(($number - 50) / 3) * 5;
            $redvalue = 130;
        } else if ($number < 100) {
            $greenvalue = 130;
            $redvalue = 50 + intval((20 - ($number - 80))) * 5;
        } else {
            $greenvalue = 160;
            $redvalue = 0;
        }
        $rgba = "rgba($redvalue, $greenvalue, $bluevalue, $opacity)";

        return $rgba;
    }

    /**
     * Prints the questions overview table.
     *
     * @param stdClass[] $questiondata data to fill the table with.
     * @param int $ccar consecutive correct answers required setting from the flexquiz table.
     * @param bool $includecca true if cca info shall be included in the questions table.
     */
    private function print_questions_table(array $questiondata, int $ccar, bool $includecca) {
        // Prepare table structure.
        $columns = array('question', 'percentage');
        $headers = array(get_string('headerquestion', 'flexquiz'), get_string('headerpercentage', 'flexquiz'));
        if ($includecca) {
            array_push($columns, 'attempts');
            array_push($headers, get_string('headerquestionattempts', 'flexquiz'));
        }
        array_push($columns, 'status');
        array_push($headers, get_string('headerstatus', 'flexquiz'));

        // Create table.
        $table = new flexible_table('flexquiz_student_questions');
        $table->define_baseurl($this->page->url);
        $table->define_columns($columns);
        $table->define_headers($headers);
        $table->set_attribute('id', "fqsquestions");
        $table->setup();
        // Add table cell styling.
        $table->column_style('percentage', 'text-align', 'center');
        $table->column_style('attempts', 'text-align', 'center');
        // Add empty line.
        $table->column_style('question', 'color', '#000000');
        $emptyline = $includecca ? ['', '', '', ''] : ['', '', ''];
        $table->add_data($emptyline);
        // Add data.
        foreach ($questiondata as $item) {
            // Get question name.
            if ($item->qtype !== 'random') {
                $questionname = $item->questionname;
            } else {
                $cleanedname = substr(trim($item->questionname), strpos($item->questionname, '(') + 1, -1);
                $questionname = get_string('randomquestion', 'flexquiz') . ' ' . $cleanedname;
            }

            // Create row.
            $row = [$item->questionnum . ' ' . $this->output->pix_icon('help', $questionname), $item->standing . '%'];
            $table->column_style('percentage', 'color', $item->percentagecolor);
            $status = '';
            if ($includecca) {
                $color = $item->correctattempts < $ccar ? 'rgba(160, 0, 0, 1.0)' : 'rgba(0, 0, 0, 1.0)';
                $table->column_style('attempts', 'color', $color);
                array_push($row, html_writer::tag('div', html_writer::tag('span', $item->correctattempts . '/' . $ccar)));
                if ($item->correctattempts >= $ccar) {
                    $status = $this->output->pix_icon('i/checked', get_string('requirementsmet', 'flexquiz'));
                }
            } else if (floatval($item->percentage) >= 100) {
                $status = $this->output->pix_icon('i/checked', get_string('requirementsmet', 'flexquiz'));
            }
            // Add row to table.
            array_push($row, $status);
            $table->add_data($row);
        }

        $table->finish_output();
    }

     /**
      * Prints the questions overview table.
      *
      * @param stdClass[] $questiondata data to fill the table with
      * @param int $ccar consecutive correct answers required setting from the flexquiz table
      * @param bool $includecca true if cca info shall be included in the questions tables
      */
    private function print_students_table(array $studentdata, int $ccar, bool $includecca) {
        // Prepare table structure.
        $columns = array('name', 'attemptstotal', 'attemptscycle', 'percentage', 'questions');
        $headers = array(
            get_string('headername', 'flexquiz'),
            get_string('headertotalattempts', 'flexquiz'),
            get_string('headercycleattempts', 'flexquiz'),
            get_string('headeraverage', 'flexquiz'),
            get_string('headerquestions', 'flexquiz')
        );

        // Create table.
        $table = new flexible_table('flexquiz_teacher_students');
        $table->define_baseurl($this->page->url);
        $table->define_columns($columns);
        $table->define_headers($headers);
        $table->set_attribute('id', 'fqteacherstudents');
        $table->setup();
        // Add table cell styling.
        $table->column_style('name', 'min-width', '120px');
        $table->column_style('attemptstotal', 'text-align', 'center');
        $table->column_style('attemptscycle', 'text-align', 'center');
        $table->column_style('percentage', 'text-align', 'center');
        // Add empty line.
        $table->column_style('percentage', 'color', '#000000');
        $table->add_data(['', '', '', '', '', '']);
        // Add data.

        foreach ($studentdata as $student) {
            // Prepare questions subtable.
            $questiondata = $student->questiondata;
            $questions = print_collapsible_region_start('', "questions_$student->id", 'details', '', true, true);
            ob_start();
            $this->print_questions_table($questiondata, $ccar, $includecca);
            $questions .= ob_get_contents();
            ob_end_clean();
            $studentrow = [
                $student->name,
                $student->attemptstotal,
                $student->attemptscycle,
                $student->percentage . '%',
                $questions
            ];
            $table->column_style('percentage', 'color', $student->percentagecolor);
            $table->add_data($studentrow);
        }
        $table->finish_output();
    }
}
