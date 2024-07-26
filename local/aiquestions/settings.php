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

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_pptgenerator_settings', new lang_string('pluginname', 'local_pptgenerator'));

    // Title
    $settings->add(new admin_setting_configtext(
        'local_pptgenerator/title',
        get_string('ppttitle', 'local_pptgenerator'),
        get_string('ppttitledesc', 'local_pptgenerator'),
        '', PARAM_TEXT
    ));

    // Content
    $settings->add(new admin_setting_configtextarea(
        'local_pptgenerator/content',
        get_string('pptcontent', 'local_pptgenerator'),
        get_string('pptcontentdesc', 'local_pptgenerator'),
        '', PARAM_TEXT
    ));

    // Outline
    $settings->add(new admin_setting_configtextarea(
        'local_pptgenerator/outline',
        get_string('pptoutline', 'local_pptgenerator'),
        get_string('pptoutlinedesc', 'local_pptgenerator'),
        '', PARAM_TEXT
    ));

    // Template
    $templates = array(
        'default' => get_string('template_default', 'local_pptgenerator'),
        'business' => get_string('template_business', 'local_pptgenerator'),
        'education' => get_string('template_education', 'local_pptgenerator')
    );
    $settings->add(new admin_setting_configselect(
        'local_pptgenerator/template',
        get_string('ppttemplate', 'local_pptgenerator'),
        get_string('ppttemplatedesc', 'local_pptgenerator'),
        'default', $templates
    ));

    $ADMIN->add('localplugins', $settings);
}
