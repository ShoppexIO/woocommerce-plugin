# Local Testing Guide

This guide describes how to exercise the plugin end-to-end against a real WordPress + WooCommerce installation with a local Shoppex backend.

## Prerequisites

- Docker Desktop (or Podman)
- A local Shoppex backend running on port **3002** (see the main monorepo's `docs/runbooks/dev-environment.md`)
- A Shoppex Dev API key (`shx_...`) associated with a test shop

## 1. Start WordPress + WooCommerce

From the root of this repo:

```bash
docker compose -f docs/docker-compose.test.yml up -d
```

That brings up:

| Service | URL                   | Purpose                              |
|---------|-----------------------|--------------------------------------|
| WP      | http://localhost:8080 | WordPress + WooCommerce              |
| MySQL   | (internal)            | WP database                          |

The plugin is mounted as `wp-content/plugins/shoppex-pay` via a volume, so changes to the plugin code are picked up without rebuilding.

## 2. Configure WordPress

1. Visit http://localhost:8080 and complete the 5-minute installer.
2. Activate **WooCommerce** and run the WC setup wizard (pick any options).
3. *Plugins → Installed Plugins* → activate **Shoppex Pay for WooCommerce**.
4. *WooCommerce → Settings → Payments* → enable **Shoppex Pay**, paste your API key.

## 3. Expose the site to the internet (for webhooks)

Shoppex needs a publicly reachable URL to deliver webhooks. The easiest options:

### ngrok

```bash
ngrok http 8080
```

Grab the `https://*.ngrok-free.app` URL.

### cloudflared

```bash
cloudflared tunnel --url http://localhost:8080
```

### localtunnel

```bash
npx localtunnel --port 8080
```

## 4. Register the webhook in Shoppex

In the Shoppex dashboard → *Settings → Developer Tools → Webhooks* → add a webhook:

- **URL:** `https://YOUR-TUNNEL/?wc-api=shoppex_webhook`
- **Events:** `order:created`, `order:paid`, `order:partial`, `order:cancelled`, `order:disputed`, `order:updated`
- Copy the generated **secret** and paste it into the plugin's Webhook Secret field in WP admin.

## 5. Run the end-to-end flow

1. Create a test product in WooCommerce (*Products → Add New* → simple product, $19.99).
2. Add it to the cart and check out.
3. The customer should be redirected to the Shoppex hosted checkout.
4. Pay with a Stripe test card (`4242 4242 4242 4242`, any future expiry, any CVC).
5. You should be redirected back to the order-received page.
6. Within a few seconds the webhook fires and the order moves to **Completed**.

## 6. Run the smoke tests

Signature verification (no backend needed):

```bash
php tests/smoke/webhook-signature-smoke.php
```

Checkout session creation (against localhost:3002 with a working API key):

```bash
SHOPPEX_API_KEY="shx_..." \
SHOPPEX_API_BASE="http://localhost:3002" \
  php tests/smoke/checkout-session-smoke.php
```

## Troubleshooting

- **"Invalid signature" in debug log:** The webhook secret in the plugin settings doesn't match the one in the Shoppex dashboard. Rotate and paste again.
- **Webhook never arrives:** Check that your tunnel URL is still valid (ngrok free URLs rotate on restart). Re-register in Shoppex if so.
- **"Could not start Shoppex checkout" notice at checkout:** Check the WooCommerce logs at *WooCommerce → Status → Logs → `shoppex-pay-*`* with **Debug logging** enabled in the plugin settings.
- **Order stuck on `pending`:** Either the webhook didn't reach your site, or the HMAC check failed. Check the logs.
