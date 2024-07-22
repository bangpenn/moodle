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
 * Plugin administration pages are defined here.
 *
 * @package     local_aiquestions
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Get questions from the API.
 *
 * @param int $courseid course id
 * @param string $story text of the story
 * @param int $numofquestions number of questions to generate
 * @param bool $idiot 1 if ChatGPT is an idiot, 0 if not
 * @return object questions of generated questions
 */
function local_aiquestions_get_questions($courseid, $story, $numofquestions, $idiot = 1) {
    global $CFG, $DB;

    // Logging start of function
    mtrace("Starting local_aiquestions_get_questions function.");

    // $language = get_config('local_aiquestions', 'language');
    $language = 'id'; // Setting Indonesian language
    $savelang = current_language();
    force_current_language('en');
    $languages = get_string_manager()->get_list_of_languages();

    // Logging language check
    mtrace("Available languages: " . json_encode($languages));

    // Check if Indonesian language is available in the list of languages
    if (array_key_exists($language, $languages)) {
        $language = $languages[$language];
    } else {
        // If not found, fallback to default language
        $language = 'id'; // Set to correct language name
    }

    // Logging restored language
    force_current_language($savelang);
    mtrace("Restored language: " . $savelang);

    // Explanation for generating questions
    $explanation = "Please write $numofquestions multiple choice question in $language language";
    $explanation .= " in GIFT format on the following text, ";
    $explanation .= " GIFT format use equal sign for right answer and tilde sign for wrong answer at the beginning of answers.";
    $explanation .= " For example: '::Question title { =right answer ~wrong answer ~wrong answer ~wrong answer }' ";
    $explanation .= " Please have a blank line between questions. ";

    // Logging explanation
    mtrace("Explanation: " . $explanation);

    if ($idiot == 1) {
        $explanation .= " Write the questions in the right format! ";
        $explanation .= " Do not forget any equal or tilde sign !";
    }

    // Logging key retrieval
    $key = get_config('local_aiquestions', 'key');
    mtrace("API Key retrieved: " . $key);

    $url = 'https://api.openai.com/v1/chat/completions';
    $authorization = "Authorization: Bearer " . $key;

    // Remove new lines and carriage returns.
    $story = str_replace("\n", " ", $story);
    $story = str_replace("\r", " ", $story);

    // Prepare data for API request
    $data = '{
        "model": "gpt-3.5-turbo",
        "messages": [
            {"role": "system", "content": "' . $explanation . '"},
            {"role": "user", "content": "' . local_aiquestions_escape_json($story) . '"}
        ]
    }';

    // Logging API request data
    mtrace("API Request Data: " . $data);

    // Initiate cURL session
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $authorization));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2000);

    // Execute cURL request
    $result = json_decode(curl_exec($ch));

    // Logging API response
    mtrace("API Response: " . json_encode($result));

    // Close cURL session
    curl_close($ch);

    $questions = new stdClass(); // The questions object.

    // Check if questions were successfully generated
    if (isset($result->choices[0]->message->content)) {
        $questions->text = $result->choices[0]->message->content;
        $questions->prompt = $story;
    } else {
        $questions = $result;
        $questions->prompt = $story;
    }

    // Logging end of function and return
    mtrace("Exiting local_aiquestions_get_questions function.");
    return $questions;
}

/**
 * Create questions from data got from ChatGPT output.
 *
 * @param int $courseid course id
 * @param string $gift questions in GIFT format
 * @param int $numofquestions number of questions to generate
 * @param int $userid user id
 * @return array of objects of created questions
 */
function local_aiquestions_create_questions($courseid, $gift, $numofquestions, $userid) {
    global $CFG, $USER, $DB;

    // Logging start of function
    mtrace("Starting local_aiquestions_create_questions function.");

    require_once($CFG->libdir . '/questionlib.php');
    require_once($CFG->dirroot . '/question/format.php');
    require_once($CFG->dirroot . '/question/format/gift/format.php');

    $qformat = new \qformat_gift();

    $coursecontext = \context_course::instance($courseid);

    // Use existing questions category for quiz or create the defaults.
    $contexts = new core_question\local\bank\question_edit_contexts($coursecontext);
    if (!$category = $DB->get_record('question_categories', ['contextid' => $coursecontext->id, 'sortorder' => 999])) {
        $category = question_make_default_categories($contexts->all());
    }

    // Split questions based on blank lines.
    // Then loop through each question and create it.
    $questions = explode("\n\n", $gift);

    // Logging number of questions and gift format
    mtrace("Number of questions: " . $numofquestions);
    mtrace("Gift format: " . json_encode($questions));

    if (count($questions) != $numofquestions) {
        mtrace("Error: Number of questions does not match expected.");
        return false;
    }

    $createdquestions = []; // Array of objects of created questions.
    foreach ($questions as $question) {
        $singlequestion = explode("\n", $question);
        // Manipulating question text manually for question text field.
        $questiontext = explode('{', $singlequestion[0]);
        $questiontext = trim(str_replace('::', '', $questiontext[0]));
        $qtype = 'multichoice';
        $q = $qformat->readquestion($singlequestion);

        // Logging question creation attempt
        mtrace("Attempting to create question: " . $questiontext);

        // Check if question is valid.
        if (!$q) {
            mtrace("Error: Invalid question format.");
            return false;
        }

        $q->category = $category->id;
        $q->createdby = $userid;
        $q->modifiedby = $userid;
        $q->timecreated = time();
        $q->timemodified = time();
        $q->questiontext = ['text' => "<p>" . $questiontext . "</p>"];
        $q->questiontextformat = 1;

        $created = question_bank::get_qtype($qtype)->save_question($q, $q);

        // Logging question creation result
        if ($created) {
            mtrace("Question created successfully.");
            $createdquestions[] = $created;
        } else {
            mtrace("Error: Failed to save question.");
        }
    }

    // Logging end of function and return
    mtrace("Exiting local_aiquestions_create_questions function.");
    if (!empty($createdquestions)) {
        return $createdquestions;
    } else {
        return false;
    }
}

/**
 * Escape json.
 *
 * @param string $value json to escape
 * @return string result escaped json
 */
function local_aiquestions_escape_json($value) {
    $escapers = array("\\", "/", "\"", "\n", "\r", "\t", "\x08", "\x0c");
    $replacements = array("\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b");
    // Continue from the previous function

    $result = str_replace($escapers, $replacements, $value);
    return $result;
}

/**
 * Check if the gift format is valid.
 *
 * @param string $gift questions in GIFT format
 * @return bool true if valid, false if not
 */
function local_aiquestions_check_gift($gift) {
    $questions = explode("\n\n", $gift);

    foreach ($questions as $question) {
        $qa = str_replace("\n", "", $question);
        preg_match('/::(.*)\{/', $qa, $matches);
        if (isset($matches[1])) {
            $qlength = strlen($matches[1]);
        } else {
            return false;
            // Error : Question title not found.
        }
        if ($qlength < 10) {
            return false;
            // Error : Question length too short.
        }
        preg_match('/\{(.*)\}/', $qa, $matches);
        if (isset($matches[1])) {
            $wrongs = substr_count($matches[1], "~");
            $right = substr_count($matches[1], "=");
        } else {
            return false;
            // Error : Answers not found.
        }
        if ($wrongs != 3 || $right != 1) {
            return false;
            // Error : There is no single right answers or no 3 wrong answers.
        }
    }
    return true;
}


/**
 * Evaluate quiz answers using OpenAI API.
 *
 * @param array $questions Questions from the quiz
 * @param array $answers User's answers
 * @return object Result of the evaluation
 */
function local_aiquestions_evaluate_quiz($questions, $answers) {
    $api_key = get_config('local_aiquestions', 'key');
    $url = 'https://api.openai.com/v1/chat/completions';
    $authorization = "Authorization: Bearer " . $api_key;

    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Evaluate the following quiz answers.'
            ],
            [
                'role' => 'user',
                'content' => json_encode(['questions' => $questions, 'answers' => $answers])
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $authorization]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2000);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $result;
}

/**
 * Process evaluation result from OpenAI.
 *
 * @param object $result Evaluation result from OpenAI
 * @param int $quiz_id Quiz ID
 * @param int $user_id User ID
 */
function local_aiquestions_process_evaluation($result, $quiz_id, $user_id) {
    if (isset($result['choices'][0]['message']['content'])) {
        $evaluation = json_decode($result['choices'][0]['message']['content'], true);

        // Save evaluation result
        local_aiquestions_save_evaluation($quiz_id, $user_id, $evaluation);
    } else {
        // Handle error
        mtrace("Error in evaluation response");
    }
}

/**
 * Save evaluation result to database.
 *
 * @param int $quiz_id Quiz ID
 * @param int $user_id User ID
 * @param array $evaluation Evaluation data
 */
function local_aiquestions_save_evaluation($quiz_id, $user_id, $evaluation) {
    global $DB;

    $record = new stdClass();
    $record->quizid = $quiz_id;
    $record->userid = $user_id;
    $record->score = $evaluation['score'];
    $record->feedback = $evaluation['feedback'];
    $record->timecreated = time();

    $DB->insert_record('quiz_evaluation', $record);
}




