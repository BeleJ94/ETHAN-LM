/* ETHAN LM — documents imprimables. */
(function () {
    'use strict';
    // Affiché dans l'aperçu en modale : la barre d'outils de la modale remplace celle de la page.
    if (window.self !== window.top) {
        document.documentElement.classList.add('is-embedded');
    }
    document.addEventListener('click', (ev) => {
        if (ev.target.closest('[data-print]')) window.print();
    });
})();
