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
use mod_maici\assistant_file;

/**
 * Library of interface functions and constants.
 *
 * @package     mod_maici
 * @copyright   
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return true | null True if the feature is supported, null otherwise.
 */
function maici_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:  return true;
        case FEATURE_SHOW_DESCRIPTION: return true;
        default: return null;
    }
}

/**
 * Add a get_coursemodule_info function in case any glossary type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function maici_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, name, intro, introformat, completionai';
    if (!$moduleinstance = $DB->get_record('maici', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $moduleinstance->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('maici', $moduleinstance, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionai'] = $moduleinstance->completionai;
    }

    return $result;
}

/**
 * Lists all browsable file areas
 *
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @return array
 * @package
 * @category files
 */
function maici_get_file_areas($course, $cm, $context) {
    return array(
        'module_instructions' => get_string('instructions', 'mod_maici'),
        'assistantfile' => get_string('assistantfile', 'mod_maici'),
    );
}

/**
 * Saves a new instance of the mod_maici into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $moduleinstance An object from the form.
 * @param mod_maici_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function maici_add_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timecreated = time();
    $moduleinstance->instructiontokens = maici_count_tokens($moduleinstance->sourceoftruth) + maici_count_tokens($moduleinstance->prompt);

    $id = $DB->insert_record('maici', $moduleinstance);
    $moduleinstance->id = $id;

    if (!isset($moduleinstance->coursemodule)) {
        $cm = get_coursemodule_from_id('feedback', $moduleinstance->id);
        $moduleinstance->coursemodule = $cm->id;
    }
    $context = context_module::instance($moduleinstance->coursemodule);

    if ($draftitemid = $moduleinstance->instructions['itemid']) {
        $moduleinstance->instructions_submit = file_save_draft_area_files($draftitemid, $context->id,
            'mod_maici', 'instructions_submit',
            0, maici_get_editor_options($context),
            $moduleinstance->instructions['text']);

        $moduleinstance->instructionsformat = $moduleinstance->instructions['format'];
    }

    if (isset($moduleinstance->assistantfile)) {
        $options = array('subdirs' => false, 'embed' => false);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_maici', 'assistantfile', $moduleinstance->id);
        file_save_draft_area_files($moduleinstance->assistantfile, $context->id, 'mod_maici', 'assistantfile', $moduleinstance->id, $options);

        $file_ai_manager = new \mod_maici\assistant_file($moduleinstance, $context->id);
        $moduleinstance->assistantfileid = $file_ai_manager->openai_assistantfiles_request();
    }

    $DB->update_record('maici', $moduleinstance);

    return $id;
}

/**
 * Updates an instance of the mod_maici in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $moduleinstance An object from the form in mod_form.php.
 * @param mod_maici_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function maici_update_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;
    $moduleinstance->instructiontokens = maici_count_tokens($moduleinstance->sourceoftruth) + maici_count_tokens($moduleinstance->prompt);

    $DB->update_record("maici", $moduleinstance);

    $context = context_module::instance($moduleinstance->coursemodule);
    $editoroptions = maici_get_editor_options($context);

    // process the custom wysiwyg editor in page_after_submit
    if ($draftitemid = $moduleinstance->instructions['itemid']) {
        $moduleinstance->instructions_submit = file_save_draft_area_files($draftitemid, $context->id,
            'mod_maici', 'instructions_submit',
            0, $editoroptions,
            $moduleinstance->instructions['text']);

        $moduleinstance->instructionsformat = $moduleinstance->instructions['format'];
    }
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_maici', 'assistantfile', $moduleinstance->id);
    if ($moduleinstance->assistantfile) {
        $options = array('subdirs' => false, 'embed' => false);
        file_save_draft_area_files($moduleinstance->assistantfile, $context->id, 'mod_maici', 'assistantfile', $moduleinstance->id, $options);

        $file_ai_manager = new \mod_maici\assistant_file($moduleinstance, $context->id);
        $moduleinstance->assistantfileid = $file_ai_manager->openai_assistantfiles_request();
    }

    return $DB->update_record('maici', $moduleinstance);
}

/**
 * Removes an instance of the mod_maici from the database.
 *
 * @param int $id Id of the module instance.
 * @return bool True if successful, false on failure.
 */
function maici_delete_instance($id) {
    global $DB;

    $exists = $DB->get_record('maici', array('id' => $id));
    if (!$exists) {
        return false;
    }

    $DB->delete_records('maici', array('id' => $id));

    return true;
}

/**
 * @param cm_info $cm
 * @return void
 * @throws dml_exception
 */
function maici_cm_info_view(cm_info $cm) {
    global $DB;
    $moduleinstance = $DB->get_record('maici', ['id' => $cm->instance]);
    $moduleinstance->coursemoduleid = $cm->id;
    $moduleinstance->context = context_module::instance($cm->id);
    $intro = '';

    if ($cm->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $intro = format_module_intro('maici', $moduleinstance, $cm->id, false);
    }

    $cm->set_content($intro,true);
}

/**
 * Extends the settings navigation with the mod_maici settings.
 *
 * This function is called when the context for the page is a mod_maici module.
 * This is not called by AJAX so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav {@see settings_navigation}
 * @param navigation_node $maicinode {@see navigation_node}
 */
function maici_extend_settings_navigation($settingsnav, $maicinode) {
    global $PAGE, $DB;
    $keys = $maicinode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }


    if (has_capability('mod/maici:viewmodulereport', context_module::instance($PAGE->cm->id))) {
        $url = new moodle_url('/mod/maici/report_logging.php',  array('id' => $PAGE->cm->id));
        $node = navigation_node::create(get_string('report_logging', 'mod_maici'),
            $url,
            navigation_node::TYPE_ACTIVITY, null, 'mod_maici_report_logging',new  image_icon('i/report', 'reportlogging'));
        $maicinode->add_node($node, $beforekey);
    }
}

function maici_fetch_assistants_array($apikey = null) {
    global $DB;

    if (!$apikey) {
        $apikey = get_config('mod_maici', 'apikey');
    }

    $curl = new \curl();
    $curl->setopt(array(
        'CURLOPT_HTTPHEADER' => array(
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
            'OpenAI-Beta: assistants=v1'
        ),
    ));

    $response = $curl->get("https://api.openai.com/v1/assistants?order=desc");
    $response = json_decode($response);

    if(property_exists($response,'error')){
        return false;
    }

    $assistant_array = [];
    foreach ($response->data as $assistant) {
        $assistant_array[$assistant->id] = $assistant->name;
    }

    return $assistant_array;
}

function maici_get_models() {
    return [
        "models" => [
            'gpt-4' => 'gpt-4',
            'gpt-4-1106-preview' => 'gpt-4-1106-preview',
            'gpt-4-0613' => 'gpt-4-0613',
            'gpt-4-0314' => 'gpt-4-0314',
            'gpt-3.5-turbo' => 'gpt-3.5-turbo',
            'gpt-3.5-turbo-16k-0613' => 'gpt-3.5-turbo-16k-0613',
            'gpt-3.5-turbo-16k' => 'gpt-3.5-turbo-16k',
            'gpt-3.5-turbo-1106' => 'gpt-3.5-turbo-1106',
            'gpt-3.5-turbo-0613' => 'gpt-3.5-turbo-0613',
            'gpt-3.5-turbo-0301' => 'gpt-3.5-turbo-0301',
        ],
        "types" => [
            'gpt-4' => 'chat',
            'gpt-4-1106-preview' => 'chat',
            'gpt-4-0613' => 'chat',
            'gpt-4-0314' => 'chat',
            'gpt-3.5-turbo' => 'chat',
            'gpt-3.5-turbo-16k-0613' => 'chat',
            'gpt-3.5-turbo-16k' => 'chat',
            'gpt-3.5-turbo-1106' => 'chat',
            'gpt-3.5-turbo-0613' => 'chat',
            'gpt-3.5-turbo-0301' => 'chat',
        ]
    ];
}

/**
 * This gets an array with default options for the editor
 *
 * @return array the options
 */
function maici_get_editor_options($context) {
    global $CFG;
    return array('subdirs' => 1, 'accepted_types' => array('.jpeg', '.jpg'), 'maxbytes' => $CFG->maxbytes,
        'maxfiles' => -1, 'changeformat' => 1, 'context' => $context, 'noclean' => 1, 'trusttext' => 0);
}

/**
 * Formats activity intro text
 *
 * @param string $module name of module
 * @param object $activity instance of activity
 * @param int $cmid course module id
 * @param bool $filter filter resulting html text
 * @return string
 */
function maici_format_instructions($moduleinstance, $cmid, $filter=true) {
    global $CFG;
    require_once("$CFG->libdir/filelib.php");
    $context = context_module::instance($cmid);
    $options = array('noclean' => true, 'para' => false, 'filter' => $filter, 'context' => $context, 'overflowdiv' => true);
    $instructions = file_rewrite_pluginfile_urls($moduleinstance->instructions_submit, 'pluginfile.php', $context->id, 'mod_maici', 'intro', null);
    return trim(format_text($instructions, $moduleinstance->instructionsformat, $options, null));
}

/**
 * Sanitize string for use as filename
 *
 * @param $string
 * @return void
 */
function maici_sanitize_filename(&$string) {
    $unwanted_array =
        array( "/" => "_",
            'Å' => 'A', 'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E',
            'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O',
            'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U','Ť'=>'T',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a', 'á' => 'a', 'â' => 'a',
            'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'o',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u','ü' => 'u', 'û' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y', " " => "_",
            'Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A');
    $string = strtr($string, $unwanted_array);
}

function maici_count_tokens($text) {
    // Define a basic set of tokenization rules
    $pattern = '/\w+|\s+|[^\w\s]/u';
    preg_match_all($pattern, $text, $matches);

    // Filter out empty elements
    $tokens = array_filter($matches[0]);

    // Count the tokens
    $tokenCount = count($tokens);

    return $tokenCount;
}

function maici_get_assistant_token_usage($message,$completion_message) {
    $usage = new stdClass();
    $usage->prompt_tokens = maici_count_tokens($message);
    $usage->completion_tokens = maici_count_tokens($completion_message);
    $usage->total_tokens = $usage->prompt_tokens + $usage->completion_tokens;
    return $usage;
}

function maici_get_chat_token_usage($usage, $instructiontokens) {

    if($usage->prompt_tokens < $instructiontokens){
        $usage->prompt_tokens = 0;
    }else{
        $usage->prompt_tokens -= $instructiontokens;
    }

    if($usage->total_tokens < $instructiontokens){
        $usage->total_tokens = 0;
    }else{
        $usage->total_tokens -= $instructiontokens;
    }

    return $usage;
}
