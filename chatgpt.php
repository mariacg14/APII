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
 * Display the course home page.
 *
 * @copyright 1999 Martin Dougiamas  http://dougiamas.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package core_course
 */

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

use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

////
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
    redirect(new moodle_url($CFG->wwwroot . "/my/courses.php"), "No tienes permisos");
}

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
echo '<style>
    .form-container {
        max-width: 800px;
        margin: 0 auto;
    }
    .question_container {
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 20px;
        margin: 20px 0;
        background-color: #f9f9f9;
    }
    .pregunta {
        display: flex;
        align-items: start;
    }
</style>
';


// Course wrapper start.
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
//////////////////////////////////////////////////////

$servidor = $_ENV["HOST"];
$usuario = $_ENV["USER"];
$pass = $_ENV["PASSWORD"];
$db = $_ENV["DB"];
$idUser = $_SESSION['USER']->id;
$conn = new mysqli($servidor, $usuario, $pass, $db);

// Verificar la conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// If available, include the JS to prepare the download course content modal.
if ($candownloadcourse) {
    $PAGE->requires->js_call_amd('core_course/downloadcontent', 'init');
}
$id_course = intval($_GET["id"]);
$servidor = $_ENV["HOST"];
$usuario = $_ENV["USER"];
$pass = $_ENV["PASSWORD"];
$db = $_ENV["DB"];
$conn = new PDO("mysql:host=$servidor;dbname=$db", $usuario, $pass);
$consulta = $conn->prepare("SELECT * FROM mdl_course_modules cm JOIN mdl_modules m ON cm.module = m.id
JOIN mdl_context c ON c.instanceid = cm.id AND c.contextlevel=70
JOIN mdl_files f ON f.contextid = c.id
WHERE f.filesize != 0 AND cm.course = :id_course AND
(f.filename LIKE '%.pdf' OR f.filename LIKE '%.docx' OR f.filename LIKE '%.txt' )
");
$consulta->bindParam(":id_course", $id_course, PDO::PARAM_INT);
$consulta->execute();
$resultados = json_encode($consulta->fetchAll(PDO::FETCH_ASSOC));
//
?>

<div class="container mt-5">
    <div class="card">
        <div class="card-header">
            <h2 id="titulo">Selección de Temas</h2>

        </div>
        <div class="card-body" id="cardBody">

            <form id="formGenerarPreguntas" method="post">
                <div class="mb-3">
                    <?php

                    $datos = json_decode($resultados);
                    foreach ($datos as $index => $archivo) {
                        echo '<div class="form-check">';
                        echo '<input class="form-check-input" type="radio" name="archivo" id="archivo' . $index . '" value="' . htmlspecialchars(json_encode($archivo)) . '" required>';
                        echo '<label class="form-check-label" for="archivo' . $index . '">' . $archivo->filename . '</label>';
                        echo '</div>';

                    }

                    ?>
                </div>
                <div class="mb-3">
                    <label for="numero_preguntas" class="form-label">
                        Cantidad de Preguntas:
                    </label>
                    <input type="number" name="numero_preguntas" id="numero_preguntas" required min="1" max="5"
                        class="form-control " value="3">
                </div>
                <hr>
                <div class="d-flex justify-content-center"> 

                    <button type="submit" class="btn btn-outline-primary">Generar Preguntas</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["archivo"])) {
        //echo $_POST["numero_preguntas"];
         
        $archivo = json_decode($_POST["archivo"]);
        $n = intval($_POST["numero_preguntas"]);
        echo "<script>";
        echo "const cardBody = document.getElementById('cardBody');";
        echo "cardBody.style.display= 'none'";
        echo "</script>";
        if ($n < 1 || $n > 5) {
            die('Número de preguntas inválido.');
        }
        $course = intval($archivo->course);
        $contextid = intval($archivo->contextid);
        $component = $archivo->component;
        $filearea = $archivo->filearea;
        $itemid = intval($archivo->itemid);
        $filepath = '/';
        $filename = $archivo->filename;

        $fs = get_file_storage();
        $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

        if (!$file) {
            die('El archivo no existe');
        }

        $contents = $file->get_content();
        $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
        function extract_text_from_pdf($contents)
        {
            $parser = new Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($contents);
            $text = $pdf->getText();
            return $text;
        }

        // Función para extraer texto de un archivo DOCX utilizando PhpOffice\PhpWord
        function extract_text_from_docx($contents)
        {
            // Crear un archivo temporal para manejar el contenido
            $temp_file = tempnam(sys_get_temp_dir(), 'docx');
            file_put_contents($temp_file, $contents);

            // Inicializar PhpWord y cargar el contenido del archivo DOCX
            $phpWord = IOFactory::load($temp_file);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . " ";
                    }
                }
            }

            // Eliminar el archivo temporal
            unlink($temp_file);

            //echo $text;
            return $text;
        }



        // Función para extraer texto de un archivo TXT
        function extract_text_from_txt($contents)
        {
            // No se necesita ninguna librería para manejar archivos de texto plano
            return $contents;
        }
        switch (strtolower($file_extension)) {
            case 'pdf':
                $text = extract_text_from_pdf($contents);
                break;
            case 'docx':
                $text = extract_text_from_docx($contents);
                break;
            case 'txt':
                $text = extract_text_from_txt($contents);
                break;
            default:
                die('Tipo de archivo no soportado');
        }

        $api_chatgpt = $_ENV["API_CHATGPT"];
        $mensaje = "Genera " . $n . " preguntas tipo test a partir de este contenido con el siguiente formato exacto: 
            1. Pregunta: ¿Texto de la pregunta?
            a) Opción 1
            b) Opción 2
            c) Opción 3
            d) Opción 4
            Respuesta correcta:
    
    Contenido: " . $text;
        $caFile = "C:/Apache24/cacert.pem";
        $request_body = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $mensaje
                ]
            ],
            'temperature' => 0.7,
        ];
        $json_request_body = json_encode($request_body);

        if ($json_request_body === false) {
            die('Error al codificar JSON: ' . json_last_error_msg());
        }

        // Realiza la solicitud a la API de OpenAI
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_request_body);
        curl_setopt($ch, CURLOPT_CAINFO, $caFile);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_chatgpt,
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            //echo "Respuesta completa de la API: " . $result;
        }

        curl_close($ch);

        $respuesta = json_decode($result, true);
        //echo json_encode($respuesta);
        if (isset($respuesta['choices'][0]['message']['content'])) {
            $output = $respuesta['choices'][0]['message']['content'];
        } else {
            echo 'No se recibieron respuestas de la API. Respuesta decodificada: ';
        }

        global $DB, $USER;

        // echo $_POST["archivo"];

        // Obtener el contexto actual
        $context = context_system::instance(); // O usa context_course::instance() si estás en una página del curso



        echo "<form action='preguntas.php?id=" . $id_course . "' id='formEnviarPreguntas' method='post' class='form-container'>";

        echo "<div class='question_container'>";

        $preguntas = explode("\n", trim($output));
        $numero_pregunta = 1;
        $pregunta_actual = '';
        $id_pregunta = 0;
        foreach ($preguntas as $linea) {
            if (preg_match('/^\d+\.\s*(.*)/', $linea, $matches)) {
                if ($pregunta_actual) {
                    echo "<div style='display:flex;align-items:start'>";
                    // Usa un ID único para cada pregunta
                    $id_pregunta++;
                    echo "<input style='margin-top:1vh;margin-right=1vw' type='checkbox' name='selected_questions[]' value='" . htmlspecialchars($pregunta_actual) . "' id='pregunta$id_pregunta'>";
                    echo "<label for='pregunta$id_pregunta'>{$pregunta_actual}</label><br>";
                    echo "</div>";
                }
                $pregunta_actual = "<strong>Pregunta {$numero_pregunta}:</strong> {$matches[1]}<br>";
                $numero_pregunta++;
            } elseif (preg_match('/^[a-d]\)\s*(.*)/', $linea)) {
                $pregunta_actual .= "{$linea}<br>";
            } elseif (strpos($linea, 'Respuesta correcta:') !== false) {
                $pregunta_actual .= "<strong>{$linea}</strong><br><br>";
            }
        }

        if ($pregunta_actual) {
            echo "<div style='display:flex;align-items:start'>";
            $id_pregunta++;
            echo "<input style='margin-top:1vh;margin-right=1vw' type='checkbox' name='selected_questions[]' value='" . htmlspecialchars($pregunta_actual) . "' id='pregunta$id_pregunta'>";
            echo "<label for='pregunta$id_pregunta'>{$pregunta_actual}</label><br>";
            echo "</div>";
        }

        $servidor = $_ENV["HOST"];
        $usuario = $_ENV["USER"];
        $pass = $_ENV["PASSWORD"];
        $db = $_ENV["DB"];
        $conn = new PDO("mysql:host=$servidor;dbname=$db", $usuario, $pass);

        $consulta_preguntas = $conn->prepare("SELECT shortname FROM mdl_course WHERE id = :id_course");
        $consulta_preguntas->bindParam(":id_course", $course, PDO::PARAM_INT);
        $consulta_preguntas->execute();
        $resultados_preguntas = $consulta_preguntas->fetchAll(PDO::FETCH_ASSOC);
        $shortname = $resultados_preguntas[0]["shortname"];
        //echo $shortname;
        $consulta_question_categories = $conn->prepare("SELECT * FROM mdl_question_categories WHERE name = 'Por defecto en " . $shortname . "'");
        $consulta_question_categories->execute();
        $resultados_question_categories = json_encode($consulta_question_categories->fetchAll(PDO::FETCH_ASSOC));
        // echo $resultados_question_categories;
        echo "</div>";
        ?>
        <input type="hidden" name="qc" value="<?php echo htmlspecialchars($resultados_question_categories); ?> ">
        <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course); ?> ">
        <div class="d-flex justify-content-center"> 

                    <button type="submit" class="btn btn-outline-primary">Enviar Preguntas Generadas</button>
        </div>
        </form>
        <?php
        echo "<script>
            document.getElementById('formGenerarPreguntas').style.display = 'none';
            document.getElementById('formEnviarPreguntas').style.display = 'block';
            document.getElementById('titulo').innerText = 'Preguntas Generadas';
        </script>";
    }
}



?>

<?php
echo $OUTPUT->footer();
?>
<script src="../JS/chatgpt.js"></script>
<script src="../JS/formulario.js"></script>