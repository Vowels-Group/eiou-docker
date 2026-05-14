// hello-eiou plugin — Plugins-tab panel JS.
//
// Wires up the "Show me a fortune" button: POSTs the plugin's
// `helloEiouFortune` gui_action to /gui/index.html, parses the
// response, and drops the fortune into the result div above.
// Loaded via `gui_assets: [{type:"js", path:"assets/script.js"}]`;
// PluginAssetRegistry emits it with the page's CSP nonce so the
// script runs without inline-script policy headaches.

(function () {
    'use strict';

    function csrfToken() {
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function showFortune(text) {
        var out = document.getElementById('plugin-hello-eiou-fortune-output');
        if (!out) return;
        out.textContent = text;
        // Replay the fade-in by toggling the class — re-applying it on
        // an element that already carries it has no effect without the
        // toggle.
        out.classList.remove('plugin-hello-eiou-fortune-fade');
        // Force a reflow so the removal is observed before the re-add.
        void out.offsetWidth;
        out.classList.add('plugin-hello-eiou-fortune-fade');
    }

    function showError(text) {
        var out = document.getElementById('plugin-hello-eiou-fortune-output');
        if (!out) return;
        out.textContent = text || 'Could not fetch a fortune. Try again?';
    }

    function requestFortune(btn) {
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        var originalLabel = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reading the tea leaves…';

        var body = 'action=helloEiouFortune'
                 + '&csrf_token=' + encodeURIComponent(csrfToken());

        // window.location.pathname rather than a hardcoded URL — keeps
        // the call working under the Tor onion path (/gui/index.html)
        // and any future host-side path remap. Same convention the
        // host's post() helper uses.
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.pathname, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            btn.disabled = false;
            btn.innerHTML = originalLabel;
            var data = null;
            try { data = JSON.parse(xhr.responseText); }
            catch (e) { data = null; }
            if (data && data.success && data.fortune) {
                showFortune(data.fortune);
            } else if (data && data.error) {
                showError(data.error.message || data.error.code || 'Error');
            } else if (xhr.status >= 200 && xhr.status < 300) {
                showError('Empty response from helloEiouFortune.');
            } else {
                showError('Request failed (HTTP ' + xhr.status + ').');
            }
        };
        xhr.send(body);
    }

    function init() {
        var btn = document.getElementById('plugin-hello-eiou-fortune-btn');
        if (!btn) return;
        // Prevent duplicate bindings if this script ever runs twice
        // (e.g. a future host change that re-emits asset includes).
        if (btn.getAttribute('data-hello-eiou-bound') === '1') return;
        btn.setAttribute('data-hello-eiou-bound', '1');
        btn.addEventListener('click', function () { requestFortune(btn); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
