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
 * Callback function to extend the navigation.
 *
 * @param settings_navigation $settingsnav The settings navigation.
 * @param navigation_node $mypluginnode The plugin navigation node.
 */
function myplugin_extend_navigations(settings_navigation $settingsnav, navigation_node $mypluginnode) {
    global $PAGE;

    // Add the custom CSS file to the page.
    $PAGE->requires->css('mod/quiz/report/gradingcustomchatgpt/styles.css');
}

/**
 * Function to insert a new grade record into the mdl_gradingform_chatgptgrades table.
 *
 * @param int $questionattemptid The ID of the question attempt.
 * @param int $questionid The ID of the question.
 * @param int $userid The ID of the user.
 * @param float $gradechatgpt The grade given by ChatGPT.
 * @param string $chatgptresponse The feedback or response from ChatGPT.
 * @param int $timestamp The timestamp of when the grading was done.
 * @return bool True on success, false on failure.
 */
class gradingcustomchatgpt {
    protected function get_question_by_attempt_id($question_attempt_id) {
        global $DB;

        $sql = "
            SELECT q.id AS question_id, q.questiontext, q.qtype
            FROM {question_attempts} qa
            JOIN {question} q ON qa.questionid = q.id
            WHERE qa.id = :question_attempt_id
            AND q.qtype = 'essay'
        ";
        return $DB->get_record_sql($sql, ['question_attempt_id' => $question_attempt_id]);
    }

    protected function get_student_answer($question_attempt_id, $user_id) {
        global $DB;

        $sql = "
            SELECT qa.responsesummary AS answer
            FROM {question_attempts} qa
            JOIN {question_attempt_steps} qas ON qa.id = qas.questionattemptid
            WHERE qa.id = :question_attempt_id
            AND qas.userid = :user_id
            GROUP BY qa.id
        ";

        $answer = $DB->get_record_sql($sql, [
            'question_attempt_id' => $question_attempt_id,
            'user_id' => $user_id
        ]);

        if ($answer) {
            // Sanitasi atau validasi jawaban jika perlu
            $answer->answer = filter_var($answer->answer, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            return $answer;
        } else {
            throw new Exception('No answer found for given question attempt ID and user ID.');
        }
    }

   // evaluate ditaruh disini
   protected function evaluate_with_chatgpt($question, $answer) {
        function parse_env_file($path) {
            $vars = [];
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') === false) {
                    continue;
                }
                list($key, $value) = explode('=', $line, 2);
                $vars[trim($key)] = trim($value);
            }
            return $vars;
        }
        // Path to your .env file
        $env_file_path = __DIR__ . '/.env';

        // Load environment variables from .env file
        $env_vars = parse_env_file($env_file_path);

        // Access the API key
        $api_key = $env_vars['OPENAI_API_KEY'] ?? '';

        if (!$api_key) {
            die("API key tidak ditemukan dalam file .env.");
        }
        $url = 'https://api.openai.com/v1/chat/completions'; // Ensure the correct endpoint for chat completion
        
        $messages = [
            ["role" => "system", "content" => "You are a helpful assistant that grades essay answers."],
            ["role" => "user", "content" => "Pertanyaan: $question\nJawaban: $answer\n\nBerikan penilaian (1-100) untuk jawaban ini dan berikan Feedback:"]
        ];
        
        $data = array(
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'max_tokens' => 150,
            'temperature' => 0.7,
            'top_p' => 1.0
        );
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
        $response = curl_exec($ch);
        if(curl_errno($ch)) {
            error_log('Curl error: ' . curl_error($ch));
        }
        curl_close($ch);
    
        $response_data = json_decode($response, true);
        if (isset($response_data['choices'][0]['message']['content'])) {
            $text = $response_data['choices'][0]['message']['content'];
            list($grade_str, $feedback) = explode("\n", trim($text), 2);
            $grade = (int) filter_var($grade_str, FILTER_SANITIZE_NUMBER_INT);
    
            return array(
                'grade' => $grade,
                'feedback' => $feedback
            );
        } else {
            error_log('Error: ' . print_r($response_data, true));
            return array(
                'grade' => 0,
                'feedback' => 'Evaluation failed.'
            );
        }
    }

    
    protected function save_chatgpt_grades($question_attempt_id, $question_id, $user_id, $grade, $feedback) {
        global $DB;

        $record = new stdClass();
        $record->question_attempt_id = $question_attempt_id;
        $record->question_id = $question_id;
        $record->user_id = $user_id;
        $record->grade_chatgpt = $grade;
        $record->chatgpt_response = $feedback;
        $record->timestamp = time();

        if ($existing = $DB->get_record('gradingform_chatgptgrades', ['question_attempt_id' => $question_attempt_id, 'user_id' => $user_id])) {
            $record->id = $existing->id;
            $DB->update_record('gradingform_chatgptgrades', $record);
        } else {
            $DB->insert_record('gradingform_chatgptgrades', $record);
        }
    }

    public function process_student_answers($question_attempt_id, $user_id) {
        $question_data = $this->get_question_by_attempt_id($question_attempt_id);
        $answer = $this->get_student_answer($question_attempt_id, $user_id);

        $evaluation = $this->evaluate_with_chatgpt($question_data->questiontext, $answer->answer);
        $grade = $evaluation['grade'];
        $feedback = $evaluation['feedback'];

        $this->save_chatgpt_grades($question_attempt_id, $question_data->question_id, $user_id, $grade, $feedback);
    }

    
    
}

function get_chatgpt_grade($userid, $question_id) {
    global $DB;

    $sql = "SELECT grade_chatgpt AS grade
            FROM {gradingform_chatgptgrades}
            WHERE user_id = :userid AND question_id = :question_id";

    $params = ['userid' => $userid, 'question_id' => $question_id];
    $result = $DB->get_record_sql($sql, $params);

    return $result ? $result->grade : '-';
}






