# Brian ← Blesta: white-label service contract

What the ReliableSite module for Blesta sends to the Brian AI service, and what the service has
to do with it. This is the implementation brief for the **AI side** — the Blesta side described
here is already built and shipping.

Reseller-facing documentation lives in [chat-api.md](chat-api.md) (the endpoint a reseller's
website talks to) and [widget-api.md](widget-api.md) (the catalog feeds).

---

## The shape of the thing

```
visitor's browser                reseller's Blesta                    Brian
  │                                    │                                │
  │  POST api.php?action=chat          │                                │
  │  {"message": "..."}                │                                │
  ├───────────────────────────────────►│                                │
  │                                    │  POST /api/reseller-chat-      │
  │                                    │       whitelabel               │
  │                                    │  x-api-key: <reseller key>     │
  │                                    ├───────────────────────────────►│
  │                                    │                                │
  │                                    │        GET ?action=pricing     │
  │                                    │◄───────────────────────────────┤
  │                                    │        GET ?action=currencies  │
  │                                    │◄───────────────────────────────┤
  │                                    │                                │
  │                                    │◄───────────────────────────────┤
  │◄───────────────────────────────────┤   {"success": true, ...}       │
```

The reseller's API key only ever exists on the Blesta host. The visitor's browser never sees
it, and Brian never talks to the browser.

---

## Outbound request

```http
POST https://api-brian.reliablesite.net/api/reseller-chat-whitelabel
Content-Type: application/json
Accept: application/json
x-api-key: <GUID-format reseller key>
x-client-ip: <visitor IP, when known>
```

```json
{
  "message": "Recommend a server for Minecraft",
  "sessionId": "web-res-blesta-wl-4f2a91c07d13-9b2c41ae77d0",
  "assignmentId": "asn-...",
  "email": "visitor@example.com",
  "reseller": {
    "agentName": "Alex",
    "companyName": "Example Hosting",
    "billingPlatform": "blesta",
    "billingBaseUrl": "https://billing.example.com",
    "catalog": {
      "pricingUrl": "https://billing.example.com/components/modules/reliablesite/api.php?action=pricing",
      "currenciesUrl": "https://billing.example.com/components/modules/reliablesite/api.php?action=currencies",
      "checkoutUrlTemplate": "https://billing.example.com/components/modules/reliablesite/api.php?action=widget&id={inventory_id}&currency={currency}"
    },
    "currency": "USD"
  }
}
```

`sessionId`, `assignmentId` and `email` are omitted entirely when empty — never sent as `""`.
`reseller.currency` is omitted when the reseller has not chosen one. Everything else is always
present.

Built by `ReliablesiteBrian::buildWhiteLabelBody()` in
[lib/reliablesite_brian.php](../lib/reliablesite_brian.php).

---

## What the service needs to change

### 1. Accept `billingPlatform: "blesta"`

Today only `whmcs` is accepted and the request is rejected otherwise. `blesta` is a second
valid value with the same meaning: *this reseller's catalog lives at these URLs, quote from
it.*

### 2. Prefer `reseller.catalog` over deriving feed URLs

The current contract derives the feeds from `billingBaseUrl`:

```text
GET {billingBaseUrl}/index.php?m=rspanel&action=pricing
GET {billingBaseUrl}/index.php?m=rspanel&action=currencies
```

That works for WHMCS because its feed paths are fixed. **It cannot work for Blesta.** Blesta
has no query-string front controller — it dispatches on the request path only, and modules get
no routes at all — so the feeds are served from a real file whose path depends on the install
directory *and* on the module folder name. There is nothing to derive.

So the module sends the URLs explicitly. The rule to implement:

> If `reseller.catalog.pricingUrl` / `currenciesUrl` are present, use them verbatim. Otherwise
> fall back to deriving them from `billingBaseUrl` as today.

This is worth applying to WHMCS too, once its module also sends the block — it removes the last
place the service has to know anything about a reseller's URL layout.

Keep the existing safety checks on these URLs, applied to the supplied values rather than the
derived ones: **https only**, must resolve to a public address, no credentials in the URL, no
localhost / private / link-local targets. The Blesta module does not validate them for you —
they come from an admin-editable field.

### 3. Read the Blesta response shapes

The bodies are deliberately kept compatible with the WHMCS module's, but three things differ.
Full detail in [widget-api.md](widget-api.md#migrating-from-the-whmcs-rspanel-feeds); the parts that
affect the AI:

**Currency ids are ISO code strings, not integers.** `currencies[].id` and
`pricing[].currency_id` both carry `"USD"`, not `1`. If the current parser types these as ints
it will silently zero them. Match on the string, and note that `currency_id === currency` for
Blesta.

**A cycle priced `0` is a cycle that is not sold — not a free one.** Blesta has no `-1`
sentinel. Every cycle field is always present and defaults to `0`. The authoritative list of
cycles a package actually offers is the keys of `pricing[].pricing_ids`:

```json
"monthly": 65, "quarterly": 0, "annually": 0,
"pricing_ids": { "monthly": 693 }
```

means *monthly at $65, nothing else offered*. Quoting "$0/year" here would be a real and
visible failure. When `pricing_ids` is absent (a WHMCS feed), fall back to the `-1` rule.

**Two extra top-level fields** — `package_id` and `frozen` — are aliases of
`whmcs_product_id` and `skip_price_sync`. Ignore them; they exist for Blesta-native widgets.

Unchanged and safe to rely on: `inventory_id` is the join key to central technical inventory
(`product_id`), the feed only contains products that are both synced and in stock, and the
envelope is still `{"success": 1, "items": [...]}` with HTTP `200` on failure too.

### 4. Build purchase links from `checkoutUrlTemplate`

Substitute `{inventory_id}` and `{currency}`:

```text
https://billing.example.com/components/modules/reliablesite/api.php?action=widget&id=262&currency=USD
```

`{currency}` takes an **ISO code** for Blesta (`USD`), where WHMCS took a numeric id. Using the
value straight out of `pricing[].currency_id` is correct for both. If no currency is known,
drop the parameter rather than guessing — the module falls back to the package's monthly price
in any currency.

The result is a navigation target that 302s into the reseller's order form with the right
package, billing term and currency selected. Emit it as a markdown link (`[Buy now](...)`); the
reseller's widget renders that.

### 5. Recognise the session-id namespace

Blesta installs use two prefixes, both distinguishable from the WHMCS ones:

| Prefix | Channel |
|---|---|
| `web-res-blesta-wl-<install>-<visitor>` | White-label, this endpoint. `<install>` is stable per reseller install, `<visitor>` is random per conversation. |
| `web-res-blesta-admin-<staffId>-<host>` | The reseller's staff chat, on `/api/web-chat-reslr`. Not this endpoint. |

`<install>` is `sha1(host + '|' + apiKey)` truncated to 12 hex characters — stable, and not
reversible to the key. Group conversations by it if you want per-reseller analytics.

The module mints a session id when the visitor's browser sends none, so a request without
`sessionId` should not be expected; if one arrives, treat it as a new conversation.

---

## Expected response

```json
{
  "success": true,
  "response": "I recommend... [Buy now](https://billing.example.com/...)",
  "sessionId": "web-res-blesta-wl-...",
  "assignmentId": "asn-..."
}
```

How the module treats it:

| Upstream | What the reseller's website sees |
|---|---|
| `2xx` with truthy `success` | `200`, the reply passed through. `sessionId`/`assignmentId` are echoed from the response when present, otherwise from the request. |
| `429` | `429`, with `Retry-After` taken from a `retryAfter` field in the body if present, else 30s. |
| Any other `4xx` | `503` — treated as a fault on the reseller's side of the wire (bad key, channel not enabled, malformed request) and logged, never shown. |
| `5xx` | `502` |
| Timeout | `504` |
| Connection failure / non-JSON | `503` |

Error text from the body is **never** shown to a visitor. Every failure renders as a
brand-neutral sentence and the real response goes to the reseller's Blesta module log with the
API key redacted. So a message that is useful to a reseller's admin is worth putting in the
body; one that is only useful to us is fine there too.

Timeouts on the module side: **10s** connect, **60s** total.

---

## Behaviour the assistant should keep

Same as the WHMCS white-label channel — restated because the whole point of the endpoint is
that these hold:

- Answer as `agentName` of `companyName`. Never mention ReliableSite, the upstream inventory,
  or that the catalog is resold.
- Quote only prices found in the reseller's own feed, in the reseller's currency. Never quote a
  central price.
- Never recommend a product absent from the feed. Absence means out of stock or not carried.
- If the catalog is unreachable, answer without prices and without purchase links rather than
  inventing either.

---

## Testing against a real install

The feeds are public and unauthenticated, so they can be checked directly:

```bash
BASE='https://billing.example.com/components/modules/reliablesite/api.php'
curl -s "$BASE?action=pricing"    | jq '.items[0]'
curl -s "$BASE?action=currencies" | jq .
curl -sI "$BASE?action=widget&id=262&currency=USD" | grep -i location
```

A reseller whose `?action=pricing` returns `{"success":1,"items":[]}` has nothing synced or
nothing in stock — that is a valid, quotable state, not an error, and the assistant should say
it has nothing available rather than falling back to central inventory.
