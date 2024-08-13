<?php
require_once ('../config.php');
require_once ('../course/lib.php');
require_once ($CFG->libdir . '/completionlib.php');
////
// Incluye las configuraciones y librerías necesarias de Moodle
require_once ('C:/Apache24/htdocs/ImpleMoodle/moodle/config.php');
require_once ($CFG->libdir . '/filelib.php');
require_once ($CFG->libdir . '/questionlib.php');
require_once ($CFG->dirroot . '/question/editlib.php');
require_once ($CFG->dirroot . '/question/type/multichoice/lib.php');

// Incluye las librerías para manejar archivos PDF y DOCX
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
require_login();


//////

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
if (!in_array($role, $roles_permitidos)) {
    redirect(new moodle_url($CFG->wwwroot."/my/courses.php"), "No tienes permisos");
}




//////
redirect_if_major_upgrade_required();

$id = optional_param('id', 0, PARAM_INT);
$name = optional_param('name', '', PARAM_TEXT);
$edit = optional_param('edit', -1, PARAM_BOOL);
$hide = optional_param('hide', 0, PARAM_INT);
$show = optional_param('show', 0, PARAM_INT);
$duplicatesection = optional_param('duplicatesection', 0, PARAM_INT);
$idnumber = optional_param('idnumber', '', PARAM_RAW);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$section = optional_param('section', 0, PARAM_INT);
$expandsection = optional_param('expandsection', -1, PARAM_INT);
$move = optional_param('move', 0, PARAM_INT);
$marker = optional_param('marker', -1, PARAM_INT);
$switchrole = optional_param('switchrole', -1, PARAM_INT); // Deprecated, use course/switchrole.php instead.
$return = optional_param('return', 0, PARAM_LOCALURL);

$params = [];
if (!empty($name)) {
    $params = ['shortname' => $name];
} else if (!empty($idnumber)) {
    $params = ['idnumber' => $idnumber];
} else if (!empty($id)) {
    $params = ['id' => $id];
} else {
    throw new \moodle_exception('unspecifycourseid', 'error');
}

$course = $DB->get_record('course', $params, '*', MUST_EXIST);

$urlparams = ['id' => $course->id];

// Sectionid should get priority over section number.   
if ($sectionid) {
    $section = $DB->get_field('course_sections', 'section', ['id' => $sectionid, 'course' => $course->id], MUST_EXIST);
}
if ($section) {
    $urlparams['section'] = $section;
}
if ($expandsection !== -1) {
    $urlparams['expandsection'] = $expandsection;
}

$PAGE->set_url('/course/view.php', $urlparams); // Defined here to avoid notices on errors etc.

// Prevent caching of this page to stop confusion when changing page after making AJAX changes.
$PAGE->set_cacheable(false);

context_helper::preload_course($course->id);
$context = context_course::instance($course->id, MUST_EXIST);

// Remove any switched roles before checking login.
if ($switchrole == 0 && confirm_sesskey()) {
    role_switch($switchrole, $context);
}

require_login($course);

// Switchrole - sanity check in cost-order...
$resetuserallowedediting = false;
if (
    $switchrole > 0 && confirm_sesskey() &&
    has_capability('moodle/role:switchroles', $context)
) {
    // Is this role assignable in this context?
    // Inquiring minds want to know.
    $aroles = get_switchable_roles($context);
    if (is_array($aroles) && isset($aroles[$switchrole])) {
        role_switch($switchrole, $context);
        // Double check that this role is allowed here.
        require_login($course);
    }
    // Reset course page state. This prevents some weird problems.
    $USER->activitycopy = false;
    $USER->activitycopycourse = null;
    unset($USER->activitycopyname);
    unset($SESSION->modform);
    $USER->editing = 0;
    $resetuserallowedediting = true;
}

// If course is hosted on an external server, redirect to corresponding
// url with appropriate authentication attached as parameter.
if (file_exists($CFG->dirroot . '/course/externservercourse.php')) {
    include ($CFG->dirroot . '/course/externservercourse.php');
    if (function_exists('extern_server_course')) {
        if ($externurl = extern_server_course($course)) {
            redirect($externurl);
        }
    }
}

require_once ($CFG->dirroot . '/calendar/lib.php'); // This is after login because it needs $USER.

// Must set layout before gettting section info. See MDL-47555.
$PAGE->set_pagelayout('course');
$PAGE->add_body_class('limitedwidth');

if ($section && $section > 0) {

    // Get section details and check it exists.
    $modinfo = get_fast_modinfo($course);
    $coursesections = $modinfo->get_section_info($section, MUST_EXIST);

    // Check user is allowed to see it.
    if (!$coursesections->uservisible) {
        // Check if coursesection has conditions affecting availability and if
        // so, output availability info.
        if ($coursesections->visible && $coursesections->availableinfo) {
            $sectionname = get_section_name($course, $coursesections);
            $message = get_string('notavailablecourse', '', $sectionname);
            redirect(course_get_url($course), $message, null, \core\output\notification::NOTIFY_ERROR);
        } else {
            // Note: We actually already know they don't have this capability
            // or uservisible would have been true; this is just to get the
            // correct error message shown.
            require_capability('moodle/course:viewhiddensections', $context);
        }
    }
}

// Fix course format if it is no longer installed.
$format = course_get_format($course);
$course->format = $format->get_format();

$PAGE->set_pagetype('course-view-' . $course->format);
$PAGE->set_other_editing_capability('moodle/course:update');
$PAGE->set_other_editing_capability('moodle/course:manageactivities');
$PAGE->set_other_editing_capability('moodle/course:activityvisibility');
if (course_format_uses_sections($course->format)) {
    $PAGE->set_other_editing_capability('moodle/course:sectionvisibility');
    $PAGE->set_other_editing_capability('moodle/course:movesections');
}

// Preload course format renderer before output starts.
// This is a little hacky but necessary since
// format.php is not included until after output starts.
$renderer = $format->get_renderer($PAGE);

if ($resetuserallowedediting) {
    // Ugly hack.
    unset($PAGE->_user_allowed_editing);
}

if (!isset($USER->editing)) {
    $USER->editing = 0;
}
if ($PAGE->user_allowed_editing()) {
    if (($edit == 1) && confirm_sesskey()) {
        $USER->editing = 1;
        // Redirect to site root if Editing is toggled on frontpage.
        if ($course->id == SITEID) {
            redirect($CFG->wwwroot . '/?redirect=0');
        } else if (!empty($return)) {
            redirect($CFG->wwwroot . $return);
        } else {
            $url = new moodle_url($PAGE->url, ['notifyeditingon' => 1]);
            redirect($url);
        }
    } else if (($edit == 0) && confirm_sesskey()) {
        $USER->editing = 0;
        if (!empty($USER->activitycopy) && $USER->activitycopycourse == $course->id) {
            $USER->activitycopy = false;
            $USER->activitycopycourse = null;
        }
        // Redirect to site root if Editing is toggled on frontpage.
        if ($course->id == SITEID) {
            redirect($CFG->wwwroot . '/?redirect=0');
        } else if (!empty($return)) {
            redirect($CFG->wwwroot . $return);
        } else {
            redirect($PAGE->url);
        }
    }

    if (has_capability('moodle/course:sectionvisibility', $context)) {
        if ($hide && confirm_sesskey()) {
            set_section_visible($course->id, $hide, '0');
            redirect($PAGE->url);
        }

        if ($show && confirm_sesskey()) {
            set_section_visible($course->id, $show, '1');
            redirect($PAGE->url);
        }
    }

    if (
        !empty($section) && !empty($coursesections) && !empty($duplicatesection)
        && has_capability('moodle/course:update', $context) && confirm_sesskey()
    ) {
        $newsection = $format->duplicate_section($coursesections);
        redirect(course_get_url($course, $newsection->section));
    }

    if (
        !empty($section) && !empty($move) &&
        has_capability('moodle/course:movesections', $context) && confirm_sesskey()
    ) {
        $destsection = $section + $move;
        if (move_section_to($course, $section, $destsection)) {
            if ($course->id == SITEID) {
                redirect($CFG->wwwroot . '/?redirect=0');
            } else {
                if ($format->get_course_display() == COURSE_DISPLAY_MULTIPAGE) {
                    redirect(course_get_url($course));
                } else {
                    redirect(course_get_url($course, $destsection));
                }
            }
        } else {
            echo $OUTPUT->notification('An error occurred while moving a section');
        }
    }
} else {
    $USER->editing = 0;
}

$SESSION->fromdiscussion = $PAGE->url->out(false);


if ($course->id == SITEID) {
    // This course is not a real course.
    redirect($CFG->wwwroot . '/?redirect=0');
}

// Determine whether the user has permission to download course content.
$candownloadcourse = \core\content::can_export_context($context, $USER);



// Add bulk editing control.
$bulkbutton = $renderer->bulk_editing_button($format);
if (!empty($bulkbutton)) {
    $PAGE->add_header_action($bulkbutton);
}

$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo html_writer::start_tag('div', ['class' => 'course-content']);

// Make sure that section 0 exists (this function will create one if it is missing).
course_create_sections_if_missing($course, 0);


$modinfo = get_fast_modinfo($course);
$modnames = get_module_types_names();
$modnamesplural = get_module_types_names(true);
$modnamesused = $modinfo->get_used_module_names();
$mods = $modinfo->get_cms();
$sections = $modinfo->get_section_info_all();


$displaysection = $section;

// Include course AJAX.
include_course_ajax($course, $modnamesused);


course_view(context_course::instance($course->id), $section);
////////////////////////////////////////////////////////////
function validate_duplicate_question($DB, $pregunta_texto, $opciones, $respuesta_correcta)
        {
            $consulta = 'SELECT COUNT(*), id FROM mdl_question WHERE name = :name GROUP BY id';
            $parametros = ["name" => $pregunta_texto];
            $duplicados = $DB->get_records_sql($consulta, $parametros);
           // echo "Duplicados: " . print_r($duplicados) . "<br>";
            foreach ($duplicados as $duplicado) {
                $id_question = $duplicado->{'id'};
                $cantidad = intval($duplicado->{'count(*)'});
            }
            $consulta_answers = 'SELECT id, question, answer FROM mdl_question_answers WHERE question = :id_question';
            $parametros_answers = ["id_question" => $id_question];
            $duplicados_answers = $DB->get_records_sql($consulta_answers, $parametros_answers);
            // echo "Duplicados respuestas: " . print_r($duplicados_answers) . "<br>";
            foreach ($duplicados_answers as $da) {
                $respuesta = $da->answer;
                $con_resp = 'SELECT COUNT(*) FROM mdl_question_answers WHERE answer = :answer';
                $parametros_con_resp = ["answer" => $respuesta];
                $duplicados_con_resp = $DB->get_records_sql($con_resp, $parametros_con_resp);
                foreach ($duplicados_con_resp as $dcr) {

                    $cantidad_resp = intval($dcr->{'count(*)'});
                    if ($cantidad_resp > 1) {
                        return true;
                    }
                }
            }
            // var_dump($cantidad);
            if ($cantidad > 0) {
                return true;
            }
            return false;

        }
//var_dump($_POST); 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_questions'])) {
    global $DB;
    // Recupera las preguntas seleccionadas del formulario
    $selected_questions = $_POST['selected_questions'];
    //echo"PREGUNTAS SELECCIONADAS: " . json_encode($selected_questions) . "<br>";

    // Separar el contenido en variables individuales
    $course_id = $_POST["course_id"];
    //echo "ID CURSO: $course_id \n";

    // echo json_encode($_POST['selected_questions']);
    $course = $DB->get_record('course', array('id' => $course_id), '*', MUST_EXIST);
    if (!$course) {
        die('El curso con el ID proporcionado no existe.');
    }

    $context_record = $DB->get_record('context', array('contextlevel' => CONTEXT_COURSE, 'instanceid' => $course_id), 'id');
    if ($context_record) {
        $context_id = $context_record->id;
      //  echo "Context ID del curso: $context_id \n";
    } else {
        die('No se encontró el contexto del curso.');
    }


    // Obtener el ID de la categoría del curso
    $category = $DB->get_record('course_categories', array('id' => $course->category), 'id');
    if ($category) {
        $category_id = $category->id;
       // echo "Category ID: $category_id";
    } else {
        die('No se encontró la categoría para el curso proporcionado.');
    }
   
    foreach ($selected_questions as $question_html) {
        // Decodifica el contenido HTML
        $question_html = urldecode($question_html);

        // Inicializa las variables
        $pregunta_texto = '';
        $opciones = [];
        $respuesta_correcta = '';
        $pregunta_pos = strpos($question_html, 'Pregunta:');
        $opcion_a_pos = strpos($question_html, 'a)');

        if ($pregunta_pos !== false && $opcion_a_pos !== false) {
            $pregunta_texto = substr($question_html, $pregunta_pos + 9, $opcion_a_pos - $pregunta_pos - 9);

            // Eliminar etiquetas HTML y espacios en blanco al inicio y al final
            $pregunta_texto = strip_tags(trim($pregunta_texto));


        } else {
            echo "No se encontró el texto de la pregunta en el contenido HTML.<br>";
        }


        // Obtener las opciones de respuesta
        if (preg_match_all('/([a-d]\))\s*(.*?)<br>/', $question_html, $matches)) {
            foreach ($matches[2] as $opcion) {
                $opciones[] = trim($opcion);
            }
        } else {
            echo "No se encontraron las opciones en el contenido HTML.<br>";
        }
       // echo $question_html;
        if (preg_match('/Respuesta correcta:\s*[a-d]\)s*(.*?)<br>/', $question_html, $matches)) {
            $respuesta_correcta = trim($matches[1]);
            // Ajustar la longitud si es necesario
            $respuesta_correcta_length = strlen($respuesta_correcta);
            if ($respuesta_correcta_length > 9) {
                $respuesta_correcta = substr($respuesta_correcta, 0, $respuesta_correcta_length - 9);
            }
        } else {
            echo "No se encontró la respuesta correcta en el contenido HTML.<br>";
        }


        array_pop($opciones);




        // Mostrar los resultados
        echo "<div class='card mb-3'>";
        echo "<div class='card-body'>";
        echo "<h3 class='card-title'>Pregunta:</h3>";
        echo "<p class='card-text'>{$pregunta_texto}</p>";

        echo "<h3 class='card-title'>Opciones:</h3>";
        echo "<ul class='list-group list-group-flush'>";
        foreach ($opciones as $opcion) {
            echo "<li class='list-group-item'>{$opcion}</li>";
        }
        echo "</ul>";

        echo "<h3 class='card-title'>Respuesta Correcta:</h3>";
        echo "<p class='card-text'>{$respuesta_correcta}</p>";

        $preguntasResp = array(
            "pregunta" => $pregunta_texto,
            "opciones" => $opciones,
            "respuesta" => $respuesta_correcta
        );
        $existe = validate_duplicate_question($DB, $pregunta_texto, $opciones, $respuesta_correcta);
        $resultado = [
            'pregunta' => $pregunta_texto,
            'opciones' => $opciones,
            'respuesta_correcta' => $respuesta_correcta,
            'estado' => $existe ? 'repetida' : 'insertada'
        ];
        
        if (validate_duplicate_question($DB, $pregunta_texto, $opciones, $respuesta_correcta)) {
            //echo "La pregunta ya existe: " . $pregunta_texto . "<br>";
            echo "<span class='badge bg-danger text-white'>ESTADO: {$resultado['estado']}</span>";
            echo "</div>";
            echo "</div>";
        } else {

            $info = json_decode($_POST["qc"], true);
            //echo "Info:". print_r($info) ."<br>";
            $id_question_bank = $info[0]["id"];
            
            // Insertar entrada en el banco de preguntas
            $entry = new stdClass();
            $entry->questioncategoryid = $id_question_bank;
            $entry->name = $pregunta_texto;

            $entry_id = $DB->insert_record('question_bank_entries', $entry);
            //var_dump($entry);

            // Insertar la pregunta en la base de datos
            $question = new stdClass();
            $question->category = $category_id;
            $question->contextid = $context_id;
            $question->name = strip_tags($pregunta_texto);
            $question->questiontext = $pregunta_texto;
            $question->questiontextformat = FORMAT_HTML;
            $question->qtype = 'multichoice'; // Tipo de pregunta: opción múltiple
            $question->generalfeedback = '';
            $question->defaultgrade = 1; // Nota por defecto
            $question->penalty = 0.1;
            $question->hidden = 0;
            $question->timecreated = time();
            $question->timemodified = time();
            $question->createdby = $USER->id; // Asignar el ID del usuario actual
            $question->modifiedby = $USER->id; // Asignar el ID del usuario actual

           // echo "Tamaño del nombre de la pregunta: " . strlen($question->name) . "<br>";

            try {
                $question_id = $DB->insert_record('question', $question);
                if (!$question_id) {
                    throw new Exception('Error al insertar la pregunta.');
                }

                // Insertar las opciones de respuesta
                foreach ($opciones as $key => $opcion) {
                    $answer = new stdClass();
                    $answer->question = $question_id;
                    $answer->answer = '<p>' . $opcion . '</p>';
                    $answer->answerformat = FORMAT_HTML;
                    $answer->fraction = ($respuesta_correcta == $opcion) ? 1 : 0; // Correcta o no
                    $answer->feedback = '';

                    try {
                        $answer_id = $DB->insert_record('question_answers', $answer);
                        if (!$answer_id) {
                            throw new Exception('Error al insertar la opción de respuesta.');
                        }
                    } catch (Exception $e) {
                        echo "Error al insertar la opción de respuesta: " . $e->getMessage() . "<br>";
                        continue;
                    }
                }

                // Insertar la versión de la pregunta
                $version = new stdClass();
                $version->questionbankentryid = $entry_id;
                $version->version = 1;
                $version->questionid = $question_id;

                $DB->insert_record('question_versions', $version);

                // Insertar en la tabla qtype_multichoice_options
                $multichoice = new stdClass();
                $multichoice->questionid = $question_id;
                $multichoice->layout = 0; // Establece el layout por defecto
                $multichoice->single = 1; // Especifica si es una sola respuesta correcta
                $multichoice->shuffleanswers = 1; // Define si las respuestas se deben mezclar
                $multichoice->correctfeedback = ''; // Feedback para la respuesta correcta
                $multichoice->correctfeedbackformat = FORMAT_HTML;
                $multichoice->partiallycorrectfeedback = ''; // Feedback para la respuesta parcialmente correcta
                $multichoice->partiallycorrectfeedbackformat = FORMAT_HTML;
                $multichoice->incorrectfeedback = ''; // Feedback para la respuesta incorrecta
                $multichoice->incorrectfeedbackformat = FORMAT_HTML;
                $multichoice->answernumbering = 'abcd'; // Define la numeración de las respuestas

                try {
                    $multichoice_id = $DB->insert_record('qtype_multichoice_options', $multichoice);
                    if (!$multichoice_id) {
                        throw new Exception('Error al insertar las opciones de elección múltiple.');
                    }
                } catch (Exception $e) {
                    echo "Error al insertar las opciones de elección múltiple: " . $e->getMessage() . "<br>";
                //header('Location:http://localhost/ImpleMoodle/moodle/course/chatgpt.php?id='.$course_id.'&status=error');
                    continue;
                }
              //  echo "Preguntas y respuestas insertadas correctamente." . $info->name;
                echo "<span class='badge bg-success text-white'>ESTADO: {$resultado['estado']}</span>";
                echo "</div>";
                echo "</div>";
            
                //header_location($CFG->wwwroot . '/course/chatgpt.php?id=2');
                //header('Location:http://localhost/ImpleMoodle/moodle/course/chatgpt.php?id='.$course_id.'&status=success'.'&nombre='.$pregunta_texto);
            } catch (Exception $e) {
                echo "Error al insertar la pregunta: " . $e->getMessage() . "<br>";
                continue;
            }
            

        }
    }

}
?>
<div class="d-flex justify-content-center">
    <a href="http://localhost/ImpleMoodle/moodle/course/view.php?id=<?php echo $course_id; ?>" class="btn btn-outline-primary">
        Volver al Curso
    </a>
</div>
<?php

///////////////////////////////////////////////////////////
echo $OUTPUT->footer();
?>

<script src="../JS/chatgpt.js"></script>