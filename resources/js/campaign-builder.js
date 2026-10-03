/**
 * The campaign builder's live checks.
 *
 * Changing a template, list or transport re-reads the page with those selections
 * in the query string, so the preflight panel and the audience figures are answered
 * for what is actually selected rather than for whatever was there when the page
 * loaded. The form still posts normally with JavaScript disabled: this only makes
 * the checks live, and the start action re-runs the same checks server-side either
 * way, so nothing here decides whether a campaign may send.
 */
const form = document.querySelector('[data-campaign-refresh]');

if (form) {
    const refresh = () => {
        const params = new URLSearchParams();

        for (const [name, value] of new FormData(form).entries()) {
            if (typeof value === 'string' && value !== '') {
                params.set(name, value);
            }
        }

        window.location.search = params.toString();
    };

    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', refresh);
    });
}