document.addEventListener("DOMContentLoaded", function() {
    console.log("removeERR.js");
    const answers = document.querySelectorAll(".answer");
    answers.forEach(respuesta => {
        Array.from(respuesta.children).forEach(child => {
            const span = child.querySelector("span.answernumber");
            if (span) {
                span.remove();
            }
            console.log(child.innerHTML);
        });
    });

});
