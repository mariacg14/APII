document.addEventListener("DOMContentLoaded", function() {
    const formGenerarPreguntas = document.getElementById("formGenerarPreguntas");
    if (formGenerarPreguntas) {
        formGenerarPreguntas.addEventListener("submit", function() {
            
            formGenerarPreguntas.style.display="none";
        });
    }
});