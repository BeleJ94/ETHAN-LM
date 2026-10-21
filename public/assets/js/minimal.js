/* ETHAN LM — gabarit minimal (connexion, erreurs). */
(function () {
    'use strict';
    document.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-toggle-password]');
        if (!btn) return;
        const input = document.querySelector(btn.dataset.togglePassword);
        input.type = input.type === 'password' ? 'text' : 'password';
    });
    // Avertissement « Verr. Maj activé » sur le mot de passe
    const caps = document.querySelector('[data-caps]');
    const pwd = document.getElementById('password');
    if (caps && pwd) {
        const check = (ev) => { if (ev.getModifierState) caps.hidden = !ev.getModifierState('CapsLock'); };
        pwd.addEventListener('keydown', check);
        pwd.addEventListener('keyup', check);
        pwd.addEventListener('blur', () => { caps.hidden = true; });
    }
    // Bouton en cours d'envoi : évite le double clic
    document.addEventListener('submit', (ev) => {
        const btn = ev.target.querySelector('[data-login-submit]');
        if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
    });
    if (window.lucide) window.lucide.createIcons();
})();
