/**
 * AiConnector — settings page interactions (CSP-safe, external).
 *
 * Injected sitewide via Plugin.php (template:layout:js); a strict no-op on pages
 * without #ai-test-btn / #ai_provider. The test URL (with a reusable CSRF token)
 * and i18n strings arrive via data-* attributes.
 */
(function () {
    'use strict';

    function runTestConnection(btn) {
        var box = document.getElementById('ai-test-result');
        var url = btn.getAttribute('data-test-url');
        if (!box || !url) { return; }

        // Append the selected profile id so the endpoint tests that profile.
        var sel = document.getElementById('ai-test-profile');
        if (sel && sel.value) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'profile=' + encodeURIComponent(sel.value);
        }

        btn.disabled = true;
        box.style.display = 'none';
        box.textContent = '';

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                box.style.display = 'block';
                if (data && data.ok) {
                    box.style.background = '#d1fae5';
                    box.style.color = '#065f46';
                    box.textContent = btn.getAttribute('data-msg-ok') || 'OK';
                } else {
                    box.style.background = '#fee2e2';
                    box.style.color = '#991b1b';
                    box.textContent = (btn.getAttribute('data-msg-fail') || 'Failed:') + ' ' +
                        ((data && data.error) || btn.getAttribute('data-msg-unknown') || '');
                }
                btn.disabled = false;
            })
            .catch(function (err) {
                box.style.display = 'block';
                box.style.background = '#fee2e2';
                box.style.color = '#991b1b';
                box.textContent = (btn.getAttribute('data-msg-request-failed') || 'Request failed:') + ' ' + err.message;
                btn.disabled = false;
            });
    }

    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('#ai-test-btn') : null;
        if (t) {
            e.preventDefault();
            runTestConnection(t);
        }
    });

    // Auto-fill the model placeholder when the provider changes.
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel || sel.id !== 'ai_provider') { return; }
        var modelInput = document.getElementById('ai_model');
        if (!modelInput) { return; }
        var defaults = {};
        try { defaults = JSON.parse(sel.getAttribute('data-defaults') || '{}'); } catch (err) {}
        var def = defaults[sel.value];
        if (def && modelInput.value === modelInput.placeholder) {
            modelInput.value = def;
        }
        modelInput.placeholder = def || '';
    });
}());
