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

/**
 * A scheduled task for scripted database integrations.
 *
 * @package    local_catmembers
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catmembers\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catmembers extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_catmembers');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;

        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();

        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $table = get_string('extdbtable', 'local_catmembers');

        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$table) {
            echo 'Assessments Table not defined.<br>';
            return 0;
        } else {
            echo 'Assessments Table: ' . $table . '<br>';
        }
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        /* Get array of Role IDs.
         * ---------------------- */
        $roleslist = array();
        $roleslist = $DB->get_records('role');
        $roles = array();
        foreach ($roleslist as $r) {
            $roles[$r->shortname] = $r->id;
        }

        /* Get array of Category IDs.
         * -------------------------- */
        $categories = array();
        $categories = $DB->get_records('course_categories');
        $catid = array();
        $subcommcats = array();
        $subcommidnum = array();
        foreach ($categories as $category) {
            $catid[$category->idnumber] = $category->id;  // Get ALL categories.
            // Ignore if not a UOG managed overarching level.
            if (strpos($category->idnumber, 'SUB-') === false ||
                strpos($category->idnumber, 'SUB-') > 0 ||
                strpos($category->idnumber, 'SCH-') === false ||
                strpos($category->idnumber, 'SCH-') > 0 ||
                strpos($category->idnumber, 'DOM-') === false ||
                strpos($category->idnumber, 'DOM-') > 0 ||
                strpos($category->idnumber, 'FAC-') === false ||
                strpos($category->idnumber, 'FAC-') > 0 ) {
                continue;
            }
            $subcommcats[$category->idnumber] = $category->id;  // Get Subject Communities ids.
            $subcommidnum[$category->id] = $category->idnumber;  // Get Subject Community idnumbers.
        }

        /* Get array of contexts for categories.
         * ------------------------------------- */
        $contexts = array();
        $sql = 'SELECT * FROM {context} WHERE contextlevel = ' . CONTEXT_COURSECAT;
        $contexts = $DB->get_records_sql($sql);
        $catcontext = array();
        $subjcommcontext = array();
        foreach ($contexts as $context) {
            $catcontext[$context->instanceid] = $context->id; // Get context for ALL categories.
            if (in_array($context->instanceid, $subcommcats)) { // Get context for Subject Communities.
                $subjcommcontext[$context->instanceid] = $context->id;
            }
        }

        /*
         * Get Academic Leads from usr_data_categorymembers.
         */

        // Read data from table.
        $sql = $externaldb->db_get_sql($table, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $aclead = $fields; // Switch to use readable variable name from template name.

                    // Get user details primarily user->id.
                    $useridnumber = $aclead['staffnumber'];
                    $sqluser = "SELECT * FROM {user} WHERE username = '".$useridnumber."'";
                    $userid = '';
                    if ($DB->get_record_sql($sqluser)) {
                        $userid = $DB->get_record_sql($sqluser);  // User ID.

                        // Check that user is not marked as deleted.
                        if ($userid->deleted == 1) {
                            continue;
                        }
                    }

                    // Get role details primarily role->id.
                    $role = $aclead['role'];
                    $roleid = '';
                    if ($roles[$role]) {
                        $roleid = $roles[$role];  // Role ID.
                    }

                    // Get category details primarily category->context->id.
                    $catidnumber = $aclead['category_idnumber'];
                    $categoryid = '';
                    $catcontextid = '';
                    if ($catid[$catidnumber]) {
                        $categoryid = $catid[$catidnumber];
                        $catcontextid = $catcontext[$categoryid];  // Context ID.
                    }

                    // Set role assignment for user->id on category->context->id with role->id if doesn't exist.
                    if ($userid !== '' && $roleid !== '' && $catcontextid !== '') {
                        if (!$DB->get_record('role_assignments',
                            array('roleid' => $roleid, 'userid' => $userid->id, 'contextid' => $catcontextid))) {
                            role_assign($roleid, $userid->id, $catcontextid);
                            echo 'Role assigned: RoleID - '.$roleid.' :UserID - '.
                                $userid->id.' :CategoryContextID - '.$catcontextid.'<br>';
                        }
                    }

                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }

        // Free memory.
        $extdb->Close();

    }

}
