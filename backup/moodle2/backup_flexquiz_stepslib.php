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
 * Backup steps file of the flexquiz module
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the backup structure for the flexquiz activity
 */
class backup_flexquiz_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        // Are we including userinfo?
        $userinfo = $this->get_setting_value('userinfo');

        // Element definitions.
        $flexquiz = new backup_nested_element('flexquiz', array('id'), array(
            'name', 'parentquiz', 'intro', 'introformat', 'customtimelimit',
            'startdate', 'enddate', 'usesai', 'minquestions', 'maxquestions',
            'timecreated', 'timemodified', 'maxquizcount', 'cycleduration',
            'pauseduration', 'ccar', 'roundupcycle', 'sectionid'));

        $fqsitems = new backup_nested_element('students');
        $fqsitem = new backup_nested_element('student', array('id'), array(
            'student', 'instances', 'cyclenumber', 'instances_this_cycle',
            'groupid', 'graded'));

        $gradesparent = new backup_nested_element('gradesparent');
        $grades = new backup_nested_element('grades', array('id'), array(
            'question', 'attempts', 'rating', 'fraction', 'ccas_this_cycle',
            'timecreated', 'timemodified', 'roundupcomplete'));

        $childrenparent = new backup_nested_element('childrenparent');
        $children = new backup_nested_element('children', array('id'), array(
            'quizid', 'active'));

        // Build the tree.
        $flexquiz->add_child($fqsitems);
        $fqsitems->add_child($fqsitem);

        $fqsitem->add_child($gradesparent);
        $gradesparent->add_child($grades);

        $fqsitem->add_child($childrenparent);
        $childrenparent->add_child($children);

        // Define sources.
        $flexquiz->set_source_table(
            'flexquiz',
            array('id' => backup::VAR_ACTIVITYID),
            'id ASC'
        );

        if ($userinfo) {
            $fqsitem->set_source_table(
            'flexquiz_student',
            array('flexquiz' => backup::VAR_PARENTID),
            'id ASC'
            );

            $grades->set_source_table(
                'flexquiz_grades_question',
                array('flexquiz_student_item' => backup::VAR_PARENTID),
                'id ASC'
            );

            $children->set_source_table(
                'flexquiz_children',
                array('flexquiz_student_item' => backup::VAR_PARENTID),
                'id ASC',
            );
        }

        // Define id annotations.
        $flexquiz->annotate_ids('quiz', 'parentquiz');
        $fqsitem->annotate_ids('user', 'student');
        $fqsitem->annotate_ids('group', 'groupid');
        $grades->annotate_ids('question', 'question');
        $children->annotate_ids('quiz', 'quizid');

        // Define file annotations.
        $flexquiz->annotate_files('mod_flexquiz', 'intro', null);

        // Return the root element wrapped into the standard activity structure.
        return $this->prepare_activity_structure($flexquiz);
    }
}
