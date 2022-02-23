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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/flexquiz/lib.php');

/**
 * Settings form for the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class mod_flexquiz_mod_form extends moodleform_mod {

    /**
     * Builds form.
     */
    public function definition() {
        global $DB, $COURSE;
        $mform =& $this->_form;

        $mform->addElement('text', 'name', get_string('flexquizname', 'flexquiz'), array('size' => '64'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $sql = 'SELECT q.id, q.name
                FROM {quiz} q
                WHERE q.id NOT IN (SELECT quizid FROM {flexquiz_children})
                AND q.course = ?
                ORDER BY q.name ASC';

        $params = [$COURSE->id];
        $quizzes = $DB->get_records_sql($sql, $params);
        $quizlist = array();
        foreach ($quizzes as $quiz) {
            $quizlist[$quiz->id] = $quiz->name;
        }

        $mform->addElement(
            'select',
            'parentquiz',
            get_string('parentquiz', 'flexquiz'),
            $quizlist, array('noselectionstring' => '')
        );
        $mform->setDefault('parentquiz', '');
        $mform->setType('parentquiz', PARAM_RAW);
        $mform->addRule('parentquiz', null, 'required', null, 'client');
        $mform->addHelpButton('parentquiz', 'parentquiz', 'flexquiz');

        $this->standard_intro_elements();

        // General options.
        $mform->addElement('header', 'generalheader', get_string('generalheader', 'flexquiz'));

        $mform->addElement('text', 'minquestions', get_string('minquestions', 'flexquiz'));
        $mform->addRule('minquestions', get_string('numbersfieldinvalid', 'flexquiz'), 'numeric', null, 'client');
        $mform->setType('minquestions', PARAM_INT);
        $mform->addHelpButton('minquestions', 'minquestions', 'flexquiz');

        $mform->addElement('text', 'maxquestions', get_string('maxquestions', 'flexquiz'));
        $mform->addRule('maxquestions', get_string('numbersfieldinvalid', 'flexquiz'), 'numeric', null, 'client');
        $mform->setType('maxquestions', PARAM_INT);

        $mform->addElement('date_time_selector', 'startdate', get_string('startdate', 'flexquiz'));
        $mform->addHelpButton('startdate', 'startdate', 'flexquiz');

        $mform->addElement('date_time_selector', 'enddate', get_string('enddate', 'flexquiz'), array('optional' => true));
        $mform->addHelpButton('enddate', 'enddate', 'flexquiz');

        $mform->addElement(
            'duration',
            'customtimelimit',
            get_string('customtimelimit', 'flexquiz'),
            ['units' => [MINSECS, HOURSECS],
            'optional' => true]
        );
        $mform->addHelpButton('customtimelimit', 'customtimelimit', 'flexquiz');

        // Cycles.
        $mform->addElement('header', 'cyclesheader', get_string('cyclesheader', 'flexquiz'));

        $mform->addElement('text', 'maxquizcount', get_string('maxquizcount', 'flexquiz'), array('optional' => true));
        $mform->addRule('maxquizcount', get_string('numbersfieldinvalid', 'flexquiz'), 'numeric', null, 'client');
        $mform->setType('maxquizcount', PARAM_INT);

        $mform->addElement(
            'duration',
            'cycleduration',
            get_string('cycleduration', 'flexquiz'),
            array('units' => [HOURSECS, DAYSECS, WEEKSECS])
        );

        $mform->addElement(
            'duration',
            'pauseduration',
            get_string('pauseduration', 'flexquiz'),
            ['units' => [MINSECS, HOURSECS, DAYSECS, WEEKSECS],
            'optional' => true]
        );
        $mform->addHelpButton('pauseduration', 'pauseduration', 'flexquiz');

        $mform->addElement('text', 'ccar', get_string('ccar', 'flexquiz'), array('optional' => true));
        $mform->addRule('ccar', get_string('numbersfieldinvalid', 'flexquiz'), 'numeric', null, 'client');
        $mform->setType('ccar', PARAM_INT);
        $mform->addHelpButton('ccar', 'ccar', 'flexquiz');

        $mform->addElement('selectyesno', 'roundupcycle', get_string('roundupcycle', 'flexquiz'));
        $mform->addHelpButton('roundupcycle', 'roundupcycle', 'flexquiz');

        // Ai options.
        $mform->addElement('header', 'aiheader', get_string('aiheader', 'flexquiz'));

        $mform->addElement('selectyesno', 'usesai', get_string('usesai', 'flexquiz'));
        $mform->addHelpButton('usesai', 'usesai', 'flexquiz');

        // Standard elements.
        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }

    /**
     * Backend validation.
     *
     * @param array $data data to be validated
     * @param array $files files to be validated
     */
    public function validation($data, $files) {
        global $DB;
        $settings = get_config('mod_flexquiz');
        $errors = parent::validation($data, $files);

        $sql = 'SELECT q.id, q.qtype
        FROM {quiz_slots} qs
        INNER JOIN {question} q ON q.id=qs.questionid
        WHERE qs.quizid=?';

        $params = [$data['parentquiz']];
        $questions = (array) $DB->get_records_sql($sql, $params);

        if ($data['maxquestions'] && $data['maxquizcount'] &&
            ($data['maxquestions'] * $data['maxquizcount'] < count($questions))) {
            // Each question should appear at least once per cycle.
            $errors['maxquestions'] = get_string('toofewquestions', 'flexquiz');
            $errors['maxquizcount'] = get_string('toofewquestions', 'flexquiz');
        }

        if ($data['usesai']) {
            $aierrors = '';
            if ($data['minquestions']) {
                $errors['minquestions'] = get_string('minquestionsnotallowed', 'flexquiz');
            }
            if (!$settings->useai) {
                $aierrors .= ' ' . get_string('ainotset', 'flexquiz');
            }
            if ($settings->aiurl === 'http://' || trim($settings->aiurl) === '') {
                $aierrors .= ' ' . get_string('ainourl', 'flexquiz');
            }
            if ($settings->aiapikey === '') {
                $aierrors .= ' ' . get_string('ainoapikey', 'flexquiz');
            }
            if ($aierrors !== '') {
                $errors['usesai'] = get_string('aierror', 'flexquiz', $aierrors);
            }
        }
        return $errors;
    }
}
