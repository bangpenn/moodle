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
    public function process_all_answers_for_quiz($quiz_id) {
        global $DB;
    
        // Fetch all question attempts for the given quiz
        $sql = "
            SELECT DISTINCT qa.id AS question_attempt_id, qa.questionid AS question_id, qas.userid AS user_id
            FROM {question_attempts} qa
            JOIN {question_attempt_steps} qas ON qa.id = qas.questionattemptid
            WHERE qa.questionusageid IN (
                SELECT qu.id
                FROM {question_usages} qu
                JOIN {quiz_attempts} qa2 ON qa2.uniqueid = qu.id
                JOIN {quiz} qz ON qz.id = qa2.quiz
                WHERE qz.id = :quiz_id
            )
        ";
    
        $params = ['quiz_id' => $quiz_id];
        $attempts = $DB->get_records_sql($sql, $params);
    
        if (empty($attempts)) {
            throw new Exception('No question attempts found for the given quiz ID.');
        }
    
        foreach ($attempts as $attempt) {
            try {
                $this->process_student_answers($attempt->question_attempt_id, $attempt->user_id);
            } catch (Exception $e) {
                error_log('Error processing student answers: ' . $e->getMessage());
                // Handle errors as needed
            }
        }
    }

    

    function fetch_grades_for_quiz($quiz_id) {
        global $DB;
        
        // SQL query to fetch the grades and feedback
        $sql = "
        SELECT DISTINCT
            qa.id AS question_attempt_id,
            qa.questionid AS question_id,
            qus.userid AS user_id,
            q.questiontext AS question_text,
            qa.responsesummary AS user_answer,  
            g.grade_chatgpt AS grade_chatgpt,
            g.chatgpt_response AS chatgpt_response
        FROM
            mdl_question_attempts qa
            JOIN mdl_question_attempt_steps qus ON qa.id = qus.questionattemptid
            JOIN mdl_question_usages qu ON qu.id = qa.questionusageid
            JOIN mdl_question q ON qa.questionid = q.id
            LEFT JOIN mdl_gradingform_chatgptgrades g ON g.question_attempt_id = qa.id
            JOIN mdl_quiz_attempts qa2 ON qa2.uniqueid = qu.id
            JOIN mdl_quiz qz ON qz.id = qa2.quiz
            JOIN mdl_course_modules cm ON cm.instance = qz.id
        WHERE
            q.qtype = 'essay'
            AND qus.state = 'complete'
            AND qa2.quiz = :quiz_id
        GROUP BY
            qa.id, qa.questionid, qus.userid, q.questiontext, qa.responsesummary, g.grade_chatgpt, g.chatgpt_response
        LIMIT 25;";
        
        // Parameters for the SQL query
        $params = ['quiz_id' => $quiz_id];
        
        // Execute the query and fetch results
        $records = $DB->get_records_sql($sql, $params);

        error_log(print_r($records, true)); // Debugging statement

        
        return $records;
    }
    
    
    


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
   public function evaluate_with_chatgpt($question, $answer) {
        if (!function_exists('parse_env_file')) {
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

    protected function parse_grade_from_feedback($feedback) {
        // Extract grade from the feedback
        if (preg_match('/\b(\d{1,3})\b/', $feedback, $matches)) {
            $grade = intval($matches[1]);
            return min(max($grade, 0), 100); // Ensure grade is between 0 and 100
        }
        return 0; // Default grade if none found
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


    function process_missing_grades_for_quiz($quiz_id) {
        global $DB;
    
        // Fetch all question attempts with missing grades
        $sql = "
            SELECT DISTINCT qa.id AS question_attempt_id, qa.questionid AS question_id, qas.userid AS user_id
            FROM {question_attempts} qa
            JOIN {question_attempt_steps} qas ON qa.id = qas.questionattemptid
            LEFT JOIN {gradingform_chatgptgrades} g ON g.question_attempt_id = qa.id
            WHERE g.grade_chatgpt IS NULL
              AND qa.questionusageid IN (
                  SELECT qu.id
                  FROM {question_usages} qu
                  JOIN {quiz_attempts} qa2 ON qa2.uniqueid = qu.id
                  JOIN {quiz} qz ON qz.id = qa2.quiz
                  WHERE qz.id = :quiz_id
              )
        ";
    
        $params = ['quiz_id' => $quiz_id];
        $attempts = $DB->get_records_sql($sql, $params);
    
        if (empty($attempts)) {
            throw new Exception('No question attempts found for the given quiz ID.');
        }
    
        foreach ($attempts as $attempt) {
            try {
                $this->process_student_answers($attempt->question_attempt_id, $attempt->user_id);
            } catch (Exception $e) {
                error_log('Error processing student answers: ' . $e->getMessage());
                // Handle errors as needed
            }
        }
    }

    
    
}

function get_chatgpt_grade($userid, $question_id) {
    global $DB;

    $sql = "SELECT grade_chatgpt AS grade
            FROM {gradingform_chatgptgrades}
            WHERE user_id = :userid AND question_id = :question_id";

    $params = ['userid' => $userid, 'question_id' => $question_id];
    $result = $DB->get_record_sql($sql, $params);

    return $result ? $result->grade : '';
}

function get_quiz_id_from_cmid($cmid) {
    global $DB;
    // Fetch the course module record for the given CMID
    $cm = $DB->get_record('course_modules', ['id' => $cmid], 'instance');
    if ($cm && $cm->instance) {
        // Fetch the quiz record using the instance ID
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id');
        if ($quiz) {
            return $quiz->id;
        }
    }
    return null; // Return null if no quiz is found
}














