<?php

/**
 * Callback function to extend the navigation.
 *
 * @param settings_navigation $settingsnav The settings navigation.
 * @param navigation_node $mypluginnode The plugin navigation node.
 */
function myplugin_extend_navigation(settings_navigation $settingsnav, navigation_node $mypluginnode) {
    global $PAGE;

    // Add the custom CSS file to the page.
    $PAGE->requires->css('mod/quiz/report/gradingcustoms/styles.css');
}
