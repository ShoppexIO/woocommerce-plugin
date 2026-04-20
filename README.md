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

1. In the **Shoppex dashboard** go to *Settings → Developer Tools* and create a Dev API key (starts with `shx_`).
2. Still in the Shoppex dashboard, create a webhook pointing at:
   ```
   https://YOUR-STORE.com/?wc-api=shoppex_webhook
   ```
   Subscribe to these events:
   - `order:created`
   - `order:paid`
   - `order:partial`
   - `order:cancelled`
   - `order:disputed`
   - `order:updated`

   Copy the webhook **secret**.

3. In WooCommerce, go to *WooCommerce → Settings → Payments → Shoppex Pay* and fill in:
   - **API Key** — the `shx_...` key from step 1.
   - **Webhook Secret** — the secret from step 2.
   - **White-label checkout** — leave off unless your Shoppex account has it enabled.
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
    │  Idempotency-Key: woo_order_<id>
    │
    ▼
Shoppex returns { data: { id, url, ... } }
    │
    ▼
Redirect customer to data.url
    │
    ▼
Customer pays on Shoppex hosted checkout
    │
    ▼
Shoppex POSTs webhook with X-Shoppex-Signature (HMAC-SHA512)
    │
    ▼
Plugin verifies signature, maps event → WC order status
```

## Refunds

Refunds work out of the box in WooCommerce (*Order detail → Refund*) for orders paid with **Stripe** or **PayPal**. The plugin calls `POST /dev/v1/orders/{id}/refund` under the hood.

For other gateways (currently including cryptocurrency PSPs), Shoppex does not yet support programmatic refunds. The plugin will add an order note linking to the Shoppex dashboard where you can issue the refund manually. This matches the behavior of other crypto-aware WooCommerce gateways.

## Development

```bash
composer install
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
    css/checkout.css
  readme.txt                              # WordPress.org plugin directory listing
```

## License

[MIT](./LICENSE)
