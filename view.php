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
 * View page of the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/flexquiz/lib.php');
require_once($CFG->dirroot . '/mod/flexquiz/classes/renderables/flexquiz_default_view.php');
require_once($CFG->dirroot . '/mod/flexquiz/classes/renderables/flexquiz_teacher_view.php');
require_once($CFG->dirroot . '/mod/flexquiz/classes/renderables/flexquiz_student_view.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID.
$tab = optional_param('tab', 'general', PARAM_ALPHAEXT);

list ($course, $cm) = get_course_and_cm_from_cmid($id, 'flexquiz');

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

require_capability('mod/flexquiz:view', $context, $USER);

$url = new moodle_url('/mod/flexquiz/view.php', array('id' => $id));
$PAGE->set_url($url);

$data = new stdClass();
$data->cm = $cm;
$data->flexquiz = $DB->get_record('flexquiz', array('id' => $cm->instance), '*', MUST_EXIST);

$widget = new \mod_flexquiz\renderables\flexquiz_default_view();

if (has_capability('moodle/course:manageactivities', $context, $USER)) {
    $widget = new \mod_flexquiz\renderables\flexquiz_teacher_view($data, $tab, $context);
} else {
    $capjoin = get_enrolled_with_capabilities_join($context, '', 'mod/quiz:attempt');
    $sql = "SELECT u.id
            FROM {user} u
            $capjoin->joins
            WHERE $capjoin->wheres
            AND u.id=:userid";
    $params = $capjoin->params;
    $params += array('userid' => $USER->id);
    $isstudent = $DB->record_exists_sql($sql, $params);

    if ($isstudent) {
        $widget = new \mod_flexquiz\renderables\flexquiz_student_view($data, $tab);
    }
}

flexquiz_view($data->flexquiz, $course, $cm, $context);

$output = $PAGE->get_renderer('mod_flexquiz');
echo $output->header();
$output->render($widget);
echo $output->footer();
