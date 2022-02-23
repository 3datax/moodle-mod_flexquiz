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
 * Admin settings for the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    if ($ADMIN->fulltree) {
        global $DB;
        // Add options.
        $settings->add(new admin_setting_configcheckbox('mod_flexquiz/useai',
            get_string('useai', 'flexquiz'), get_string('useaidescription', 'flexquiz'), 0));
        $settings->add(new admin_setting_configtext('mod_flexquiz/aiurl',
            get_string('aiurlfield', 'flexquiz'), get_string('aiurldescription', 'flexquiz'), 'http://', PARAM_URL));
        $settings->add(new admin_setting_configtext('mod_flexquiz/aiapikey',
            get_string('aiapikeyfield', 'flexquiz'), get_string('aiapikeydescription', 'flexquiz'), ''));

        $version = get_config('mod_flexquiz', 'version');
        // Add statistics.
        if ($version >= 2020120904) {
            $sql = 'SELECT 0,
                    sum(COALESCE(fraction, 0)) AS fractionsum,
                    count(*) AS cnt
                    FROM {flexquiz_stats}';
            $result = $DB->get_records_sql($sql, array())[0];

            $sql = 'SELECT 0,
                    sum(COALESCE(roundupfraction, 0)) AS roundupsum,
                    count(*) AS cnt
                    FROM {flexquiz_stats}
                    WHERE roundupattempts > 0';
            $roundupresult = $DB->get_records_sql($sql, [])[0];

            if (intval($result->cnt) !== 0) {
                $a = new stdClass();
                $a->numberofstudents = $result->cnt;
                $settings->add(
                    new admin_setting_description(
                        'mod_flexquiz/statistics',
                        get_string('statistics', 'flexquiz'),
                        get_string('numberofstudents', 'flexquiz', $a)
                    )
                );
                $a->average = 100 * (floatval($result->fractionsum) / intval($result->cnt));
                $settings->add(
                    new admin_setting_description(
                        'mod_flexquiz/statisticsline2',
                        '',
                        get_string('statsvalue', 'flexquiz', $a)
                    )
                );
                if (intval($roundupresult->cnt) !== 0) {
                    $a->roundupavg = 100 * (floatval($roundupresult->roundupsum) / intval($roundupresult->cnt));
                    $settings->add(
                        new admin_setting_description(
                            'mod_flexquiz/statisticsline3',
                            '',
                            get_string('roundupstatsvalue', 'flexquiz', $a)
                        )
                    );
                }
            } else {
                $settings->add(new admin_setting_description('mod_flexquiz/statistics', get_string('statistics', 'flexquiz'),
                    get_string('nostats', 'flexquiz')));
            }
        }
    }
}
