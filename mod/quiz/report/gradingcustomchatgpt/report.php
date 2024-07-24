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
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/report/gradingcustomchatgpt/lib.php');

require_login();

// Set up the page
$id = optional_param('id', 0, PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);
$quiz_id = optional_param('quiz_id', 0, PARAM_INT);

$PAGE->set_url('/mod/quiz/report.php', ['id' => $id, 'mode' => $mode]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'quiz_gradingcustomchatgpt'));
$PAGE->set_heading(get_string('pluginname', 'quiz_gradingcustomchatgpt'));

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo $OUTPUT->header();

// Fetch quizzes for search
$quizzes = $DB->get_records_menu('quiz', null, 'name ASC', 'id, name');

if (!$quizzes) {
    echo $OUTPUT->notification(get_string('no_quizzes_found', 'quiz_gradingcustomchatgpt'), 'notifyproblem');
} else {
    // Display form to select quiz and process grades
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);

    // Search form
    echo html_writer::start_tag('div', ['class' => 'search-form']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'quiz_search',
        'placeholder' => get_string('search_quiz', 'quiz_gradingcustomchatgpt'),
        'value' => optional_param('quiz_search', '', PARAM_TEXT)
    ]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'search', 'value' => get_string('search', 'quiz_gradingcustomchatgpt')]);
    echo html_writer::end_tag('div');

    // Process form
    echo html_writer::start_tag('div', ['class' => 'process-form']);
    echo html_writer::select($quizzes, 'quiz_id', $quiz_id, ['' => get_string('select_quiz', 'quiz_gradingcustomchatgpt')]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'process', 'value' => '1']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('process_generate', 'quiz_gradingcustomchatgpt')]);
    echo html_writer::end_tag('div');

    echo html_writer::end_tag('form');
}

// Initialize grading custom ChatGPT class
$grading = new gradingcustomchatgpt();

if (optional_param('process', false, PARAM_BOOL)) {
    if ($quiz_id) {
        $grading->process_all_answers_for_quiz($quiz_id);
        echo $OUTPUT->notification(get_string('processing_completed', 'quiz_gradingcustomchatgpt'), 'notifysuccess');
    } else {
        echo $OUTPUT->notification(get_string('missing_quiz_id', 'quiz_gradingcustomchatgpt'), 'notifyproblem');
    }
}

if ($quizzes && $quiz_id) {
    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'No');
    echo html_writer::tag('th', 'Nama Siswa');
    // echo html_writer::tag('th', 'Feedback');
    echo html_writer::tag('th', 'Soal');
    echo html_writer::tag('th', 'Jawaban Siswa');
    echo html_writer::tag('th', 'Nilai ChatGPT');
    echo html_writer::tag('th', 'Feedback');
    echo html_writer::end_tag('tr');

    $grades = $grading->fetch_grades_for_quiz($quiz_id);
    // var_dump($grades);
    foreach ($grades as $grade) {
        $user = $DB->get_record('user', ['id' => $grade->user_id]);
        $user_fullname = $user ? $user->firstname . ' ' . $user->lastname : 'No name';

        $question = $DB->get_record('question', ['id' => $grade->question_id]);
        $attempt = $DB->get_record('question_attempts', ['id' => $grade->question_attempt_id]);
        $answer = $attempt ? $attempt->responsesummary : 'No answer';

        // Note: Ensure $grade->chatgpt_response and $grade->grade_chatgpt are correctly populated by fetch_grades_for_quiz()
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $grade->question_attempt_id); // Changed to question_attempt_id for better clarity
        echo html_writer::tag('td', $user_fullname);
        // echo html_writer::tag('td', isset($grade->chatgpt_response) ? $grade->chatgpt_response : 'No feedback');
        echo html_writer::tag('td', isset($question->questiontext) ? $question->questiontext : 'No question');
        echo html_writer::tag('td', $answer);
        echo html_writer::tag('td', isset($grade->grade_chatgpt) ? $grade->grade_chatgpt : 'No grade');
        echo html_writer::tag('td', isset($grade->chatgpt_response) ? $grade->chatgpt_response : 'No feedback');
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('table');
} else {
    echo html_writer::tag('p', get_string('no_quizzes_found', 'quiz_gradingcustomchatgpt'));
}


echo $OUTPUT->footer();
