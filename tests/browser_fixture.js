// Inject BEFORE navigation using CDP Page.addScriptToEvaluateOnNewDocument.
// This is a synthetic renderer, not a provider implementation.
(() => {
    if (location.origin !== 'http://127.0.0.1:8765' || location.pathname !== '/console') return;
    const state = new URLSearchParams(location.search).get('state') || 'mounted';
    window.__kvmQa = {state, requests: 0, scripts: 0};
    window.fetch = async (url, options) => {
        if (new URL(url, location.href).origin !== location.origin) throw new Error('Fixture blocks external requests');
        window.__kvmQa.requests++;
        if (options.method !== 'POST') throw new Error('Unexpected method');
        if (state === 'loading') return new Promise(() => {});
        if (state === 'error') return new Response(JSON.stringify({error: 'SYNTHETIC failure'}), {status:502, headers:{'Content-Type':'application/json'}});
        return new Response(JSON.stringify({JavascriptUrl:'https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js?token=SYNTHETIC-NEVER-REQUESTED'}), {status:200,headers:{'Content-Type':'application/json'}});
    };
    const append = Node.prototype.appendChild;
    Node.prototype.appendChild = function (node) {
        if (node.tagName === 'SCRIPT' && node.src) {
            window.__kvmQa.scripts++;
            if (state === 'scriptfailure') { queueMicrotask(() => node.onerror()); return node; }
            const host = document.getElementById('instant-kvm');
            host.innerHTML = '<div style="width:100%;height:100%;box-sizing:border-box;border:3px solid #50bda0;display:grid;place-items:center;padding:24px;text-align:center;background:#10202a;color:#eef7ff"><div><h1>SYNTHETIC PROVIDER HOST</h1><p>Full-viewport layout fixture only</p><p>No video, WebSocket, keyboard, media or power session.</p></div></div>';
            queueMicrotask(() => node.onload());
            return node; // NEVER insert or request the real provider script.
        }
        return append.call(this, node);
    };
    addEventListener('DOMContentLoaded', () => {
        const label=document.createElement('div');
        label.textContent='SYNTHETIC QA — '+state+' — no live provider';
        label.style.cssText='position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#fff0b3;color:#241d00;padding:6px 10px;text-align:center;font:12px/1.4 system-ui;pointer-events:none';
        document.body.appendChild(label);
    });
})();
