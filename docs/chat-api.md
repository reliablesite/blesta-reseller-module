# White-Label Sales Assistant API

An AI sales assistant for your own website, served by your Blesta install.

Your website posts a visitor's message to your Blesta; your Blesta forwards it to the
ReliableSite AI with your reseller API key attached server-side, and returns the reply. The
assistant answers as **your** company, quotes **your** prices, and links into **your** order
form. Your API key never reaches the browser.

Requires the ReliableSite module for Blesta **2.6 or later**.

---

## Setup

1. In Blesta admin, open **Settings → Company → Modules → ReliableSite → Manage**.
2. Open the **Settings** tool (gear icon, far right of the module's nav) and scroll to
   **Customer-facing sales assistant**.
3. Set the **Assistant name** and **Company name** the AI should introduce itself with.
4. Under **Allowed websites**, add every site that will embed the chat, one per line
   (`https://www.example.com`, or `*.example.com` for all subdomains).
5. Tick **Enable the sales assistant endpoint** and save.

Two prerequisites the **Ask Brian** screen will warn you about:

- Your module **API Key** must be set (Connection section of the same settings form).
- Your install must be reachable at a public `https://` address. The AI reads your catalog from
  it over the public internet — if it is `http://`, internal, or unreachable, replies come back
  with no prices and no purchase links. If the address the public uses differs from the one
  Blesta knows (a vanity domain, a reverse proxy), set **Public base URL**.

The **Ask Brian** tool (robot icon) shows the resulting endpoint URL, whether the assistant is
live, and anything still missing.

---

## Endpoint

```http
POST https://your-blesta.example.com/components/modules/reliablesite/api.php?action=chat
Content-Type: application/json
```

No API key or credential is sent from the browser. The endpoint is bound to your install and
authorised by the **Allowed websites** list plus rate limiting.

### Request

```json
{
  "message": "I need a server for a Minecraft community of about 200 players",
  "sessionId": "web-res-blesta-wl-4f2a91c07d13-9b2c41ae77d0",
  "assignmentId": "asn-...",
  "email": "visitor@example.com"
}
```

| Field | Required | Notes |
|---|---|---|
| `message` | yes | 1–2000 characters. |
| `sessionId` | no | Omit on the first message; echo back the returned value on every message after that. This is what preserves conversation history. |
| `assignmentId` | no | Long-lived visitor id returned on the first reply. Store it and send it back so returning visitors are recognised. |
| `email` | no | Send it once the visitor gives you one, to attach the conversation to them. |

`sessionId` and `assignmentId` must match `[A-Za-z0-9._:-]{1,190}` — send back exactly what you
were given and they will.

A form-encoded body (`application/x-www-form-urlencoded`) with the same field names is also
accepted, which avoids a CORS preflight if you prefer.

### Response

```json
{
  "success": true,
  "response": "For 200 concurrent players I'd go with... [Buy now](https://your-blesta.example.com/components/modules/reliablesite/api.php?action=widget&id=262&currency=USD)",
  "sessionId": "web-res-blesta-wl-4f2a91c07d13-9b2c41ae77d0",
  "assignmentId": "asn-..."
}
```

`response` is markdown — expect `[label](url)` links and `**bold**`. Escape it before inserting
into the DOM, then linkify; never assign it with `innerHTML` raw.

Purchase links point at `action=widget`, which maps the recommended server to the matching
package in your Blesta and redirects the visitor into your order form at your price. See the
[Widget API](widget-api.md#3-cart-handoff).

**Store the session id.** Omit `sessionId` on the first message and the module mints one for
that visitor. Every message that omits it starts a brand new conversation with no memory of the
last one.

### Errors

```json
{ "success": false, "errors": ["You have sent too many messages. Please wait a moment and try again."] }
```

| Status | Meaning |
|---|---|
| `400` | Invalid request — empty/oversized message, malformed email or ids. |
| `403` | The assistant is disabled, or the calling site is not in **Allowed websites**. |
| `405` | Wrong method — use `POST`. |
| `429` | Rate limited. A `Retry-After` header and `retryAfter` field give the seconds to wait. |
| `502` | The AI service returned an error. |
| `503` | The assistant is misconfigured (check the two prerequisites above) or the service is down. |
| `504` | The AI service did not reply in time. Retrying usually succeeds. |

Every error body carries `errors` as an array of human-readable strings, and `errors[0]` is
always safe to show a visitor: messages are brand-neutral and never mention ReliableSite or
your configuration. The underlying cause is written to your Blesta **Module Log** instead.

Note this endpoint uses real booleans and real HTTP status codes, unlike the catalog feeds in
the [Widget API](widget-api.md), which always answer `200` with `success: 1|0` for WHMCS
compatibility.

---

## What the assistant can sell

Recommendations come from your live Blesta catalog, read from the two feeds this module already
publishes:

```text
GET ?action=pricing      # synced packages, in stock, with your prices
GET ?action=currencies   # your currency codes and conversion rates
```

Only packages that are synced from ReliableSite inventory **and currently have stock in your
Blesta** appear in `pricing`, so the assistant cannot recommend hardware you can't fulfil.
Prices quoted are the ones in your package catalog, including your configured markup.

Catalog data is cached by the AI service for about 10 minutes, so a price or stock change takes
up to that long to reach the assistant — on top of however often your `rs_catalog_sync` cron
runs.

---

## Rate limits

Two limits, both configured in the same settings section:

- **Per visitor** — default 20 messages per 600 seconds, keyed on visitor IP.
- **Per store** — an optional cap on total messages per 24 hours across your whole site
  (**Daily cap**, default `0` = off).

Counters live in `mod_reliablesite_chat_rate`; nothing else about a conversation is stored in
your database.

If Blesta sits behind Cloudflare or a reverse proxy, tick **This install sits behind Cloudflare
or a reverse proxy** — otherwise every visitor shares the proxy's IP and they will collectively
trip the per-visitor limit. Leave it off when you are not behind one: with it on, anyone can
mint a fresh bucket per request by varying a header.

---

## Worked example

```html
<script>
(function () {
  var ENDPOINT = 'https://your-blesta.example.com/components/modules/reliablesite/api.php?action=chat';

  function send(message) {
    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: message,
        // Session id lives for the tab; assignment id follows the visitor across visits.
        sessionId: sessionStorage.getItem('chat_session') || undefined,
        assignmentId: localStorage.getItem('chat_visitor') || undefined
      })
    })
    .then(function (r) { return r.json().then(function (body) { return { status: r.status, body: body }; }); })
    .then(function (res) {
      if (!res.body.success) {
        throw new Error(res.body.errors ? res.body.errors[0] : 'Chat unavailable');
      }
      // Persist the ids or the next message starts a brand new conversation.
      if (res.body.sessionId) sessionStorage.setItem('chat_session', res.body.sessionId);
      if (res.body.assignmentId) localStorage.setItem('chat_visitor', res.body.assignmentId);
      return res.body.response;
    });
  }

  // Escape first, then apply the small subset of markdown the assistant emits.
  function render(markdown) {
    var html = markdown
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    return html
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
               '<a href="$2" target="_blank" rel="noopener">$1</a>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\n/g, '<br>');
  }

  window.salesAssistant = { send: send, render: render };
})();
</script>
```

Wire `send()` to your input field and `render()` to your message list, and you have a working
assistant. Expect a reply in a few seconds; allow up to 60 for the first message of a session,
which is when the catalog may need rebuilding.

Quick check from the command line:

```bash
curl -s -X POST \
  -H 'Content-Type: application/json' \
  -H 'Origin: https://www.example.com' \
  -d '{"message":"What do you recommend for a small game server?"}' \
  'https://your-blesta.example.com/components/modules/reliablesite/api.php?action=chat' | jq .
```

`Origin` has to be one of your **Allowed websites** or you will get a `403`.

---

## Notes

- **Server-to-server** calls (no browser `Origin` header) are accepted, so you can proxy the
  endpoint from your own backend if you prefer. Rate limits still apply, keyed on the IP that
  reaches Blesta — send the visitor's IP through your proxy (and tick the proxy setting) or all
  visitors share one bucket.
- **CORS** is origin-scoped, not `*`: the endpoint echoes back only an `Origin` that appears in
  **Allowed websites**, and always sends `Vary: Origin`. The catalog feeds are the opposite —
  fully public — because they expose nothing your order form doesn't.
- **Conversation history** is held by the AI service against `sessionId`. Nothing is stored in
  your Blesta database beyond rate-limit counters.
- **Session ids are per visitor.** A minted id looks like
  `web-res-blesta-wl-<install>-<visitor>`; the first half identifies your install, the second is
  random per conversation.
- **Logging**: each call is recorded under **Tools → Logs → Module Log** against the ReliableSite
  module. The API key is redacted.
- **This is a different assistant from the one on the Ask Brian screen.** That one answers *you*
  as a ReliableSite reseller, from the central catalog, and is a staff tool. This one answers
  your visitors as your brand, from your catalog. They share only an API key.
