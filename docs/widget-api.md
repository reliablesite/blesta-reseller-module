# Widget API

Public read-only endpoints exposed by the **ReliableSite** module for Blesta (v2.6).

These exist so an external storefront widget — hosted anywhere, on any domain — can display **your** prices, **your** currencies and **your** live stock, and then hand the visitor off to your Blesta order form with the right package and billing term pre-selected. The same data is what the [white-label sales assistant](chat-api.md) reads when recommending hardware.

Implemented in [api.php](../api.php), which also serves the chat endpoint.

---

## Base URL

```
https://your-blesta.example.com/components/modules/reliablesite/api.php
```

Use the URL of your **Blesta installation**. If Blesta lives in a subdirectory, include it:

```
https://example.com/billing/components/modules/reliablesite/api.php
```

Each endpoint is selected with the `action` query parameter; anything else answers `Unknown action.`

These are a plain file path rather than a route because Blesta has no query-string front controller — it dispatches on the request path only, and modules (unlike plugins) get no routes at all. The file is requested directly, which works because the webroot rewrite rule only redirects paths that do not resolve to a real file. The response bodies deliberately stay close to the WHMCS `rspanel` addon's conventions where that costs nothing — the `success: 1` envelope, the per-cycle column names, and the `whmcs_product_id` / `skip_price_sync` field names — so a widget written against the WHMCS feeds has little to change. The *shape* is familiar; the *URLs and semantics* are Blesta's.

---

## Endpoints at a glance

| Purpose | Request | Returns |
|---|---|---|
| [Pricing & stock](#1-pricing--stock) | `GET ?action=pricing` | JSON — every in-stock synced package, priced in every currency |
| [Currencies](#2-currencies) | `GET ?action=currencies` | JSON — your configured currencies |
| [Cart handoff](#3-cart-handoff) | `GET ?action=widget&id=…` | `302` into your Blesta order form |

---

## Conventions

Applies to both JSON endpoints (`pricing`, `currencies`):

| | |
|---|---|
| **Method** | `GET`. `OPTIONS` is answered `200` for CORS preflight. |
| **Authentication** | **None.** Public — no key, no login, no session. |
| **CORS** | `Access-Control-Allow-Origin: *`, so browser `fetch()` from any domain works. |
| **Content-Type** | `application/json` |
| **Parameters** | None. Both always return the full data set. |
| **Company** | Resolved from the `Host` header, so a multi-company install answers with the right catalog. |
| **Throttling** | None. Cache or front with a WAF if volume concerns you. |
| **Caching** | None, server-side or in headers. Every request reads the database. |

### Response envelope

**Success**

```json
{ "success": 1, "items": [ … ] }
```

**Failure**

```json
{ "success": 0, "errors": ["An error occurred while handling the request."] }
```

> `success` is the number `1` or `0` — not a boolean. Compare loosely (`if (data.success)`).

Like the WHMCS addon, the two JSON endpoints answer `200` even on failure — check the `success` field, not the status code. The [cart handoff](#3-cart-handoff) is the exception and returns a real `404`. So does the [chat endpoint](chat-api.md), which is new and has no WHMCS-shaped consumers to keep happy.

---

## 1. Pricing & Stock

Every package synced from ReliableSite inventory that a customer could order right now.

```
GET https://your-blesta.example.com/components/modules/reliablesite/api.php?action=pricing
```

### Response fields

Each item:

| Field | Type | Description |
|---|---|---|
| `inventory_id` | int | **ReliableSite inventory product ID.** Your join key — it matches `product_id` in the upstream inventory feed, and it is the `id` you pass to the [cart handoff](#3-cart-handoff). |
| `package_id` | int | Internal Blesta package ID this was synced into |
| `whmcs_product_id` | int | Identical to `package_id`, under WHMCS's field name |
| `name` | string | Package name as configured in Blesta (`en_us`) |
| `stock` | int | Units available. Always `> 0` — see below. |
| `frozen` | bool | `true` if an admin pinned this package's price so syncs won't overwrite it |
| `skip_price_sync` | bool | Identical to `frozen`, under WHMCS's field name |
| `pricing` | array | One entry per currency — see below |

Each entry in `pricing`:

| Field | Type | Description |
|---|---|---|
| `currency` | string | ISO code — matches `code` from the [currency export](#2-currencies) |
| `currency_id` | string | Identical to `currency`. Blesta has no numeric currency id; see [migrating](#migrating-from-the-whmcs-rspanel-feeds). |
| `monthly` | float | Monthly price |
| `quarterly` | float | Quarterly price |
| `semiannually` | float | Semi-annual price |
| `annually` | float | Annual price |
| `biennially` | float | Biennial price |
| `pricing_ids` | object | Maps each **offered** cycle to its Blesta `package_pricing` id. Cycles the package does not sell are absent. |

### Example response

```json
{
  "success": 1,
  "items": [
    {
      "inventory_id": 262,
      "whmcs_product_id": 268,
      "package_id": 268,
      "name": "Intel Quad Core i5/i7/Xeon - 32 GB DDR3/4 - 1 TB SSD (New York City Metro)",
      "stock": 40,
      "skip_price_sync": false,
      "frozen": false,
      "pricing": [
        {
          "currency_id": "USD",
          "currency": "USD",
          "monthly": 65,
          "quarterly": 0,
          "semiannually": 0,
          "annually": 0,
          "biennially": 0,
          "pricing_ids": { "monthly": 693 }
        }
      ]
    }
  ]
}
```

### Things to know

**Out-of-stock products disappear from the feed.** Only packages whose Blesta quantity is above zero are returned. If an `inventory_id` you rendered yesterday is missing today, treat it as sold out, not as an error. An empty `items` array is likewise not an error — it means nothing is currently both synced and in stock.

**`pricing_ids` tells you which cycles are real — the price fields cannot.** Every cycle field is always present and defaults to `0`, so `"annually": 0` means *"this package is not sold annually"*, not *"a year costs nothing"*. Blesta has no equivalent of the WHMCS `-1` sentinel: a cycle it does not sell simply has no pricing row. Render a cycle only when its key appears in `pricing_ids`:

```js
const offered = Object.keys(price.pricing_ids);   // ["monthly"]
```

Most imports are monthly-only — the **Billing cycles to import** setting — so expect the other cycles to be absent unless an admin enabled them. A `0` that *does* appear in `pricing_ids` genuinely means free.

**Prices exclude tax and setup fees.** The cycle price is the full recurring charge before tax. Final invoiced totals are calculated by Blesta at checkout.

**One entry per currency that has a stored price.** Blesta stores a separate price per currency rather than converting on the fly, so prefer the exact `currency` match over doing your own conversion. `pricing` is an empty array if the package has no price rows yet — guard against this.

---

## 2. Currencies

Every currency configured on the company, so the widget can offer a switcher and format prices correctly.

```
GET https://your-blesta.example.com/components/modules/reliablesite/api.php?action=currencies
```

| Field | Type | Description |
|---|---|---|
| `code` | string | ISO code, e.g. `USD` — the join key used by the pricing feed |
| `id` | string | Identical to `code`. Present where WHMCS put a numeric id. |
| `prefix` | string | Symbol shown *before* the amount, e.g. `$` (may be empty) |
| `suffix` | string | Text shown *after* the amount, e.g. ` EUR` (may be empty) |
| `rate` | float | Exchange rate relative to your default currency |

```json
{
  "success": 1,
  "currencies": [
    { "id": "GBP", "code": "GBP", "prefix": "£", "suffix": "", "rate": 0.756974 },
    { "id": "USD", "code": "USD", "prefix": "$", "suffix": "", "rate": 1 }
  ]
}
```

### Things to know

**`id` carries the ISO code, not a number.** Blesta keys currencies by code and has no numeric id to return, so `id` and `code` are the same string. That is also exactly what `?currency=` expects back, so a consumer that round-trips `id → currency` keeps working untouched. One that does arithmetic on the id, or stores it in an integer column, does not.

**Your default currency always has a `rate` of `1.00`.** Every other rate is expressed relative to it. This is the real exchange rate stored in your Blesta currency settings, not a derived one.

**Don't use `rate` for pricing display.** The pricing feed already gives you a real stored price per currency. `rate` is a fallback for a currency with no price rows.

Format prices as `prefix + amount + suffix` — both can be empty strings, and both need applying to match how Blesta renders the price at checkout.

---

## 3. Cart Handoff

Takes a ReliableSite inventory ID, resolves the Blesta package it was synced into, and redirects the visitor into the order form with that package and billing term selected.

```
GET https://your-blesta.example.com/components/modules/reliablesite/api.php?action=widget&id=262&currency=USD
```

| Parameter | Required | Description |
|---|---|---|
| `id` | Yes | ReliableSite inventory product ID (`inventory_id` from the pricing feed) |
| `currency` | No | ISO currency code. Selects the cart's currency — Blesta only honours it while the visitor's cart is empty. |
| `form` | No | Order form label, to send a campaign at one specific form. Defaults to the first active form serving the package's group. |

On a match, responds `302 Found` to `/order/config/index/{form-label}/?pricing_id={id}&group_id={id}&currency={code}`.

The `pricing_id` is chosen as: monthly in the requested currency → monthly in any currency → any priced term. So a link without `&currency=` still lands somewhere sane.

If `id` is missing, non-numeric, or has nothing mapped behind it — or the package is in no group, has no active order form, or has no pricing — the endpoint answers `404`, as JSON:

```json
{ "success": 0, "errors": ["The requested product was not found."] }
```

### Things to know

**This is a navigation target, not an AJAX call.** Point a link or a button's `window.location` at it. It deliberately does *not* send CORS headers, and it needs to run in the visitor's browser so the cart session and currency selection stick.

**It saves you from hardcoding Blesta package and pricing IDs.** Your widget only ever needs ReliableSite inventory IDs; the module resolves package, group, order form and pricing term at click time, so re-syncs and package rebuilds can't break your links.

---

## Putting it together

A minimal widget that renders in-stock servers in the visitor's chosen currency:

```js
const BASE = 'https://your-blesta.example.com/components/modules/reliablesite/api.php';

async function loadWidget(currencyCode = 'USD') {
  const [pricing, currencies] = await Promise.all([
    fetch(`${BASE}?action=pricing`).then(r => r.json()),
    fetch(`${BASE}?action=currencies`).then(r => r.json()),
  ]);

  if (!pricing.success || !currencies.success) throw new Error('Feed unavailable');

  const currency = currencies.currencies.find(c => c.code === currencyCode);
  if (!currency) throw new Error(`Currency ${currencyCode} not configured`);

  return pricing.items.map(item => {
    const price = item.pricing.find(p => p.currency === currency.code);
    // A cycle exists only if it has a pricing id; the price fields default to 0.
    if (!price || !price.pricing_ids.monthly) return null;

    return {
      name:  item.name,
      stock: item.stock,
      price: `${currency.prefix}${price.monthly.toFixed(2)}${currency.suffix}`,
      url:   `${BASE}?action=widget&id=${item.inventory_id}&currency=${currency.code}`,
    };
  }).filter(Boolean);
}
```

Quick check from the command line:

```bash
BASE='https://your-blesta.example.com/components/modules/reliablesite/api.php'
curl -s "$BASE?action=pricing"    | jq .
curl -s "$BASE?action=currencies" | jq .
curl -sI "$BASE?action=widget&id=262&currency=USD" | grep -i location
```

---

## Migrating from the WHMCS `rspanel` feeds

If you have a widget written against `?m=rspanel&action=pricing`, the changes are small:

| WHMCS | Blesta |
|---|---|
| `index.php?m=rspanel&action=pricing` | `…/reliablesite/api.php?action=pricing` |
| `index.php?m=rspanel&action=currencies` | `…/reliablesite/api.php?action=currencies` |
| `index.php?m=rspanel&action=widget&id=…` | `…/reliablesite/api.php?action=widget&id=…` |
| `whmcs_product_id` | `package_id` (`whmcs_product_id` still provided) |
| `skip_price_sync` | `frozen` (`skip_price_sync` still provided) |
| cycle `-1` = disabled | cycle absent from `pricing_ids` = not offered; the value reads `0` |
| currency joined on numeric `id` | join on ISO `code` (`currency_id` carries the code too) |
| `&currency=2` (numeric) | `&currency=USD` (ISO code) |
| redirect to `cart.php?a=add&pid=…` | redirect to your order form, carrying `pricing_id` and `group_id` |
| HTTP `200` even on failure | `200` for the JSON feeds; the cart handoff returns a real `404` |

New fields you didn't have before: `package_id`, `frozen`, and `pricing_ids`. Everything else keeps its WHMCS name and meaning.

The one change that is not cosmetic is the cycle sentinel. A widget that skips cycles priced `-1` will now happily render every cycle at `0.00`, because Blesta writes `0` for a cycle it does not sell. Switch that check to `pricing_ids` before you point the widget at a Blesta install.

---

## Operational notes

**Data freshness.** These endpoints read straight from your Blesta database — they don't call ReliableSite. Values change when the catalog sync cron runs (`rs_catalog_sync`), controlled by the **Enable automatic catalog sync** and **Sync frequency** settings. Upstream inventory itself refreshes every 10–15 minutes, so syncing more often than every 15 minutes gains nothing.

**No caching, anywhere.** There is no server-side cache and no HTTP cache headers — every request hits the database. Cache client-side in the widget, and put a CDN in front if you expect meaningful traffic.

**Multi-company installs answer per hostname.** The company is resolved from the `Host` header, so each company's storefront sees its own catalog from the same file path.

**These endpoints are public and unauthenticated.** Anyone who knows your Blesta URL can read your full synced package list, your retail prices in every currency, and your live stock levels — including your internal Blesta package IDs. That is by design — the same data appears on your public order form. There is no throttle; if the volume concerns you, front the endpoints with a cache or a WAF rule rather than relying on obscurity.

**Everything else in the module directory is denied.** The module ships an `.htaccess` that blocks direct access to every `.php` file except `api.php`, and to `.json` and `.md` files, so the module source and these docs are not fetchable. If you deploy behind nginx, reproduce that rule.
