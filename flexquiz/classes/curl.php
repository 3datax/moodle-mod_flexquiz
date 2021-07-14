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
 * Curl helper functions of the flexquiz module.
 *
 * @package mod_flexquiz
 * @copyright danube.ai
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_flexquiz\curl;

defined('MOODLE_INTERNAL') || die();

class curl_request_helper
{

  /**
   * Requests an array of questions from the given url
   * @param string $url to send the request to
   * @param string[] $options curl options array
   * @param string[] $params additional url params
   * @throws moodle_exception
   * 
   * @return object[] $response array of question ids
   */
  public static function request_questions(string $url, $options = null, array $params = null)
  {
    $ch = curl_init();

    if ($params) {
      $urlparams = array_key_first($params) . '=' . array_values($params)[0];
      array_shift($params);
      foreach ($params as $param => $value) {
        $urlparams = $urlparams . '&' . $param . '=' . $value;
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, $urlparams);
    }

    if ($options) {
      curl_setopt_array($ch, $options);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $output = curl_exec($ch);
    $responsecode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    $response = json_decode($output);
    if (!is_array($response)) {
      // if response data is not valid, display a debug message and throw an exception
      curl_close($ch);
      debugging(get_string('faultyairesponse', 'flexquiz'), DEBUG_DEVELOPER);
      throw new \moodle_exception('noaierror', 'flexquiz');
    }

    curl_close($ch);
    return $response;
  }
}
