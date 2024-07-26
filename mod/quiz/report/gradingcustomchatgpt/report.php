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
require_once(__DIR__.'/../../../../config.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/report/gradingcustomchatgpt/lib.php');

/**
 * Report script for the gradingcustomchatgpt plugin.
 *
 * @package     quiz_gradingcustomchatgpt
 * @copyright   2024 zal <zal@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_login();

$mode = optional_param('mode', '', PARAM_ALPHA);
$cmid = required_param('id', PARAM_INT);
$quiz_id = get_quiz_id_from_cmid($cmid);
$generate = optional_param('generate', 0, PARAM_BOOL);
$regenerate = optional_param('regenerate', 0, PARAM_BOOL);
$ajax = optional_param('ajax', 0, PARAM_BOOL);


$PAGE->set_url('/mod/quiz/report.php', ['id' => $cmid]);
$PAGE->set_context(context_module::instance($cmid));
$PAGE->set_title(get_string('pluginname', 'quiz_gradingcustomchatgpt'));
$PAGE->set_heading(get_string('pluginname', 'quiz_gradingcustomchatgpt'));

$grading = new gradingcustomchatgpt();

if ($ajax) {
    header('Content-Type: application/json');
    
    try {
        if ($generate) {
            $grading->process_missing_grades_for_quiz($quiz_id);
        }
        if ($regenerate) {
            $grading->process_all_answers_for_quiz($quiz_id);
        }

        $grades = $grading->fetch_grades_for_quiz($quiz_id);
        echo json_encode([
            'status' => 'success',
            'grades' => $grades
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$grades = $grading->fetch_grades_for_quiz($quiz_id);
echo $OUTPUT->header();

$all_grades_generated = true;
$missing_grades = false;
foreach ($grades as $grade) {
    if (empty($grade->grade_chatgpt)) {
        $all_grades_generated = false;
        $missing_grades = true;
        break;
    }
}

echo html_writer::start_tag('form', ['id' => 'gradingForm', 'method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quiz_id', 'value' => $quiz_id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ajax', 'value' => '1']);

if ($missing_grades) {
    echo html_writer::empty_tag('input', ['type' => 'button', 'value' => get_string('generate_missing_grades', 'quiz_gradingcustomchatgpt'), 'onclick' => 'confirmGenerateGrades()']);
}

if ($all_grades_generated) {
    echo html_writer::empty_tag('input', ['type' => 'button', 'value' => get_string('regenerate_grades', 'quiz_gradingcustomchatgpt'), 'onclick' => 'confirmRegenerateGrades()']);
}

echo html_writer::end_tag('form');

echo html_writer::start_tag('table', ['class' => 'generaltable']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', 'No');
echo html_writer::tag('th', 'Nama Siswa');
echo html_writer::tag('th', 'Soal');
echo html_writer::tag('th', 'Jawaban Siswa');
echo html_writer::tag('th', 'Nilai ChatGPT');
echo html_writer::tag('th', 'Feedback');
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

$i = 1;
foreach ($grades as $grade) {
    $user = $DB->get_record('user', ['id' => $grade->user_id]);
    $user_fullname = $user ? $user->firstname . ' ' . $user->lastname : 'No name';
    $question = $DB->get_record('question', ['id' => $grade->question_id]);
    $attempt = $DB->get_record('question_attempts', ['id' => $grade->question_attempt_id]);
    $answer = $attempt ? $attempt->responsesummary : 'No answer';

    // var_dump($grades);

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $i++);
    echo html_writer::tag('td', $user_fullname);
    echo html_writer::tag('td', isset($question->questiontext) ? $question->questiontext : 'No question');
    echo html_writer::tag('td', $answer);
    echo html_writer::tag('td', isset($grade->grade_chatgpt) ? $grade->grade_chatgpt : 'No grade');
    echo html_writer::tag('td', isset($grade->chatgpt_response) ? $grade->chatgpt_response : 'No feedback');
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
error_log(print_r($grades, true));

echo $OUTPUT->footer();

?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script type="text/javascript">
function confirmGenerateGrades() {
    if (confirm("Some grades are missing. Do you want to generate missing grades?")) {
        $.ajax({
            url: '<?php echo $PAGE->url; ?>&mode=gradingcustomchatgpt&ajax=1&generate=1',
            type: 'POST',
            data: {
                quiz_id: $('input[name="quiz_id"]').val(),
            },
            success: function(response) {
                console.log('Response received:', response);

                try {
                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                    if (data.status === 'success') {
                        updateTableWithLatestData($('input[name="quiz_id"]').val());
                        alert('Grades generated successfully');
                    } else {
                        alert('Error: ' + data.message);
                    }
                } catch (e) {
                    console.error('Failed to parse JSON response:', e);
                    console.error('Response received:', response); 
                    alert('An error occurred while processing the request.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                alert('An error occurred: ' + error);
                console.error('Response:', xhr.responseText);
            }

        });
    } else {
        alert("Operation cancelled.");
    }
}

function confirmRegenerateGrades() {
    if (confirm("All grades are already generated. Do you want to regenerate grades?")) {
        $.ajax({
            url: '<?php echo $PAGE->url; ?>&mode=gradingcustomchatgpt&ajax=1&regenerate=1',
            type: 'POST',
            data: {
                quiz_id: $('input[name="quiz_id"]').val(),
            },
            success: function(response) {
                console.log('Response received:', response); 

                try {
                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                    if (data.status === 'success') {
                        updateTableWithLatestData($('input[name="quiz_id"]').val());
                        alert('Grades regenerated successfully');
                    } else {
                        alert('Error: ' + data.message);
                    }
                } catch (e) {
                    console.error('Failed to parse JSON response:', e);
                    console.error('Response received:', response); 
                    alert('An error occurred while processing the request.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                alert('An error occurred: ' + error);
                console.error('Response:', xhr.responseText);
            }

        });
    } else {
        alert("Operation cancelled.");
    }
}

function updateTableWithLatestData(quizId) {
    $.ajax({
        url: '<?php echo $PAGE->url; ?>&mode=gradingcustomchatgpt&ajax=1',
        type: 'GET',
        data: {
            quiz_id: quizId
        },
        success: function(response) {
            console.log('Response received for table update:', response);

            try {
                // Jika respons berupa objek, pastikan data valid dan dalam format JSON
                const data = typeof response === 'string' ? JSON.parse(response) : response;
                if (data.status === 'success') {
                    updateTable(data.grades);
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (e) {
                console.error('Failed to parse JSON response:', e);
                console.error('Response received:', response);
                alert('An error occurred while processing the request.');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            alert('An error occurred: ' + error);
            console.error('Response:', xhr.responseText);
        }
    });
}


function updateTable(grades) {
    // Update the table with grades data
    let tableBody = $('table tbody');
    tableBody.empty(); // Clear the existing table content

    $.each(grades, function(key, grade) {
        // console.log(grades);

        let row = `<tr>
            <td>${key + 1}</td>
            <td>${grade.user_fullname || 'N/A'}</td>
            <td>${grade.question_text || 'N/A'}</td>
            <td>${grade.user_answer || 'N/A'}</td>
            <td>${grade.grade_chatgpt || 'N/A'}</td>
            <td>${grade.chatgpt_response || 'N/A'}</td>
        </tr>`;
        tableBody.append(row);
    });
}
</script>
