<?php

$evaluation = $DB->get_record('quiz_evaluation', ['quizid' => $quiz_id, 'userid' => $user_id]);

if ($evaluation) {
    echo "Your Score: " . $evaluation->score;
    echo "Feedback: " . $evaluation->feedback;
}
