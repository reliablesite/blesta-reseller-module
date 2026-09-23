/* Dedicated document only: mount once; the provider owns console lifecycle. */
(async function () {
    'use strict';
    const config = JSON.parse(document.getElementById('kvm-config').textContent);
    const status = document.getElementById('kvm-status');
    const message = document.getElementById('kvm-message');
    const retry = document.getElementById('kvm-retry');
    retry.addEventListener('click', function () { location.reload(); });
    if (!config) { return; }
    function fail(text) {
        message.textContent = text;
        status.hidden = false;
        retry.hidden = false;
    }
    try {
        const response = await fetch(location.href, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
            body: new URLSearchParams({action: 'instant_kvm_launch', _csrf_token: config.csrf, nonce: config.nonce}).toString()
        });
        if (response.status === 429) {
            fail('Please wait 10 seconds before requesting a fresh launch.');
            return;
        }
        if (!response.ok || !/^application\/json(?:\s*;|$)/i.test(response.headers.get('Content-Type') || '')) {
            throw new Error('Launch failed');
        }
        const data = await response.json();
        if (typeof data.JavascriptUrl !== 'string' || /[\s\\\u0000-\u001f\u007f]/.test(data.JavascriptUrl)) {
            throw new Error('Invalid script');
        }
        const url = new URL(data.JavascriptUrl);
        if (url.protocol !== 'https:' || !config.origins.includes(url.origin) || url.username || url.password
            || url.hash || url.pathname !== '/embed/v1/kvm.js') {
            throw new Error('Unapproved script');
        }
        message.textContent = 'Loading the provider console…';
        const script = document.createElement('script');
        script.src = data.JavascriptUrl; // Preserve the provider's exact URL and query.
        script.dataset.target = 'instant-kvm';
        script.referrerPolicy = 'no-referrer';
        script.onerror = function () { fail('Could not load the provider console. Request a fresh launch to try again.'); };
        script.onload = function () { status.hidden = true; };
        document.body.appendChild(script);
    } catch (error) {
        // Never expose/log upstream errors, request details or credential-bearing URLs.
        fail('Could not start Instant KVM. Check your access and browser IP configuration, then request a fresh launch.');
    }
}());
