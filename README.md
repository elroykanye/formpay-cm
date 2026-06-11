# FormPay CM

Collect **Mobile Money** and **Orange Money** payments in **Cameroon** (XAF) from any supported WordPress form — **Elementor Pro**, **WPForms**, or **MetForm** — using [Fapshi](https://fapshi.com).

The amount can be **fixed**, or resolved dynamically from the submission (e.g. a *Faculty* dropdown → tuition amount), all configured without code.

## How it works

```
Form submission
  → adapter extracts submitted fields
  → PriceResolver applies the form's pricing rule (server-side)
  → PaymentManager → Fapshi initiate-pay (hosted checkout)
  → payer redirected to Fapshi → pays via MTN MoMo / Orange Money
  → webhook + hourly reconcile sweep settle the transaction
```

The payable amount is **always resolved server-side**. For value→amount tables the form submits an option *key* (e.g. `medicine`); the price is looked up on the server, so the browser can never dictate what is charged.

## Pricing modes

| Mode | Behaviour |
|------|-----------|
| `fixed` | Same amount every submission |
| `field_map` | One field's value → amount, from a server-side table (e.g. Faculty) |
| `conditional` | First matching multi-field rule wins (e.g. Faculty + Level) |
| `field_value` | A numeric field *is* the amount (donations) — opt-in, trusts input |

## Setup

1. Install & activate the plugin.
2. **Settings → FormPay CM**: choose Sandbox/Live and paste your Fapshi service `apiuser` / `apikey`. Set a webhook secret and copy the shown Webhook URL into your Fapshi service.
3. Configure a form:
   - **Elementor**: add the *FormPay CM (Mobile Money)* action and set up pricing in the action panel.
   - **WPForms**: open the *FormPay CM* section in the form builder settings.
   - **MetForm**: configure the rule under **Settings → Form Payments**, and point the form's *Confirmation → Redirect To* at `?formpay_cm_pay=1`.

## Development

```bash
# Lint
docker run --rm -v "$PWD:/app" php:8.2-cli sh -c 'for f in $(find /app -name "*.php"); do php -l "$f"; done'

# Pricing engine unit tests
docker run --rm -v "$PWD:/app" php:8.2-cli php /app/tests/pricing-test.php

# Verify Fapshi credentials (sandbox), no WordPress needed
docker run --rm -e FAPSHI_USER=xxx -e FAPSHI_KEY=yyy -v "$PWD:/app" \
  php:8.2-cli php /app/tests/fapshi-smoke.php

# Local WordPress with the plugin mounted
docker compose up -d   # → http://localhost:8080
```

## Status

Provider: Fapshi (initiate-pay). Currency: XAF. Country: Cameroon.
Pricing engine covered by unit tests. Form integrations are built against each
plugin's documented/source APIs and should be validated on a live install.
