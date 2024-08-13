document.addEventListener("DOMContentLoaded", function () {
    console.log("chatgpt.js");

        
    const urlParams = new URLSearchParams(window.location.search);
    const id_course = urlParams.get('id') || urlParams.get("courseid");
    const enlaceHtml = `
    <li data-key="editsettings" class="nav-item" role="none" data-forceintomoremenu="false">
       <a role="menuitem" class="nav-link chatgptEnlace" href="http://localhost/ImpleMoodle/moodle/API/chatgpt.php?id=${id_course}" tabindex="-1">
            ChatGPT
        </a>
    </li>
    `;
    const rutas = [
        "/moodle/course",
        "moodle/user",
        "/moodle/grade/report/grader",
        "/moodle/report",
        "/moodle/question",
        "/moodle/contentbank",
        "/moodle/badges",
        "/moodle/admin/tool/lp",
        "/moodle/filter",
        "/moodle/API",
    ];

    fetch('http://localhost/ImpleMoodle/moodle/API/usuario.php')
        .then(response => response.json())
        .then(data => {
            console.log(data);
            if (data.allowed) {
                for (const ruta of rutas) {
                    if (window.location.pathname.includes(ruta)) {
                        const more = document.querySelector(".nav-tabs");

                        if (more) {
                            const contenedorTemporal = document.createElement('div');
                            contenedorTemporal.innerHTML = enlaceHtml;
                            const nuevoNodo = contenedorTemporal.firstElementChild;

                            if (nuevoNodo) {
                                more.appendChild(nuevoNodo);
                            } else {
                                console.error("El nuevo nodo no se creó correctamente.");
                            }
                        } else {
                            console.error("No se encontró el elemento con la clase 'nav-tabs'.");
                        }

                        if (this.location.href.includes('chatgpt') || this.location.href.includes('preguntas')) {
                            const enlaceCurso = Array.from(document.querySelectorAll("a.nav-link")).find(link => link.innerText === "Curso");
                            enlaceCurso.classList.remove("active");
                            enlaceCurso.classList.remove("active_tree_node");

                            document.querySelector(".chatgptEnlace").classList.add("active");
                            document.querySelector(".chatgptEnlace").classList.add("active_tree_node");
                        }
                    }
                }
            } else {
                console.error("El usuario no tiene un rol permitido.");
            }
        })
        .catch(error => console.error('Error al obtener el rol del usuario:', error));
});
