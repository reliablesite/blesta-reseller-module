# ReliableSite for Blesta — API documentation

The module exposes four public endpoints from a single file, [api.php](../api.php):

```
https://your-blesta.example.com/components/modules/reliablesite/api.php?action=…
```

| `action` | What it is | Doc |
|---|---|---|
| `pricing` | Your synced, in-stock packages with prices in every currency | [widget-api.md](widget-api.md) |
| `currencies` | Your currencies and exchange rates | [widget-api.md](widget-api.md) |
| `widget` | Redirect into your order form for one inventory id | [widget-api.md](widget-api.md) |
| `chat` | White-label AI sales assistant for your website | [chat-api.md](chat-api.md) |

The first three are public and unauthenticated — they expose only what your order form already
shows. `chat` spends your API key and AI quota, so it is off until you enable it, answers only
to the websites you list, and is rate limited.

### Which document do I want?

**Building a storefront widget, or a price/stock display.** → [widget-api.md](widget-api.md)

**Embedding the AI sales assistant on your website.** → [chat-api.md](chat-api.md)

**Implementing Blesta support on the Brian AI service.** →
[brian-blesta-contract.md](brian-blesta-contract.md) — what the module sends upstream and what
the service has to do with it.

### Relationship to the WHMCS module

`pricing`, `currencies` and `widget` return bodies that are deliberately compatible with the
WHMCS `rspanel` addon's, so a widget written against WHMCS needs only its base URL changed.
The exceptions are listed in
[widget-api.md § Migrating from the WHMCS rspanel feeds](widget-api.md#migrating-from-the-whmcs-rspanel-feeds).

`chat` is the Blesta equivalent of the WHMCS `action=chat` endpoint and behaves the same way,
with real HTTP status codes and boolean `success` in both.
