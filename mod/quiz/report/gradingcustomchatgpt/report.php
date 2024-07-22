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

defined('MOODLE_INTERNAL') || die();

/**
 * Report script for the gradingcustomchatgpt plugin.
 *
 * @package     quiz_gradingcustomchatgpt
 * @copyright   2024 zal <zal@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 require_once(__DIR__.'/../../../../config.php');
 require_once($CFG->dirroot . '/mod/quiz/report/gradingcustomchatgpt/lib.php');
 
 require_login();
 
 // Set up the page
//  http://localhost/moodle/mod/quiz/report.php?id=11&mode=gradingcustomchatgpt
 $PAGE->set_url('/mod/quiz/report.php?id=11&mode=gradingcustomchatgpt');
 $PAGE->set_context(context_system::instance());
 $PAGE->set_title(get_string('pluginname', 'quiz_gradingcustomchatgpt'));
 $PAGE->set_heading(get_string('pluginname', 'quiz_gradingcustomchatgpt'));
 
 error_reporting(E_ALL);
 ini_set('display_errors', 1);

 echo $OUTPUT->header();
 
 // Initialize grading custom ChatGPT class
 $grading = new gradingcustomchatgpt();
 
// Check if form is submitted
if (optional_param('process', false, PARAM_BOOL)) {
    $question_attempt_id = optional_param('question_attempt_id', 0, PARAM_INT);
    $user_id = optional_param('user_id', 0, PARAM_INT);

    // Debugging POST data
    error_log("Received POST data: process = 1, question_attempt_id = $question_attempt_id, user_id = $user_id");

    if ($question_attempt_id && $user_id) {
        // Initialize grading custom ChatGPT class
        $grading = new gradingcustomchatgpt();
        $grading->process_student_answers($question_attempt_id, $user_id);
        echo $OUTPUT->notification('Processing completed', 'notifysuccess');
    } else {
        echo $OUTPUT->notification('Missing question attempt ID or user ID.', 'notifyproblem');
    }
}
 
 // Display the form
 echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
 echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'process', 'value' => '1']);
 echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'question_attempt_id', 'placeholder' => 'Question Attempt ID']);
 echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'user_id', 'placeholder' => 'User ID']);
 echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Process']);
 echo html_writer::end_tag('form');
 
 // Display the grading results
 global $DB;
 $grades = $DB->get_records('gradingform_chatgptgrades');
 
 if ($grades) {
     echo html_writer::start_tag('table', ['class' => 'generaltable']);
     echo html_writer::start_tag('tr');
     echo html_writer::tag('th', 'Question ID');
     echo html_writer::tag('th', 'User ID');
     echo html_writer::tag('th', 'Grade');
     echo html_writer::tag('th', 'Feedback');
     echo html_writer::end_tag('tr');
 
     foreach ($grades as $grade) {
         echo html_writer::start_tag('tr');
         echo html_writer::tag('td', $grade->question_id);
         echo html_writer::tag('td', $grade->user_id);
         echo html_writer::tag('td', $grade->grade_chatgpt);
         echo html_writer::tag('td', $grade->chatgpt_response);
         echo html_writer::end_tag('tr');
     }
     echo html_writer::end_tag('table');
 } else {
     echo html_writer::tag('p', get_string('nogrades', 'quiz_gradingcustomchatgpt'));
 }
 
 // Debugging
 error_log(print_r($grades, true));
 
 echo $OUTPUT->footer();
 