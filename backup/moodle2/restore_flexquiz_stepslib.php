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
 * Restore steps file of the flexquiz module
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore a flexquiz activity
 */
class restore_flexquiz_activity_structure_step extends restore_activity_structure_step {

    /**
     * Defines backup structure.
     *
     * @return stdClass structure
     */
    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('flexquiz', '/activity/flexquiz');

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'flexquiz_student',
                '/activity/flexquiz/students/student'
            );
            $paths[] = new restore_path_element(
                'flexquiz_grades_question',
                '/activity/flexquiz/students/student/gradesparent/grades'
            );
            $paths[] = new restore_path_element(
                'flexquiz_children',
                '/activity/flexquiz/students/student/childrenparent/children'
            );
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Processes a flexquiz.
     * 
     * @param $data flexquiz data.
     */
    protected function process_flexquiz($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->course = $this->get_courseid();
        $data->sectionid = 0;

        $data->timeopen = $this->apply_date_offset($data->startdate);
        $data->timeclose = $data->enddate ? $this->apply_date_offset($data->enddate) : 0;

        $newitemid = $DB->insert_record('flexquiz', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Processes a flexquiz student.
     * 
     * @param $data flexquiz student data.
     */
    protected function process_flexquiz_student($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->flexquiz = $this->get_new_parentid('flexquiz');

        $studentmapping = $this->get_mapping('user', $data->student);
        $data->student = $studentmapping ? $studentmapping->newitemid : 0;

        $groupmapping = $this->get_mapping('group', $data->groupid);
        $data->groupid = $groupmapping ? $groupmapping->newitemid : 0;

        if ($data->student) {
            $newitemid = $DB->insert_record('flexquiz_student', $data);
            $this->set_mapping('flexquiz_student', $oldid, $newitemid);
        }
    }

    /**
     * Processes flexquiz question grades.
     * 
     * @param $data flexquiz question grades data.
     */
    protected function process_flexquiz_grades_question($data) {
        global $DB;

        $data = (object)$data;

        $data->flexquiz_student_item = $this->get_new_parentid('flexquiz_student');

        $questionmapping = $this->get_mapping('question', $data->question);
        $data->question = $questionmapping ? $questionmapping->newitemid : 0;

        if ($data->question && $data->flexquiz_student_item) {
            $newitemid = $DB->insert_record('flexquiz_grades_question', $data);
        }
    }

    /**
     * Processes flexquiz children.
     * 
     * @param $data flexquiz children data.
     */
    protected function process_flexquiz_children($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->flexquiz_student_item = $this->get_new_parentid('flexquiz_student');

        if ($data->flexquiz_student_item) {
            // Children which are lost here will be replaced in the after_restore() function.
            if (!$DB->record_exists('flexquiz_children', array('quizid' => $data->quizid))) {
                $newitemid = $DB->insert_record('flexquiz_children', $data);
                $this->set_mapping('flexquiz_children', $oldid, $newitemid);
            }
        }
    }

    /**
     * Run after execution.
     */
    protected function after_execute() {
        $this->add_related_files('mod_flexquiz', 'intro', null);
    }
}
