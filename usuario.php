<?php

require_once("../config.php");
if (isloggedin()) {
    global $USER, $DB;

    $roles_permitidos = array(
        "manager",
        "coursecreator",
        "editingteacher",
        "teacher"
    );

    $consulta = "
        SELECT r.shortname FROM mdl_user u 
        JOIN mdl_role_assignments ra ON u.id = ra.userid 
        JOIN mdl_role r ON ra.roleid = r.id
        WHERE ra.userid = ?
    ";
    $role = $DB->get_field_sql($consulta, array($USER->id));
    if (in_array($role, $roles_permitidos)) {
        echo json_encode(array('role' => $role, 'allowed' => true));
    } else {
        echo json_encode(array('role' => $role, 'allowed' => false));
    }
} else {
    echo json_encode(array('allowed' => false));
}

?>
