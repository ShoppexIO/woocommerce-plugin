# Shoppex Pay for WooCommerce

Accept **Stripe, PayPal, Cryptocurrencies, SumUp, Square, CashApp** and more in WooCommerce through the [Shoppex](https://shoppex.io) hosted checkout.

## What it is

A thin WordPress plugin that adds **Shoppex** as a payment gateway in WooCommerce. Your products and orders stay in WooCommerce; Shoppex handles the payment step and notifies your store via signed webhooks.

- No PCI compliance for your store — Shoppex hosts the checkout.
- Supports every payment method you enable in your Shoppex dashboard.
- Works with both classic and Blocks checkout.
- Compatible with WooCommerce HPOS (High-Performance Order Storage).
- Refund support for Stripe and PayPal orders from inside WooCommerce.

## Requirements

- WordPress 6.2+
- WooCommerce 7.0+
- PHP 7.4+
- A Shoppex account with a Dev API key and a registered webhook

## Installation

### Via the WordPress plugin directory (recommended)

Search for **Shoppex Pay** in *Plugins → Add New* and install.

### Manually

1. Download the latest release ZIP from the [Releases page](https://github.com/ShoppexIO/woocommerce-plugin/releases).
2. Upload via *Plugins → Add New → Upload Plugin*.
3. Activate.

## Configuration

1. In the **Shoppex dashboard** go to *Settings → Developer → API keys* and create a Dev API key (starts with `shx_`) with the `payments.write` and `orders.write` scopes.
2. In *Settings → Developer → Webhooks*, create a webhook pointing at:
   ```
   https://YOUR-STORE.com/?wc-api=shoppex_webhook
   ```
   Subscribe to these events:
   - `order:paid`
   - `order:partial`
   - `order:cancelled`
   - `order:disputed`
   - `order:updated`

   Copy the webhook **secret**.

3. In WooCommerce, go to *WooCommerce → Settings → Payments → Shoppex Pay* and fill in:
   - **API Key** — the `shx_...` key from step 1.
   - **Webhook Secret** — the secret from step 2.
   - **Checkout display** — *Redirect* (default) sends the customer to the Shoppex payment page. *Overlay window* keeps them on your store: the order payment page opens the Shoppex checkout in a window, with a link to the full payment page next to it.
   - Optional: **Order title prefix**, **Debug logging**.

4. Save. You're done.

## How it works

```
Customer places order
    │
    ▼
WooCommerce calls process_payment()
    │
    ▼
POST https://api.shoppex.io/dev/v1/checkout/sessions
    │  Authorization: Bearer shx_...
    │  Idempotency-Key: woo_order_<id>_<hash of previous session + payload>
    │
    ▼
Shoppex returns { data: { id, url, ... } }
    │
    ├─ Redirect: customer goes to data.url
    └─ Overlay:  customer goes to the WooCommerce order payment page,
                 which runs Shoppex.open({ invoiceId: data.id })
    │
    ▼
Customer pays on the Shoppex checkout
    │
    ▼
Shoppex POSTs the webhook with X-Shoppex-Signature-V2
(HMAC-SHA256 over <delivery id>.<timestamp>.<body>)
    │
    ▼
Plugin verifies the signature and timestamp, finds the order by the
metadata it sent (order id + order key), and on order:paid checks the
paid amount and currency against the order before completing it.
```

The order is marked paid only by the verified webhook. The overlay's browser events (`shoppex:success`, `shoppex:close`) only move the customer to the order-received page.

### Overlay window

The overlay loads `https://checkout.shoppex.io/embed/embed.iife.js` on the order payment page. If the script cannot load (blocked by an extension or a Content Security Policy), the page says so and the customer continues on the full payment page through the link that is always shown. Payment methods that need a new tab (PayPal, some bank redirects) open one from the overlay; when the browser blocks it, the checkout navigates the current tab instead. If your store sends a `Content-Security-Policy`, allow `https://checkout.shoppex.io` in `script-src` and `frame-src`.

## Refunds

Refunds work out of the box in WooCommerce (*Order detail → Refund*) for orders paid with **Stripe** or **PayPal**. The plugin calls `POST /dev/v1/orders/{id}/refund` under the hood.

For other gateways (currently including cryptocurrency PSPs), Shoppex does not yet support programmatic refunds. The plugin will add an order note linking to the Shoppex dashboard where you can issue the refund manually. This matches the behavior of other crypto-aware WooCommerce gateways.

## Development

```bash
composer install
composer test       # PHPUnit (WordPress and WooCommerce doubles, no install needed)
composer phpcs      # lint with WPCS + PHPCompatibility
composer phpcbf     # auto-fix where possible
```

### Repo layout

```
shoppex-pay/
  shoppex-pay.php                         # Plugin bootstrap
  includes/
    class-wc-gateway-shoppex.php          # Main payment gateway
    class-wc-gateway-shoppex-blocks.php   # Blocks checkout registration
    class-shoppex-api-client.php          # /dev/v1 HTTP client
    class-shoppex-webhook-handler.php     # Webhook receiver + HMAC verify
  assets/
    images/logo.svg
    js/blocks-checkout.js
    js/overlay.js                         # Opens the invoice in the Shoppex embed window
    css/checkout.css
  readme.txt                              # WordPress.org plugin directory listing
```

## License

[MIT](./LICENSE)
