<?php
defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    // Create a new settings page specific to the gradingcustoms report.
    $settings = new admin_settingpage('quiz_gradingcustoms', get_string('pluginname', 'quiz_gradingcustoms'), 'moodle/site:config');

    // Add the max_diff setting.
    $settings->add(new admin_setting_configtext(
        'quiz_gradingcustoms/max_diff', // The name of the setting.
        get_string('max_diff', 'quiz_gradingcustoms'), // The title of the setting.
        get_string('max_diff_desc', 'quiz_gradingcustoms'), // Description.
        3, // Default value.
        PARAM_INT // Type of the setting value.
    ));

    // Add the column_id setting as a checkbox.
    $settings->add(new admin_setting_configcheckbox(
        'quiz_gradingcustoms/column_id', // The name of the setting.
        get_string('column_id', 'quiz_gradingcustoms'), // The title of the setting.
        get_string('column_id_desc', 'quiz_gradingcustoms'), // Description.
        1 // Default value (1 for true, 0 for false).
    ));

    // Add the settings page to the quiz report settings category.
    $ADMIN->add('reports', $settings);
}
