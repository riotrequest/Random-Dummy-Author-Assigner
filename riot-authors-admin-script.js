/* Native forms are the source of truth; this only prevents accidental double submissions. */
(() => {
    'use strict';
    const app = document.getElementById('riot-authors-app');
    if (!app) return;
    app.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting = 'yes';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.dataset.original = button.textContent;
            button.disabled = true;
            button.textContent = 'Saving…';
        });
    });
    window.addEventListener('pageshow', () => {
        app.querySelectorAll('form[data-submitting]').forEach((form) => {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[data-original]').forEach((button) => {
                button.textContent = button.dataset.original;
                button.disabled = false;
            });
        });
    });
})();
