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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Code to be executed after the plugin's database scheme has been installed is defined here.
 *
 * @package     quiz_gradingcustomchatgpt
 * @category    upgrade
 * @copyright   2024 zal <zal@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Custom code to be run on installing the plugin.
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Post-install script for quiz_gradingcustomchatgpt plugin
 */
function xmldb_quiz_gradingcustomchatgpt_install() {
    global $DB;

    // Get the database manager
    $dbman = $DB->get_manager();

    // Create a new record for the quiz report
    $record = new stdClass();
    $record->name         = 'gradingcustomchatgpt'; // Name of the report
    $record->displayorder = 6000; // Display order (adjust as needed)
    $record->capability   = 'mod/quiz:grade'; // Capability required to view this report

    // Insert the new record into the quiz_reports table
    $DB->insert_record('quiz_reports', $record);
}
