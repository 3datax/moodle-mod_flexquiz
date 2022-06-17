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
 * Database updates for the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

/**
 * Upgrades database.
 *
 * @param stdClass $oldversion old plugin version
 * @return bool true if successful
 */
function xmldb_flexquiz_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Add db upgrades here.

    if ($oldversion < 2022060800) {

        // Define table flexquiz_cycle to be created.
        $table = new xmldb_table('flexquiz_cycle');

        // Adding fields to table flexquiz_cycle.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('flexquiz', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cycle_number', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grade_module', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table flexquiz_cycle.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('flexquiz', XMLDB_KEY_FOREIGN, ['flexquiz'], 'flexquiz', ['id']);

        // Adding indexes to table flexquiz_cycle.
        $table->add_index('flexquiz_cycle', XMLDB_INDEX_UNIQUE, ['flexquiz', 'cycle_number']);

        // Conditionally launch create table for flexquiz_cycle.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Flexquiz savepoint reached.
        upgrade_mod_savepoint(true, 2022060800, 'flexquiz');
    }

    return true;
}
