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
 * Quiz report to help teachers manually grade questions by students.
 *
 * This report basically provides two screens:
 * - List student attempts that might need manual grading / regarding.
 * - Provide a UI to grade all questions of a particular quiz attempt.
 *
 * @package   quiz_gradingcustoms
 * @copyright 2024 MFH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use mod_quiz\local\reports\report_base;

class quiz_gradingcustoms_report extends report_base {

    protected $viewoptions = array();
    protected $questions;
    protected $course;
    protected $cm;
    protected $quiz;
    protected $context;
    protected $shownames;

    public function display($quiz, $cm, $course) {
        global $OUTPUT;
        $this->quiz = $quiz;
        $this->cm = $cm;
        $this->course = $course;

        // Get the URL options.
        $grade = optional_param('grade', null, PARAM_ALPHA);
        $usageid = optional_param('usageid', 0, PARAM_INT);
        $slots = optional_param('slots', '', PARAM_SEQUENCE);
        if (!in_array($grade, array('all', 'needsgrading', 'autograded', 'manuallygraded', 'viewgrading', 'updategrading'))) {
            $grade = null;
        }
        
        // Check permissions.
        $this->context = context_module::instance($cm->id);
        require_capability('mod/quiz:grade', $this->context);

        // Process any submitted data.
        if ($data = data_submitted() && confirm_sesskey()) {
            $slotsArray = explode(',', $slots);
            $records = array();
            foreach ($slotsArray as $slot) {
                $prefix = "q{$usageid}:{$slot}_";
                $records[] = array(
                    'usageid'   => $usageid,
                    'slot'      => $slot,
                    'grade'     => $this->get_post_value($prefix . '-mark', 'float', 2),
                    'max_grade'  => $this->get_post_value($prefix . '-maxmark', 'float',2),
                    'status'      => 0
                );
            }
            $saveGrading = $this->save_grading_records($records, $usageid);
            if ($saveGrading) {
                redirect(new moodle_url('/mod/quiz/report.php',
                    array('id' => $this->cm->id, 'mode' => 'gradingcustoms')));
            }
        }

        $hasquestions = quiz_has_questions($quiz->id);

        // Start output.
        $this->print_header_and_tabs($cm, $course, $quiz, 'gradingcustoms');

        // Check if the session message exists and render the notification
        if (!empty($_SESSION['notifications'])) {
            // Iterate over each notification and render it
            foreach ($_SESSION['notifications'] as $notification) {
                $message_notif = $notification['message'];
                $type_notif = $notification['type'];
        
                $this->render_notification($message_notif, $type_notif);
            }
        
            // Clear the notifications after rendering
            unset($_SESSION['notifications']);
        }

        // check allowed role shortname : main_teacher, subs_teacher and head_teacher.
        if($this->check_role()){
            // What sort of page to display?
            if (!$hasquestions) {
                echo quiz_no_questions_message($quiz, $cm, $this->context);
            } else if (!$usageid) {
                $this->display_index();
            } else {
                $this->display_grading_interface($usageid, $slots, $grade);
            }
        } else {
            echo $OUTPUT->heading(get_string('donthaveaccess', 'quiz_gradingcustoms'));
        }
        return true;
    }

    protected function display_index() {
        global $OUTPUT, $PAGE;
        
        $PAGE->requires->js(new moodle_url('/mod/quiz/report/gradingcustoms/js/jquery-3.6.0.min.js'));
        $PAGE->requires->js(new moodle_url('/mod/quiz/report/gradingcustoms/js/script.js'));
        
        $attempts = $this->get_formatted_student_attempts();
        // Check the current group for the user looking at the report.
        $currentgroup = $this->get_current_group($this->cm, $this->course, $this->context);
        if ($currentgroup == self::NO_GROUPS_ALLOWED) {
            echo $OUTPUT->notification(get_string('notingroup'));
            return;
        }

        echo $OUTPUT->heading(get_string('questionsthatneedgrading', 'quiz_gradingcustoms', $this->check_role('name')));
        $enable_column_id = $this->get_setting_enable_collumnid();
        $data = array();
        foreach ($attempts as $key => $attempt) {

            if ($attempt->all == 0) {
                continue;
            }

            $row = [];
            if($enable_column_id){
                $row[] = md5($attempt->attemptid);
            }
            if ($this->check_role("code") == 'head') {
                $custom_grading = $this->get_data_custom_grading_by_usageid($attempt->uniqueid);
                $custom_grading['uniqueid'] = $attempt->uniqueid;
                [$count_need_grading, $need_grading] = $this->format_count_for_table($custom_grading, 'needsgrading', 'grade');
                [$count_update_grading, $update_grading] = $this->format_count_for_table($custom_grading, 'updategrading', 'updategrade');
                [$count_view_grading, $view_grading] = $this->format_count_for_table($custom_grading, 'viewgrading', 'viewgrade');

                // if (($count_need_grading + $count_update_grading + $count_view_grading) == 0) {
                //     continue;
                // }

                $row[] = $need_grading;
                $row[] = $update_grading;
                $row[] = $view_grading;
                $row[] = $attempt->totalgrademainisnull  ? '-' : $attempt->totalgrademain;
                $row[] = $attempt->totalgradesubsisnull  ? '-' : $attempt->totalgradesubs;
                $row[] = $attempt->totalgradefinalisnull ? '-' : $attempt->totalgradefinal;
            } else {
                $row[] = $this->format_count_for_table($attempt, 'needsgrading', 'grade');
                $row[] = $this->format_count_for_table($attempt, 'manuallygraded', 'updategrade', $attempt->status);
                $row[] = $attempt->totalgrade;
            }
            $row[] = $attempt->status;
            $data[] = $row;
        }

        if (empty($data)) {
            echo $OUTPUT->heading(get_string('nothingfound', 'quiz_gradingcustoms'));
            return;
        }

        $table = new html_table();
        $table->class = 'generaltable';
        $table->id = 'studentstograde';
        if($enable_column_id){
            $table->head[] = 'ID';
        }
        $table->head[] = get_string('tograde', 'quiz_gradingcustoms');
        $table->head[] = get_string('alreadygraded', 'quiz_gradingcustoms');
        if ($this->check_role("code") == 'head') {
            $table->head[] = get_string('viewgraded', 'quiz_gradingcustoms');
            $table->head[] = get_string('totalmarkmain', 'quiz_gradingcustoms');
            $table->head[] = get_string('totalmarksubs', 'quiz_gradingcustoms');
            $table->head[] = get_string('totalmarkhead', 'quiz_gradingcustoms');
        } else {
            $table->head[] = get_string('totalmark', 'quiz_gradingcustoms');
        }
        $table->head[] = get_string('status', 'quiz_gradingcustoms');
        
        $table->data = $data;

        $html_table = html_writer::table($table);

        $selected_filter_status = optional_param('status_filter', 'all', PARAM_RAW);
    
        // Extract unique statuses from the table
        $statuses = $this->extract_unique_statuses($html_table);

        if($enable_column_id){
            // Generate the search element
            $search = $this->generate_search_element();
            
            // Generate the select element
            $selectStatus = $this->generate_select_element($statuses, $selected_filter_status);
            $filteredTable = $this->filter_table_by_status($html_table, $selected_filter_status);
            
            $selected_filter_search = optional_param('search_filter', '', PARAM_RAW);
            if($selected_filter_search !== ''){
                $filteredTable = $this->filter_table_by_search($filteredTable, $selected_filter_search);
            }
    
            // search and filter
            echo '<div class="row mb-2">';
            echo '<div class="col-md-12"><a href="#" id="clear-filter">Clear search filter</a></div>';
            echo '<div class="col-md-6 float-left">' . $search .'</div>';
            echo '<div class="col-md-6 float-right">' . $selectStatus . '</div>';
            echo '</div>';
        } else {
            // Generate the select element
            $selectStatus = $this->generate_select_element($statuses, $selected_filter_status);
            $filteredTable = $this->filter_table_by_status($html_table, $selected_filter_status);

            echo html_writer::div($selectStatus, 'filter-container float-right mb-2');
        }

        echo html_writer::div($filteredTable, 'clearfix');
    }

    /**
     * Extracts unique statuses from the provided HTML table string.
     *
     * @param string $html_table The HTML table as a string.
     * @return array An array of unique statuses.
     */
    protected function extract_unique_statuses($html_table) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html_table);
        libxml_clear_errors();

        $statuses = [];
        $xpath = new DOMXPath($dom);
        $status_nodes = $xpath->query('//td[contains(@class, "cell") and contains(@class, "lastcol")]/span');

        foreach ($status_nodes as $status_node) {
            $status = $status_node->textContent;
            if (!in_array($status, $statuses)) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }    

    /**
     * Generates a search element filter value on table.
     *
     * @return string The HTML search element as a string.
     */

    protected function generate_search_element() {
        // Define the HTML for the search input and button with inline CSS
        $search_element = '
            <div class="input-group">
                <input type="search" id="searchFilter" placeholder="Search ID..." class="form-control" style="max-width: 300px;">
                <div class="input-group-append">
                    <button id="searchButton" onclick="search()" class="btn btn-primary" type="button">Search</button>
                </div>
            </div>
        ';
    
        return $search_element;
    }

    /**
     * Generates a select element with options based on the provided statuses and selected filter.
     *
     * @param array $statuses An array of statuses.
     * @param string $selected_filter The selected filter value.
     * @return string The HTML select element as a string.
     */
    protected function generate_select_element($statuses, $selected_filter) {
        $selectStatusOptions = '<option value="all"' . ($selected_filter === 'all' ? ' selected' : '') . '>Show All</option>';
        foreach ($statuses as $status) {
            $selected = $selected_filter === $status ? ' selected' : '';
            $selectStatusOptions .= '<option value="' . htmlspecialchars($status) . '"' . $selected . '>' . htmlspecialchars($status) . '</option>';
        }
        return '<select id="statusFilter" class="custom-select float-right">' . $selectStatusOptions . '</select>';
    }

    /**
     * Filters the provided HTML table string based on the selected status filter.
     *
     * @param string $html_table The HTML table as a string.
     * @param string $selected_filter The selected filter value.
     * @return string The filtered HTML table as a string.
     */
    protected function filter_table_by_status($html_table, $selected_filter) {
        if ($selected_filter === 'all') {
            return $html_table;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html_table);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//tbody/tr');

        foreach ($rows as $row) {
            $statusCell = $xpath->query('.//td[contains(@class, "cell") and contains(@class, "lastcol")]/span', $row)->item(0);
            if ($statusCell && $statusCell->textContent !== $selected_filter) {
                $row->parentNode->removeChild($row);
            }
        }

        return $dom->saveHTML($dom->getElementsByTagName('table')->item(0));
    }

    /**
     * Filters the provided HTML table string based on the search filter.
     *
     * @param string $html_table The HTML table as a string.
     * @param string $selected_filter The selected filter value.
     * @return string The filtered HTML table as a string.
     */
    protected function filter_table_by_search($html_table, $selected_filter) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html_table);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//tbody/tr');

        foreach ($rows as $row) {
            $firstTdInRow = $xpath->query('.//td[1]', $row)->item(0);
            $firstTdTextContent = $firstTdInRow->textContent;
            if ($firstTdInRow && strpos(mb_strtolower($firstTdTextContent), strtolower($selected_filter)) === false) {
                $row->parentNode->removeChild($row);
            }
        }

        return $dom->saveHTML($dom->getElementsByTagName('table')->item(0));
    }

    protected function display_grading_interface($usageid, $slots, $grade) {
        global $CFG, $OUTPUT, $PAGE;
        
        $PAGE->requires->js(new moodle_url('/mod/quiz/report/gradingcustoms/js/jquery-3.6.0.min.js'));
        $PAGE->requires->js(new moodle_url('/mod/quiz/report/gradingcustoms/js/script.js'));

        $attempts = $this->get_formatted_student_attempts();
        $attempt = $attempts[$usageid];
        $custom_grading = $this->get_data_custom_grading_by_usageid($usageid);

        // Prepare the form.
        $hidden = array(
            'id' => $this->cm->id,
            'mode' => 'gradingstudents',
            'usageid' => $usageid,
            'slots' => $slots,
        );

        if (array_key_exists('includeauto', $this->viewoptions)) {
            $hidden['includeauto'] = $this->viewoptions['includeauto'];
        }

        // Print the heading and form.
        echo question_engine::initialise_js();

        echo $OUTPUT->heading(get_string('gradingstudentrole', 'quiz_gradingcustoms', $this->check_role('name')));
        
        echo html_writer::tag('p', html_writer::link($this->list_questions_url(),
                get_string('backtothelistofstudentattempts', 'quiz_gradingcustoms')),
                array('class' => 'mdl-align'));

        // Display the form with one section for each attempt.
        $sesskey = sesskey();
        echo html_writer::start_tag('form', array('method' => 'post',
                'action' => $this->grade_question_url($usageid, $slots, $grade),
                'class' => 'mform', 'id' => 'manualgradingform')) .
                html_writer::start_tag('div') .
                html_writer::input_hidden_params(new moodle_url('', array(
                                'usageid' => $usageid, 'slots' => $slots, 'sesskey' => $sesskey)));
            $quba = question_engine::load_questions_usage_by_activity($usageid);
            $displayoptions = quiz_get_review_options($this->quiz, $attempt, $this->context);
            $displayoptions->generalfeedback = question_display_options::HIDDEN;
            $displayoptions->history = question_display_options::HIDDEN;
            if($grade === 'viewgrading'){
                $displayoptions->manualcomment = question_display_options::HIDDEN;
            }else{
                $displayoptions->manualcomment = question_display_options::EDITABLE;
            }
        foreach ($attempt->questions as $slot => $question) {
                $role = $this->check_role('code');
                if($role == 'head'){
                    $data = $this->findObjectBySlot($custom_grading, $slot);
                    if ($data !== false) {
                        $isView = $this->check_grade_type_question($data, $grade);
                        if($isView){
                            $grade_main = $this->formatGrade($data->grade_main);
                            $grade_subs = $this->formatGrade($data->grade_subs);
                            $grade_final = $this->formatGrade($data->grade_final);
                            $renderedQuestion = $quba->render_question($slot, $displayoptions, $this->questions[$slot]->number);
                            if($grade === 'viewgrading'){
                                $newElementHtml="<div class='formulation'>
                                                    <p>Nilai Dosen Utama    : {$grade_main}</p>
                                                    <p>Nilai Dosen Cadangan : {$grade_subs}</p>
                                                    <p>Nilai Final          : {$grade_final}</p>
                                                </div>";
                            }else{
                                $newElementHtml="<div class='formulation'>
                                                    <p>Nilai Dosen Utama    : {$grade_main}</p>
                                                    <p>Nilai Dosen Cadangan : {$grade_subs}</p>
                                                </div>";
                            }
                            $modifiedOutput = $this->addElementToRenderedQuestion($renderedQuestion, $newElementHtml);
                            $modifiedOutput = $this->hideCommentToRenderedQuestion($modifiedOutput);
                            $modifiedOutput = $this->processgradeRenderedHtml($modifiedOutput, $usageid);
                            echo $modifiedOutput;
                        }
                    }
                } else {
                    if ($this->normalise_state($question->state) === $grade || $question->state === $grade || $grade === 'all') {
                        $renderedQuestion = $quba->render_question($slot, $displayoptions, $this->questions[$slot]->number);
                        $modifiedOutput = $this->hideCommentToRenderedQuestion($renderedQuestion);
                        $modifiedOutput = $this->processgradeRenderedHtml($modifiedOutput, $usageid);
                        echo $modifiedOutput;
                    }
                }
        }

        if($grade !== 'viewgrading'){
            echo html_writer::tag('div', html_writer::empty_tag('input', array(
                    'type' => 'submit', 'value' => get_string('saveandgotothelistofattempts', 'quiz_gradingcustoms'))),
                    array('class' => 'mdl-align')) .
                    html_writer::end_tag('div') . html_writer::end_tag('form');
        }

        $PAGE->requires->string_for_js('changesmadereallygoaway', 'moodle');
        $PAGE->requires->yui_module('moodle-core-formchangechecker',
                'M.core_formchangechecker.init', [['formid' => 'manualgradingform']]);
    }

    /**
     * Return remove trailing zeros
     */
    protected function formatGrade($grade) {
        if ($grade == NULL) {
            return '-';
        }
        return rtrim(rtrim(sprintf('%.10f', $grade), '0'), '.');
    }

    /**
     * Return an array of slot filtered.
     */
    protected function findObjectBySlot($array, $slotValue) {
        foreach ($array as $item) {
            if ($item->slot == $slotValue) {
                return $item;
            }
        }
        return false;
    }

    /**
     * Return an render question after add element.
     */
    protected function addElementToRenderedQuestion($renderedHtml, $elementHtml) {
        // Load the HTML
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress errors due to invalid HTML
        $doc->loadHTML($renderedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
    
        // Find the element with class "content"
        $xpath = new DOMXPath($doc);
        $elements = $xpath->query("//*[contains(@class, 'content')]");
    
        foreach ($elements as $element) {
            // Create a new DOM fragment for the new element
            $fragment = $doc->createDocumentFragment();
            $fragment->appendXML($elementHtml);
            // Append the new element
            $element->appendChild($fragment);
        }
    
        // Return the modified HTML
        return $doc->saveHTML();
    }

    /**
     * Return an render question after hide commment.
     */
    protected function hideCommentToRenderedQuestion($renderedHtml) {
        // Load the HTML
        $doc = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true); // Suppress errors due to invalid HTML
        $doc->loadHTML('<?xml encoding="UTF-8">' . $renderedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
    
        // Create an XPath object
        $xpath = new DOMXPath($doc);
    
        // Hide labels with 'for' attribute containing '-comment_id'
        $labels = $xpath->query("//label[contains(@for, '-comment_id')]");
        foreach ($labels as $label) {
            $label->setAttribute('style', 'display:none;');
        }
    
        // Hide elements with class 'fhtmleditor'
        $editors = $xpath->query("//*[contains(@class, 'fhtmleditor')]");
        foreach ($editors as $editor) {
            $editor->setAttribute('style', 'display:none;');
        }
    
        // Restore previous error handling state
        libxml_use_internal_errors($internalErrors);
    
        // Return the modified HTML
        return $doc->saveHTML($doc->documentElement);
    }

    /**
     * Return an array of quiz attempts with all relevant information for each attempt.
     */
    protected function get_formatted_student_attempts() {
        $quizattempts = $this->get_quiz_attempts();
        $attempts = $this->get_question_attempts();
        if (!$quizattempts) {
            return array();
        }
        if (!$attempts) {
            return array();
        }
        $output = array();
        if($this->check_role('code') == 'head'){
            foreach ($quizattempts as $key => $quizattempt) {
                $grades = $this->get_grade_attempts($quizattempt->uniqueid);
                $questions = array();
                $needsgrading = 0;
                $autograded = 0;
                $manuallygraded = 0;
                $totalgradefinal = 0;
                $totalgradefinalisnull = true;
                $totalgradefinalisexistnull = false;
                $totalgrademain = 0;
                $totalgrademainisnull = true;
                $totalgrademainisexistnull = false;
                $totalgradesubs = 0;
                $totalgradesubsisnull = true;
                $totalgradesubsisexistnull = false;
                $all = 0;
                $status = get_string('statusfinal', 'quiz_gradingcustoms');
                foreach ($attempts as $attempt) {
                    if ($quizattempt->uniqueid === $attempt->usageid) {
                        $questions[$attempt->slot] = $attempt;
                        $state = $this->get_current_state_for_this_attempt($attempt->questionattemptid);
                        $questions[$attempt->slot]->state = $state;

                        $state_grading = $this->get_current_state_for_this_attempt_grade($attempt, $grades);
                        if ($this->normalise_state($state) === 'needsgrading') {
                            if($state_grading){
                                if($state_grading['status'] == 0){
                                    $status = get_string('statussaved', 'quiz_gradingcustoms');
                                }
                                $questions[$attempt->slot]->state = 'manuallygraded';
                                if($state_grading['grade_final'] !== null){
                                    $totalgradefinalisnull = false;
                                    $totalgradefinal += (float)$state_grading['grade_final'];
                                }else{
                                    $totalgradefinalisexistnull = true;
                                }
                                if($state_grading['grade_main'] !== null){
                                    $totalgrademainisnull = false;
                                    $totalgrademain += (float)$state_grading['grade_main'];
                                }else{
                                    $totalgrademainisexistnull = true;
                                }
                                if($state_grading['grade_subs'] !== null){
                                    $totalgradesubsisnull = false;
                                    $totalgradesubs += (float)$state_grading['grade_subs'];
                                }else{
                                    $totalgradesubsisexistnull = true;
                                }
                                $manuallygraded++;
                            } else {
                                $needsgrading++;
                            }
                        }
                        if ($this->normalise_state($state) === 'autograded') {
                            $autograded++;
                        }
                        if ($this->normalise_state($state) === 'manuallygraded') {
                            if($state_grading){
                                if($state_grading['grade_final'] !== null){
                                    $totalgradefinalisnull = false;
                                    $totalgradefinal += (float)$state_grading['grade_final'];
                                }else{
                                    $totalgradefinalisexistnull = true;
                                }
                                if($state_grading['grade_main'] !== null){
                                    $totalgrademainisnull = false;
                                    $totalgrademain += (float)$state_grading['grade_main'];
                                }else{
                                    $totalgrademainisexistnull = true;
                                }
                                if($state_grading['grade_subs'] !== null){
                                    $totalgradesubsisnull = false;
                                    $totalgradesubs += (float)$state_grading['grade_subs'];
                                }else{
                                    $totalgradesubsisexistnull = true;
                                }
                                if($state_grading['status'] == 0){
                                    $status = get_string('statussaved', 'quiz_gradingcustoms');
                                }
                            }
                            $manuallygraded++;
                        }
                        $all++;
                    }
                }
                $quizattempt->needsgrading = $needsgrading;
                $quizattempt->autograded = $autograded;
                $quizattempt->manuallygraded = $manuallygraded;
                $quizattempt->all = $all;
                $quizattempt->questions = $questions;
                $quizattempt->totalgradefinal = $totalgradefinal;
                $quizattempt->totalgradefinalisnull = $totalgradefinalisnull;
                $quizattempt->totalgrademain = $totalgrademain;
                $quizattempt->totalgrademainisnull = $totalgrademainisnull;
                $quizattempt->totalgradesubs = $totalgradesubs;
                $quizattempt->totalgradesubsisnull = $totalgradesubsisnull;

                if($totalgradefinalisexistnull && !$totalgrademainisnull && !$totalgradesubsisnull){
                    $quizattempt->status = get_string('statusgrading', 'quiz_gradingcustoms');
                } elseif ($totalgrademainisnull || $totalgradesubsisnull || $totalgrademainisexistnull || $totalgradesubsisexistnull){
                    $quizattempt->status = get_string('statusprocess', 'quiz_gradingcustoms');
                } else {
                    $quizattempt->status = $status;
                }
                
                $output[$quizattempt->uniqueid] = $quizattempt;
            }
        } else {
            foreach ($quizattempts as $key => $quizattempt) {
                $grades = $this->get_grade_attempts($quizattempt->uniqueid);
                $questions = array();
                $needsgrading = 0;
                $autograded = 0;
                $manuallygraded = 0;
                $totalgrade = 0;
                $all = 0;
                $status = get_string('statusfinal', 'quiz_gradingcustoms');
                foreach ($attempts as $attempt) {
                    if ($quizattempt->uniqueid === $attempt->usageid) {
                        $questions[$attempt->slot] = $attempt;
                        $state = $this->get_current_state_for_this_attempt($attempt->questionattemptid);
                        $questions[$attempt->slot]->state = $state;

                        $state_grading = $this->get_current_state_for_this_attempt_grade($attempt, $grades);
                        if ($this->normalise_state($state) === 'needsgrading') {
                            if($state_grading){
                                if($state_grading['status'] == 0){
                                    $status = get_string('statussaved', 'quiz_gradingcustoms');
                                }
                                $questions[$attempt->slot]->state = 'manuallygraded';
                                $totalgrade += (float)$state_grading['grade'];
                                $manuallygraded++;
                            }else {
                                $needsgrading++;
                            }
                        }
                        if ($this->normalise_state($state) === 'autograded') {
                            $autograded++;
                        }
                        if ($this->normalise_state($state) === 'manuallygraded') {
                            if($state_grading){
                                $totalgrade += (float)$state_grading['grade'];
                                if($state_grading['status'] == 0){
                                    $status = get_string('statussaved', 'quiz_gradingcustoms');
                                }
                            }
                            $manuallygraded++;
                        }
                        $all++;
                    }
                }
                $quizattempt->needsgrading = $needsgrading;
                $quizattempt->autograded = $autograded;
                $quizattempt->manuallygraded = $manuallygraded;
                $quizattempt->all = $all;
                $quizattempt->questions = $questions;
                $quizattempt->totalgrade = $totalgrade;
                if ($quizattempt->manuallygraded == 0 || $quizattempt->needsgrading > 0) {
                    $quizattempt->status = get_string('statusgrading', 'quiz_gradingcustoms');
                } else {
                    $quizattempt->status = $status;
                }
                $output[$quizattempt->uniqueid] = $quizattempt;
            }
        }
        return $output;
    }

    /**
     * Return an array of quiz attempts, augmented by user idnumber.
     *
     * @param object $quiz quiz settings.
     * @return array of objects containing fields from quiz_attempts with user idnumber.
     */
    private function get_quiz_attempts() {
        global $DB;

        // Get the group, and the list of significant users.
        $currentgroup = $this->get_current_group($this->cm, $this->course, $this->context);
        if ($currentgroup == self::NO_GROUPS_ALLOWED) {
            return [];
        }

        $groupstudentsjoins = get_enrolled_with_capabilities_join($this->context, '',
                ['mod/quiz:attempt', 'mod/quiz:reviewmyattempts'], $currentgroup);
        $userfieldsapi = \core_user\fields::for_identity($this->context, true)
                ->with_name()->excluding('id', 'idnumber');

        $params = [
            'quizid' => $this->quiz->id,
            'state' => 'finished',
        ];
        $fieldssql = $userfieldsapi->get_sql('u', true);
        $sql = "SELECT qa.id AS attemptid, qa.uniqueid, qa.attempt AS attemptnumber,
                       qa.quiz AS quizid, qa.layout, qa.userid, qa.timefinish, qa.timestart,
                       qa.preview, qa.state, u.idnumber $fieldssql->selects
                  FROM {user} u
                  JOIN {quiz_attempts} qa ON u.id = qa.userid
                  {$groupstudentsjoins->joins}
                  {$fieldssql->joins}
                  WHERE {$groupstudentsjoins->wheres}
                    AND qa.quiz = :quizid
                    AND qa.state = :state
                  ORDER BY qa.userid DESC";

                 $params = array_merge($fieldssql->params, $groupstudentsjoins->params, $params);
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Return and array of question attempts.
     * @return array an array of question attempts.
     */
    private function get_question_attempts() {
        global $DB;
        $sql = "SELECT qa.id AS questionattemptid, qa.slot, qa.questionid, qu.id AS usageid
                  FROM {question_usages} qu
                  JOIN {question_attempts} qa ON qa.questionusageid = qu.id
                 WHERE qu.contextid = :contextid
              ORDER BY qa.slot ASC";
        return $DB->get_records_sql($sql, array('contextid' => $this->context->id));
    }

    /**
     * Return and array of grade attempts.
     *
     * @param int $usageid unique id attempt.
     * @return array an array of question attempts.
     */
    private function get_grade_attempts($usageid) {
        global $DB;
        $sql = "SELECT *
                FROM {gradingform_customgraders} gc
                WHERE gc.usageid = :usageid";
        return $DB->get_records_sql($sql, array('usageid' => $usageid));
    }

    /**
     * Return the latest state for a given question.
     *
     * @param int $attemptid as question_attempt id.
     * @return string the attempt state.
     */
    private function get_current_state_for_this_attempt($attemptid) {
        global $DB;
        $sql = "SELECT qas.*
                  FROM {question_attempt_steps} qas
                 WHERE questionattemptid = :qaid
              ORDER BY qas.sequencenumber ASC";
        $states = $DB->get_records_sql($sql, array('qaid' => $attemptid));
        return end($states)->state;
    }

    /**
     * Return data custom grading ny usageid.
     *
     * @param int $usageid as question_attempt id.
     * @return object the attempt state.
     */
    private function get_data_custom_grading_by_usageid($usageid) {
        global $DB;
        $sql = "SELECT gc.*
                  FROM {gradingform_customgraders} gc
                 WHERE usageid = :usageid
              ORDER BY gc.slot ASC";
        return $DB->get_records_sql($sql, array('usageid' => $usageid));
    }

    /**
     * Return the latest state for a given question.
     *
     * @param object $attempt as question_attempt.
     * @param array $grade as question_grade.
     * @return object the attempt grade if found.
     */
    private function get_current_state_for_this_attempt_grade($attempt, $grades) {
        $role = $this->check_role('code');
        foreach ($grades as $grade) {
            if ($grade->slot == $attempt->slot && $grade->usageid == $attempt->usageid){
                if($role == 'head'){
                    return [
                        'status' => $grade->status,
                        'grade_final' => $grade->grade_final,
                        'grade_main' => $grade->grade_main,
                        'grade_subs' => $grade->grade_subs
                    ];
                }else{
                    $fgrade = "grade_$role";
                    if ($grade->$fgrade != NULL) {
                        return [
                            'status' => $grade->status,
                            'grade' => $grade->$fgrade
                        ];
                    }
                }

            }
        }
        return false;
    }

    /**
     * Normalise the string from the database table for easy comparison.
     *
     * @param string $state
     * @return string|null the classified state.
     */
    protected function normalise_state($state) {
        if (!$state) {
            return null;
        }
        if ($state === 'needsgrading') {
            return 'needsgrading';
        }
        if (substr($state, 0, strlen('graded')) === 'graded') {
            return 'autograded';
        }
        if (substr($state, 0, strlen('mangr')) === 'mangr' || $state == 'manuallygraded') {
            return 'manuallygraded';
        }
        return null;
    }

    /**
     * Return formatted output.
     *
     * @param object $attempt augmented quiz_attempts row as from get_formatted_student_attempts.
     * @param string $type type of attempts, e.g. 'needsgrading'.
     * @param string $gradestring corresponding lang string for the action, e.g. 'grade'.
     * @return string formatted string.
     */
    protected function format_count_for_table($attempt, $type, $gradestring, $status = true) {
        $role = $this->check_role('code');
        if ($role == 'head') {
            $counts = 0;
            $slots = array();
            $custom_grading = $attempt;
            foreach ($custom_grading as $data) {
                $check_grade = $this->check_grade_type_question($data, $type);

                if($check_grade){
                    $counts += 1;
                    $slots[] = $data->slot;
                }
            }
            
            $slots = implode(',', $slots);
            $result = $counts;
            if ($counts > 0) {
                $result .= ' ' . html_writer::link($this->grade_question_url(
                    $attempt['uniqueid'], $slots, $type),
                    get_string($gradestring, 'quiz_gradingcustoms'),
                    array('class' => 'gradetheselink'));
            }
            return [$counts, $result];
        } else {
            $counts = $attempt->$type;
            $slots = array();
            if ($counts > 0) {
                foreach ($attempt->questions as $id => $question) {
                    if ($type === $this->normalise_state($question->state) || $type === 'all') {
                        $slots[] = $question->slot;
                    }
                }
            }
            $slots = implode(',', $slots);
            $result = $counts;
            if ($counts > 0) {
                if ($status !== true && $status == get_string('statusfinal', 'quiz_gradingcustoms')) {
                    $result .= ' ' . html_writer::link($this->grade_question_url(
                            $attempt->uniqueid, $slots, $type),
                            get_string('viewgrade', 'quiz_gradingcustoms'),
                            array('class' => 'gradetheselink'));
                } else {
                    $result .= ' ' . html_writer::link($this->grade_question_url(
                            $attempt->uniqueid, $slots, $type),
                            get_string($gradestring, 'quiz_gradingcustoms'),
                            array('class' => 'gradetheselink'));
                }
            }
        }
        
        return $result;
    }
    /**
     * Return check grade as head.
     *
     */
    protected function check_grade_type_question($data, $type) {
        switch ($type) {
            case 'needsgrading':
                if($data->grade_head == NULL && $data->grade_final == NULL && $data->max_diff != NULL && $data->grade_main != NULL && $data->grade_subs != NULL){
                    return true;
                }
                break;
            case 'updategrading':
                if($data->grade_head != NULL){
                    return true;
                }
                break;
            case 'viewgrading':
                if(($data->grade_main != NULL || $data->grade_subs != NULL) && $data->grade_head == NULL){
                    return true;
                }
                break;
        }
        return false;
    }



    /**
     * Return url for appropriate questions.
     *
     * @param int $usageid the usage id of the attempt to grade.
     * @param string $slots comma-sparated list of the slots to grade.
     * @param string $grade type of things to grade, e.g. 'needsgrading'.
     * @return moodle_url the requested URL.
     */
    protected function grade_question_url($usageid, $slots, $grade) {
        $url = $this->base_url();
        $url->params(array('ctid' => $this->context->id, 'usageid' => $usageid, 'slots' => $slots, 'grade' => $grade));
        $url->params($this->viewoptions);
        return $url;
    }

    /**
     * Return the base URL of the report.
     *
     * @return moodle_url the URL.
     */
    protected function base_url() {
        return new moodle_url('/mod/quiz/report.php',
                array('id' => $this->cm->id, 'mode' => 'gradingcustoms'));
    }

    protected function check_role($mode = "code"){
        global $USER, $DB;
        
        $role_shortnames = [
            'head' => 'head_teacher',
            'main' => 'main_teacher',
            'subs' => 'subs_teacher',
        ];
        
        $user_role = null;
        
        foreach ($role_shortnames as $role_name => $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname]);
            if ($role && user_has_role_assignment($USER->id, $role->id, $this->context->id)) {
                if($mode === 'code'){
                    return $role_name;
                }
                $user_role = $role;
                break;
            }
        }
        
        if ($user_role !== null){
            switch ($mode) {
                case 'name':
                    return $user_role->name;
                    break;
                case 'shortname':
                    return $user_role->shortname;
                    break;
                default:
                return $user_role;
                    break;
            }
        }
        return false;
    }

    /**
     * Get the URL of the front page of the report that lists all attempts.
     *
     * @param bool|null $includeauto if not given, use the current setting, otherwise,
     *      force a particular value of includeauto in the URL.
     * @return moodle_url the URL.
     */
    protected function list_questions_url($includeauto = null) {
        $url = $this->base_url();

        $url->params($this->viewoptions);

        if ($includeauto !== null) {
            if ($includeauto) {
                $url->param('includeauto', 1);
            } else {
                $url->remove_params('includeauto');
            }
        }
        return $url;
    }
    

    /**
     * Helper function to retrieve and optionally round POST data.
     *
     * @param string $key The POST key to retrieve.
     * @param string $type The type to cast the value to (int, float, text).
     * @param int|null $roundTo Optional. The number of decimal places to round to (for float values).
     * @return mixed The retrieved and optionally rounded value, or null if not set.
     */
    protected function get_post_value($key, $type = 'text', $roundTo = null) {
        if (!isset($_POST[$key])) {
            return null;
        }
    
        switch ($type) {
            case 'int':
                return (int)$_POST[$key];
            case 'float':
                if($_POST[$key] == '') return null;
                $value = (float)$_POST[$key];
                return is_null($roundTo) ? $value : round($value, $roundTo);
            case 'text':
            default:
                return $_POST[$key];
        }
    }

    /**
     * Helper function to save data grading
     *
     * @param array $records The POST data request.
     * @param int $usageid data usageid.
     * @return boolean status succes or fail of the transaction.
     */
    protected function save_grading_records($records, $usageid) {
        global $DB, $USER;
    
        // Start the transaction
        $transaction = $DB->start_delegated_transaction();
    
        try {
            $role = $this->check_role('code');
            $grader = "grader_$role";
            $grade = "grade_$role";
            $temp_post = $_POST;
            $_POST = [];
            $message = 'Sucess!';
            $messageType = 'success';
            foreach ($records as $record) {
                $data = new stdClass();
                $data->usageid = $record['usageid'];
                $data->slot = $record['slot'];
                if($role && $record['grade'] !== NULL && $record['grade'] >= 0 && $record['grade'] <= $record['max_grade']){
                    $data->$grader = $USER->id;
                    $data->$grade = $record['grade'];
                }else{
                    // message notif if the data not in range
                    $_SESSION['notifications'][] = ['message' => get_string('notifnotinrange', 'quiz_gradingcustoms'), 'type' => 'error'];
                    return false;
                }
                $data->timestamp = time();
    
                // Check if a record with the same usageid and slot exists
                $existing_record = $DB->get_record('gradingform_customgraders', array('usageid' => $data->usageid, 'slot' => $data->slot));
    
                if ($existing_record) {
                    if($existing_record->grade_head == NULL || $role == 'head'){
                        $data->id = $existing_record->id;
                        $DB->update_record('gradingform_customgraders', $data);
                        $message = get_string('notifgradeupdated', 'quiz_gradingcustoms');
                    } else {
                        // message notif warning can not change grade if the data already grade by head teacher/kajur.
                        $_SESSION['notifications'][] = ['message' => get_string('notifalreadygradehead', 'quiz_gradingcustoms'), 'type' => 'warning'];
                        continue;
                    }
                } else {
                    $DB->insert_record('gradingform_customgraders', $data);
                    $message = get_string('notifgradesaved', 'quiz_gradingcustoms');
                }
                
                // Get final grade if the diff not exceed the max_diff between subs and main, if not return false
                $finalGrade = $this->check_status_grading($data->usageid, $data->slot);
                if($finalGrade){
                    if(count($_POST) == 0){
                        $_POST["usageid"]  = $data->usageid;
                        $_POST["slots"]    = "{$data->slot}";
                        $_POST["sesskey"]  = $temp_post["sesskey"];
                    } else {
                        $_POST["slots"]    .= ",{$data->slot}";
                    }

                    $prefix = "q{$data->usageid}:{$data->slot}_";

                    $_POST[$prefix . ":sequencecheck"]    = $temp_post[$prefix . ":sequencecheck"];
                    $_POST[$prefix . "-comment"]          = $temp_post[$prefix . "-comment"];
                    $_POST[$prefix . "-comment:itemid"]   = $temp_post[$prefix . "-comment:itemid"];
                    $_POST[$prefix . "-commentformat"]    = $temp_post[$prefix . "-commentformat"];
                    $_POST[$prefix . "-mark"]             = $finalGrade;
                    $_POST[$prefix . "-maxmark"]          = $temp_post[$prefix . "-maxmark"];  
                    $_POST[$prefix . ":minfraction"]      = $temp_post[$prefix . ":minfraction"]; 
                    $_POST[$prefix . ":maxfraction"]      = $temp_post[$prefix . ":maxfraction"];

                } else {
                    
                }
            }
            $transaction->allow_commit();

            if (count($_POST) > 0) {
                $this->process_submitted_data($usageid);
            }
            
            // message notif saved
            $_SESSION['notifications'][] = ['message' => $message, 'type' => $messageType];

            return true;
        } catch (Exception $e) {
            $transaction->rollback($e);
            return false;
        }
    }

    

    /**
     * Helper function to save data grading
     *
     * @return float setting max diff.
     */
    protected function get_setting_maxdiff() {
        return (float)get_config('quiz_gradingcustoms', 'max_diff');
    }

    

    /**
     * Helper function to save data grading
     *
     * @return boolean setting enable column ID.
     */
    protected function get_setting_enable_collumnid() {
        return get_config('quiz_gradingcustoms', 'column_id');
    }

    /**
     * Return url for appropriate questions.
     *
     * @param int $usageid the usage id of the attempt to grade.
     * @param string $slot comma-sparated list of the slots to grade.
     */
    protected function check_status_grading($usageid, $slot) {
        global $DB;

        $finalGrade = false;

        $maxdiff = $this->get_setting_maxdiff();
        if($maxdiff){
            $existing_record = $DB->get_record('gradingform_customgraders', array('usageid' => $usageid, 'slot' => $slot));
            
            $data = new stdClass();
            $data->id = $existing_record->id;

            if(empty($existing_record->max_diff)){
                $data->max_diff = $maxdiff;
            }

            $role = $this->check_role('code');
            if($role == 'head'){
                if($existing_record->grade_head != NULL){
                    $data->grade_final = $existing_record->grade_head;
                    $data->status = 1;
                    $finalGrade = $data->grade_final;
                }
            } elseif ($existing_record->grade_main != NULL && $existing_record->grade_subs != NULL && $existing_record->grade_head == NULL) {
                $diff= $existing_record->grade_main - $existing_record->grade_subs;
                if(abs($diff) <= $maxdiff){
                    $data->grade_final = round((($existing_record->grade_main + $existing_record->grade_subs)/2) , 2);
                    $data->status = 1;
                    $finalGrade = number_format($data->grade_final, 2,'.','');
                } else {
                    $data->status = 0;
                    $data->grade_final = NULL;
                    $DB->update_record('gradingform_customgraders', $data);
                }
            }

            if($data->max_diff || $data->grade_final){
                $DB->update_record('gradingform_customgraders', $data);
            } 
        }
        return $finalGrade;
    }

    /**
     * Update the quiz attempt with the new grades.
     *
     * @param int $usageid usage id of the quiz attempt being graded.
     */
    protected function process_submitted_data($usageid) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $attempt = $DB->get_record('quiz_attempts', array('uniqueid' => $usageid));
        $attemptobj = new quiz_attempt($attempt, $this->quiz, $this->cm, $this->course);
        $attemptobj->process_submitted_actions(time());
        $transaction->allow_commit();
    }

    /**
     * render notification error.
     *
     * @param string $message the usage id of the attempt to grade.
     * @param string $type notification type.
     */
    protected function render_notification($message, $type = 'error'){
        global $OUTPUT;
        switch ($type) {
            case 'success':
                $notification = new \core\output\notification($message, \core\output\notification::NOTIFY_SUCCESS);
                break;
            case 'warning':
                $notification = new \core\output\notification($message, \core\output\notification::NOTIFY_WARNING);
                break;
            case 'error':
                $notification = new \core\output\notification($message, \core\output\notification::NOTIFY_ERROR);
                break;
            case 'info':
                $notification = new \core\output\notification($message, \core\output\notification::NOTIFY_INFO);
                break;
        }
        echo $OUTPUT->render($notification);
    }

    protected function processgradeRenderedHtml($renderedHtml, $usageid) {
        // Fetch query parameters
        $ctid = optional_param('ctid', null, PARAM_ALPHA);
        $typeGrade = optional_param('grade', null, PARAM_ALPHA);
    
        // Load the HTML
        $doc = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $renderedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
    
        switch ($typeGrade) {
            case 'manuallygraded':
                $grades = $this->fetchGradeAttempts($ctid, $usageid);
                foreach ($grades as $value) {
                    $prefix = "q{$usageid}:{$value->slot}_"; // Access object properties using '->'
                    $markId = $prefix . '-mark';
                    $markElement = $doc->getElementById($markId);
                    if ($markElement) {
                        $formattedValue = $this->formatGrade($value->grade);
                        $markElement->setAttribute('value', $formattedValue);
                    }
                }
                break;
    
            case 'updategrading':
                $xpath = new DOMXPath($doc);
                $inputs = $xpath->query("//input[contains(@name, '_-mark')]");
                foreach ($inputs as $input) {
                    $formattedValue = $this->formatGrade($input->getAttribute('value'));
                    $input->setAttribute('value', $formattedValue);
                }
                break;
    
            case 'needsgrading':
                $xpath = new DOMXPath($doc);
                $inputs = $xpath->query("//input[contains(@name, '_-mark')]");
                foreach ($inputs as $input) {
                    $input->setAttribute('value', '');
                }
                break;
    
            default:
                // No action needed
                break;
        }
    
        // Restore previous error handling state
        libxml_use_internal_errors($internalErrors);
    
        // Return the modified HTML
        return $doc->saveHTML($doc->documentElement);
    }
    
    protected function fetchGradeAttempts($ctid, $usageid) {
        global $DB;
        $role = $this->check_role_bt_ctid($ctid);
        $sql = "SELECT gc.slot, gc.grade_$role as `grade` 
                FROM {gradingform_customgraders} gc 
                WHERE gc.usageid = :usageid";
        return $DB->get_records_sql($sql, array('usageid' => $usageid));
    }

    protected function check_role_bt_ctid($ctid){
        global $USER, $DB;
        
        $role_shortnames = [
            'head' => 'head_teacher',
            'main' => 'main_teacher',
            'subs' => 'subs_teacher',
        ];
        
        foreach ($role_shortnames as $role_name => $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname]);
            if ($role && user_has_role_assignment($USER->id, $role->id, $ctid)) {
                return $role_name;
                break;
            }
        }
        return false;
    }
}