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
 * Event handlers for the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\mod_flexquiz\quiz_observers::handle_quiz_completion'
    ),
    array(
        'eventname' => '\core\event\course_viewed',
        'callback' => '\mod_flexquiz\course_observers::delete_completed_quizzes'
    ),
    array(
        'eventname' => '\core\event\role_assigned',
        'callback' => '\mod_flexquiz\course_observers::handle_user_enrolment'
    ),
    array(
        'eventname' => '\core\event\role_unassigned',
        'callback' => '\mod_flexquiz\course_observers::handle_user_unenrolment'
    )
);