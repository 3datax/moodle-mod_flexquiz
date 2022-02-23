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
 * Backup task file of the flexquiz module
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/flexquiz/backup/moodle2/backup_flexquiz_stepslib.php');

/**
 * Flexquiz backup task
 */
class backup_flexquiz_activity_task extends backup_activity_task {

    /**
     * Define flexquiz backup settings.
     */
    protected function define_my_settings() {
        // No settings yet.
    }

    /**
     * Define backup steps for this activity.
     */
    protected function define_my_steps() {
        $this->add_step(
            new backup_flexquiz_activity_structure_step('flexquiz_structure', 'flexquiz.xml')
        );
    }

    /**
     * Encoding rules for flexquiz links.
     *
     * @param string $content content to encode.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, "/");

        // Link to the flexquiz index page.
        $search = "/(" . $base . "\/mod\/flexquiz\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@FLEXQUIZINDEX*$2@$', $content);

        // Link to flexquiz view page.
        $search = "/(" . $base . "\/mod\/flexquiz\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@FLEXQUIZVIEWBYID*$2@$', $content);

        return $content;
    }
}
