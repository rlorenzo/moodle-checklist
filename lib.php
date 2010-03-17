<?php 

/**
 * Library of functions and constants for module checklist
 * This file should have two well differenced parts:
 *   - All the core Moodle functions, neeeded to allow
 *     the module to work integrated in Moodle.
 *   - All the checklist specific functions, needed
 *     to implement all the module logic. Please, note
 *     that, if the module become complex and this lib
 *     grows a lot, it's HIGHLY recommended to move all
 *     these module specific functions to a new php file,
 *     called "locallib.php" (see forum, quiz...). This will
 *     help to save some memory when Moodle is performing
 *     actions across all modules.
 */

define("CHECKLIST_TEACHERMARK_NO", 2); 
define("CHECKLIST_TEACHERMARK_YES", 1);
define("CHECKLIST_TEACHERMARK_UNDECIDED", 0);

define("CHECKLIST_MARKING_STUDENT", 0);
define("CHECKLIST_MARKING_TEACHER", 1);
define("CHECKLIST_MARKING_BOTH", 2);

define("CHECKLIST_MAX_INDENT", 10);

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $checklist An object from the form in mod_form.php
 * @return int The id of the newly inserted checklist record
 */
function checklist_add_instance($checklist) {

    $checklist->timecreated = time();
    $returnid = insert_record('checklist', $checklist);

    $checklist = stripslashes_recursive($checklist);
    checklist_grade_item_update($checklist);

    return $returnid;
}


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $checklist An object from the form in mod_form.php
 * @return boolean Success/Fail
 */
function checklist_update_instance($checklist) {

    $checklist->timemodified = time();
    $checklist->id = $checklist->instance;

    $returnid = update_record('checklist', $checklist);

    $checklist = stripslashes_recursive($checklist);
    checklist_grade_item_update($checklist);

    return $returnid;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function checklist_delete_instance($id) {

    if (! $checklist = get_record('checklist', 'id', $id)) {
        return false;
    }

    $result = true;

    $items = get_records('checklist_item', 'checklist', $checklist->id, '', 'id');
    if ($items) {
        $items = implode(',',array_keys($items));
        $result  = delete_records_select('checklist_check', 'item IN ('.$items.')');

        if ($result) {
            $result = delete_records('checklist_item', 'checklist', $checklist->id);
        }
    }
    if ($result && !delete_records('checklist', 'id', $checklist->id)) {
        $result = false;
    }

    checklist_grade_item_delete($checklist);

    return $result;
}

function checklist_update_all_grades() {
    $checklists = get_records('checklist');
    if ($checklists) {
        foreach ($checklists as $checklist) {
            checklist_update_grades($checklist);
        }
    }
}

function checklist_update_grades($checklist, $userid=0) {
    global $CFG;

    if ($CFG->version < 2007101500) {
        // No gradelib for pre 1.9
        return;
    }

    $items = get_records_select('checklist_item',"checklist = $checklist->id AND userid = 0 AND itemoptional = 0", ''. 'id');
    if ($userid) {
        $users = $userid;
    } else {
        if (!$course = get_record('course', 'id', $checklist->course)) {
            return;
        }
        if (!$cm = get_coursemodule_from_instance('checklist', $checklist->id, $course->id)) {
            return;
        }
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
        if (!$users = get_users_by_capability($context, 'mod/checklist:updateown', 'u.id', '', '', '', '', '', false)) {
            return;
        }
        $users = implode(',',array_keys($users));
    }
    
    $total = count($items);
    $itemlist = implode(',',array_keys($items));
    if ($checklist->teacheredit == CHECKLIST_MARKING_STUDENT) {
        $date = ', MAX(c.usertimestamp) AS datesubmitted';
        $where = 'c.usertimestamp > 0';
    } else {
        $date = ', MAX(c.teachertimestamp) AS dategraded';
        $where = 'c.teachermark = '.CHECKLIST_TEACHERMARK_YES;
    }

    $sql = 'SELECT u.id AS userid, (SUM(CASE WHEN '.$where.' THEN 1 ELSE 0 END) * 100 / '.$total.') AS rawgrade'.$date;
    $sql .= " FROM {$CFG->prefix}user u LEFT JOIN {$CFG->prefix}checklist_check c ON u.id = c.userid";
    $sql .= " WHERE c.item IN ($itemlist)";
    $sql .= ' AND u.id IN ('.$users.')';
    $sql .= ' GROUP BY u.id';

    $grades = get_records_sql($sql);

    checklist_grade_item_update($checklist, $grades);
}

function checklist_grade_item_delete($checklist) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');
    if (!isset($checklist->courseid)) {
        $checklist->courseid = $checklist->course;
    }

    return grade_update('mod/checklist', $checklist->courseid, 'mod', 'checklist', $checklist->id, 0, NULL, array('deleted'=>1));
}

function checklist_grade_item_update($checklist, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    if (!isset($checklist->courseid)) {
        $checklist->courseid = $checklist->course;
    }

    $params = array('itemname'=>$checklist->name);
    $params['gradetype'] = GRADE_TYPE_VALUE;
    $params['grademax']  = 100;
    $params['grademin']  = 0;

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/checklist', $checklist->courseid, 'mod', 'checklist', $checklist->id, 0, $grades, $params);
}


/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @return null
 * @todo Finish documenting this function
 */
function checklist_user_outline($course, $user, $mod, $newmodule) {
    return $return;
}


/**
 * Print a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @return boolean
 * @todo Finish documenting this function
 */
function checklist_user_complete($course, $user, $mod, $newmodule) {
    return true;
}


/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in newmodule activities and print it out.
 * Return true if there was output, or false is there was none.
 *
 * @return boolean
 * @todo Finish documenting this function
 */
function checklist_print_recent_activity($course, $isteacher, $timestart) {
    return false;  //  True if anything was printed, otherwise false
}


/**
 * Function to be run periodically according to the moodle cron
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * @return boolean
 * @todo Finish documenting this function
 **/
function checklist_cron () {
    return true;
}


/**
 * Must return an array of user records (all data) who are participants
 * for a given instance of newmodule. Must include every user involved
 * in the instance, independient of his role (student, teacher, admin...)
 * See other modules as example.
 *
 * @param int $newmoduleid ID of an instance of this module
 * @return mixed boolean/array of students
 */
function checklist_get_participants($checklistid) {
    global $CFG;

    $sql = "SELECT DISTINCT u.id, u.id FROM {$CFG->prefix}user u, {$CFG->prefix}checklist_item i, {$CFG->prefix}checklist_check c ";
    $sql .= "WHERE i.checklist = '$checklistid' AND ((c.item = i.id AND c.userid = u.id) OR (i.userid = u.id))";

    $return = get_records_sql($sql);
    
    return $return;
}


/**
 * This function returns if a scale is being used by one checklist
 * if it has support for grading and scales. Commented code should be
 * modified if necessary. See forum, glossary or journal modules
 * as reference.
 *
 * @param int $newmoduleid ID of an instance of this module
 * @return mixed
 * @todo Finish documenting this function
 */
function checklist_scale_used($checklistid, $scaleid) {
    return false;
}


/**
 * Checks if scale is being used by any instance of checklist.
 * This function was added in 1.9
 *
 * This is used to find out if scale used anywhere
 * @param $scaleid int
 * @return boolean True if the scale is used by any checklist
 */
function checklist_scale_used_anywhere($scaleid) {
    return false;
}


/**
 * Execute post-install custom actions for the module
 * This function was added in 1.9
 *
 * @return boolean true if success, false on error
 */
function checklist_install() {
    return true;
}


/**
 * Execute post-uninstall custom actions for the module
 * This function was added in 1.9
 *
 * @return boolean true if success, false on error
 */
function checklist_uninstall() {
    return true;
}

function checklist_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'checklistheader', get_string('modulenameplural', 'checklist'));
    $mform->addElement('checkbox', 'reset_checklist_progress', get_string('resetchecklistprogress','checklist'));
}

function checklist_reset_course_form_defaults($course) {
    return array('reset_checklist_progress' => 1);
}

function checklist_reset_userdata($data) {
    global $CFG;

    $status = array();
    $component = get_string('modulenameplural', 'checklist');
    $typestr = get_string('resetchecklistprogress', 'checklist');
    $status[] = array('component'=>$component, 'item'=>$typestr, 'error'=>false);

    if (!empty($data->reset_checklist_progress)) {
        $checklists = get_records('checklist', 'course', $data->courseid);
        if (!$checklists) {
            return $status;
        }
        $checklistkeys = implode(',',array_keys($checklists));
        $items = get_records_select('checklist_item', 'checklist IN ('.$checklistkeys.')');
        if (!$items) {
            return $status;
        }
        $items = implode(',',array_keys($items));
        
        delete_records_select('checklist_check', 'item IN ('.$items.')');

        $sql = 'checklist IN ('.$checklistkeys.') AND userid != 0';
        delete_records_select('checklist_item', $sql);

        // Reset the grades
        foreach ($checklists as $checklist) {
            checklist_grade_item_update($checklist, 'reset');            
        }
    }

    return $status;
}

?>
