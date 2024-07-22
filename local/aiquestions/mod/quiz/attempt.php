<?php


if ($quiz_completed) {
    // Panggil fungsi untuk mengirim jawaban ke OpenAI
    $evaluation_result = local_aiquestions_evaluate_quiz($quiz_questions, $user_answers);
    
    // Proses hasil evaluasi
    local_aiquestions_process_evaluation($evaluation_result, $quiz_id, $user_id);
}
