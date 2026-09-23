# Instant KVM implementation / scope ledger

Authority: current user request and supplied `doc_e12619296bf9_instant-kvm.md` (all 146 lines reviewed).

| Behavior | Class | Provenance | Baseline → intended |
|---|---|---|---|
| Client/admin Instant KVM | approved_requirement | User request; API doc §§2–4 | Legacy only → additional capability-gated new-tab console |
| Dedicated viewport and provider lifecycle | approved_requirement | User request; doc §4 | No console → standalone document, exact provider script with data-target |
| Authorization, CSRF, active int32 server, browser IP, no retry/logging/cache | approved_requirement | Delegation; doc §§3,5,6 | New path must fail closed without changing legacy paths |
| Module allowlist/proxy configuration | approved_requirement | Delegation; doc §§3,4 | NY origin only; trusted proxies opt-in |
| Tests, fixtures, screenshots | approved_requirement | User request | No suite → deterministic synthetic tests, no privileged live launch |
| Legacy/API logging/TLS and GET actions | out_of_scope | Preserve legacy; native-file restriction | Preserve; isolate new sensitive transport |

## Framework inspection

- `ClientServices::manage` checks the service belongs to the current client, rejects inactive tabs, resolves the service's module row, and only dispatches advertised tabs. ClientController requires login and client-area permission.
- `AdminCompanyModules::manage` requires a module in the current company and calls `manageModule` **before** metadata writes. The standalone Instant KVM branch must exit there; do not use `addrow` POST, which persists before module rendering.
- `AdminClients::serviceTab` checks service/client association and dispatches advertised module tabs. A separate explicit staff-group ACL check is used for console access because AppController's permission/CSRF implementation is ionCube-encoded here.
- Native Form supports `getCsrfToken` / `verifyCsrfToken`; use the loaded framework helper plus a separate session nonce for console launches. No core files are edited.
- Existing `fetchToken` can log key-bearing URLs on errors; new Instant KVM transport is isolated, TLS-verifying, non-redirecting and never logs requests/responses.

## Visual contract / baseline

Live unauthenticated client-services route redirects to login. No credentials requested or guessed. Public portal and login screenshots are in `tests/artifacts/`. Authenticated live module baseline is blocked. Synthetic module-template baselines will be captured before template edits.

Expected change: Instant KVM card/button near legacy KVM, only eligible servers; existing navigation and legacy controls retained. New-tab host contains no Blesta navigation, fills the viewport at 320, 390, 768, 1366 and 1920px widths. Loading/error/retry remains readable; mounted host reserves every viewport pixel for provider UI. Synthetic fixture must be visibly labeled and cannot call a real provider.

No live privileged console, keyboard, media, power or session tests are authorized. Provider video/WebSocket/IP binding/CSP remain rollout checks for an authorized test browser.

## Implemented routes and controls

- Client KVM tab links to `client/services/manage/{service}/tabClientManage/?p=instantkvm`. Server ID comes only from that authorized service's stored field, not a request override.
- Admin server KVM links to `admin/settings/company/modules/manage/{module}/?scr=instantkvm&rsid={serverId}`. Admin service tabs instead link to `admin/clients/servicetab/{client_id}/{service_id}/tabAdminManage/?p=instantkvm`, preserving the framework-bound service credential row. The service route validates the active service and bound row, derives the server ID from the service, and binds its nonce to service/row/server. Both the document and launch require active staff membership in the current company and the exact native `admin_company_modules/manage` ACL action (the native permission seed defines it separately from module listing). Console responses exit before normal controller processing resumes.
- GET renders an unframed standalone document; its script issues one same-origin, CSRF-protected POST with a one-use session nonce. No GET launches a provider session. POST rechecks ownership/ACL, active capability, exact server ID, and browser IP. The API credential row's company is verified independently of the legacy row resolver.
- All console responses are `no-store`, `no-referrer`, `nosniff`, and deny framing. No provider URL is put in navigation links or stored server-side. Third-party provider JavaScript necessarily runs in the console document's origin; only explicitly approved origins are accepted.
- Session-local policy: one attempt per 10 seconds; at most 32 outstanding nonces, each usable for five minutes. These are module safety limits, **not provider token TTL/rate-limit claims**. A failed attempt consumes its nonce; Retry reloads a fresh document. There is no automatic POST retry or second script insertion.
- Isolated cURL transport checks HTTP 200, JSON content type, strict application status, exact eligible active server, and case-sensitive `data.JavascriptUrl`; verifies TLS, forbids redirects, and never logs URLs, headers, body, upstream error text or API credentials. API tokens live only in the request's client object. `EnableKVM` is never called by this flow.
- Provider script uses `data-target="instant-kvm"` and `referrerPolicy="no-referrer"`; the host is the exact viewport with no Blesta chrome. The provider owns disconnect/page-exit cleanup. Retry only exists in pre-mount failure states; no invented teardown/session API.
- Console JS/CSS are emitted inline from module files, so no new dependency on publicly accessible `/components/` assets or a direct public PHP launch endpoint is introduced.

## Deployment configuration and limitations

Edit **only this module's** `config/reliablesite.php`:

- `Reliablesite.instant_kvm_origins`: initially only `https://kvmproxy-ny1.reliablesite.net`, the documented origin. Add a different exact HTTPS origin only after ReliableSite confirms it for your location. The script path must remain `/embed/v1/kvm.js`; its exact query is preserved.
- `Reliablesite.instant_kvm_trusted_proxies`: empty by default. Direct requests use validated public `REMOTE_ADDR`. When behind a proxy, explicitly list exact trusted peer IPs and configure those proxies to **overwrite** `X-Forwarded-For` with one validated public browser IP. Chains, ports, CIDRs, hostnames, private/shared/multicast IPs, and arbitrary forwarded headers are rejected. No proxies or infrastructure were reconfigured here.
- The existing module resolves a default credential row for admin module management. Service tabs use the framework-bound row. This change does not redesign account/row selection; the new path fails closed if the existing resolver picks a different company's row. Multi-company/multi-account deployments need an authorized end-to-end check of row selection before rollout. The legacy global `getInstalled()` row-selection behavior is a separate `needs_approval` finding, not silently refactored here.
- Live authentication was unavailable: the client services URL redirected to Login. AppController is ionCube-encoded, so its internal pre-action implementation cannot be read here. The visible native controller ownership/dispatch paths were inspected, and the module independently checks ACL/CSRF/nonce. Native Form was exercised directly: same-session CSRF accepted; wrong token and another session rejected, without creating a session file.
- No rendered real provider console, WebSocket/video, browser-IP binding, live signed-in route/CSRF flow or site CSP compatibility is claimed. Validate those with an authorized test account before production use. Do not relax CSP broadly: permit only confirmed provider HTTPS/wss origins and required styles under the site's existing policy.

## Reproducible tests

From this module directory:

```sh
python3 tests/run_tests.py
```

This runs the PHP API/security/module/config tests, cURL-boundary test (`php -n` so its cURL functions are synthetic), Node browser-code tests, loopback HTTP/render tests, all module PHP/PDT lint, and `git diff --check`. It starts/stops its own loopback fixture, never bootstraps the billing app, and never creates a real provider session. Latest output: `tests/artifacts/latest-tests.txt`.

Individual commands:

```sh
php tests/instant_kvm_test.php
php tests/module_instant_kvm_test.php
php tests/config_test.php
php -n tests/transport_test.php
node tests/instant_kvm_js_test.js
# In a separate terminal, for synthetic rendering / visual QA only:
php -S 127.0.0.1:8765 tests/fixture.php
python3 tests/ui_test.py
```

A cURL-stub file must be linted with `php -n -l`, not normal `php -l` (otherwise built-in cURL function names collide). The full runner handles this. No Composer/npm packages or native files were changed.

### TDD evidence

Incremental RED→GREEN cycles were executed, not just tests added afterward: missing launch client → ID/IP gates → active exact-server capability → HTTP/envelope/token failure handling → provider script allowlist → trusted proxy policy → isolated TLS transport → CSRF/one-use response → client/admin authorization → throttle → UI route data → controller dispatch → rendered new-tab links → real tab data binding → default configuration → single browser POST/script → browser errors/retry → standalone document → mapped-private/multicast IP rejection → credential-company gate → exact native manage-action denial → frame denial. Each failed test was run before its corresponding implementation and passed afterward.

One early route test reached the legacy token helper using the literal synthetic noncredential from the test stub; no privileged launch occurred. The harness was corrected immediately to make legacy API access throw before network access. All final automated transport/provider responses are injected synthetic fixtures.

## Visual QA results

Baseline: public portal/login evidence plus 15 synthetic entry screenshots captured **before** template edits. Authenticated live baseline remained blocked. The synthetic template fixture uses actual module templates with minimal fake framework helpers; it does not claim native Blesta chrome fidelity.

Final: 15 entry viewport combinations and 25 console state/viewport combinations at **320×568, 390×844, 768×1024, 1366×768, 1920×1080**. States: loading, launch error, script-load failure, unavailable, and synthetic mounted host. Exact host bounds and request/script counts were asserted; a real browser link click created one new console tab; explicit retry created a fresh document with one POST.

- PASS: no horizontal page overflow; entry links stay inside their card with at least 44px height.
- PASS: existing navigation/legacy controls retained; only the Instant KVM card is added. Narrow pages naturally scroll vertically after adding the card.
- PASS: host fills the viewport; pre-mount messages/retry remain readable; mounted host has no Blesta chrome.
- PASS: unavailable state makes zero launch POSTs; other synthetic states make exactly one; only mounted/script-failure states attempt one script insertion. The real provider script is intercepted and **never requested or executed**.
- BLOCKED: real provider rendering/controls, native authenticated layout, video/WebSockets/IP binding/CSP.

Artifacts under `tests/artifacts/`:

- `baseline-*.png`, `final-*.png`, `console-*.png`
- `visual-comparison.png`, `console-states-320.png`
- `baseline.json`, `entry-qa.json`, `console-qa.json`, `flow-qa.json`
- `public-baseline.png`, `live-auth-blocker.png`, `latest-tests.txt`

To repeat safe browser QA, inject `tests/browser_fixture.js` via CDP **before navigating** to `http://127.0.0.1:8765/console?state=...`, and block `*reliablesite.net/*` / `*reliablesite.dev/*` requests as defense in depth. The fixture intercepts both fetch and script insertion and visibly labels every console state as synthetic. Never substitute a live authenticated console URL for the fixture.
