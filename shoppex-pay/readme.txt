=== Shoppex Pay for WooCommerce ===
Contributors: shoppex
Tags: payment gateway, woocommerce, crypto, paypal, stripe
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
WC requires at least: 7.0
WC tested up to: 9.5
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept Stripe, PayPal, Cryptocurrencies and many more payment methods in WooCommerce via Shoppex.

== Description ==

Shoppex Pay lets your WooCommerce store accept payments through the Shoppex hosted checkout. After installing the plugin and pasting in your Shoppex Dev API key, your customers can pay with any of the payment methods enabled in your Shoppex dashboard, including:

* Credit & debit cards via Stripe
* PayPal
* Cryptocurrencies (Bitcoin, Ethereum, Litecoin, Solana, USDT, and more)
* SumUp
* Square
* CashApp
* Any additional gateways you have configured in Shoppex

The plugin is a thin payment bridge — your products, inventory, and orders live in WooCommerce; Shoppex only handles the payment step. When a customer checks out, they are redirected to a Shoppex-hosted payment page, and the order is updated via an HMAC-signed webhook.

= What you need =

* A Shoppex account with the gateways you want to accept configured
* A Shoppex Dev API key (create one in Shoppex dashboard → Settings → Developer Tools)
* A registered Shoppex webhook pointing at your store

== Installation ==

1. Upload the `shoppex-pay` folder to `/wp-content/plugins/`, or install via the WordPress plugin directory.
2. Activate **Shoppex Pay for WooCommerce** in *Plugins → Installed Plugins*.
3. Go to *WooCommerce → Settings → Payments* and enable **Shoppex Pay**.
4. Paste your Shoppex **API key** (starts with `shx_`).
5. In the Shoppex dashboard, create a webhook pointing at:
   `https://YOUR-STORE.com/?wc-api=shoppex_webhook`
   Subscribe it to these events: `order:created`, `order:paid`, `order:partial`, `order:cancelled`, `order:disputed`, `order:updated`.
6. Copy the webhook **secret** into the plugin settings and save.

== Frequently Asked Questions ==

= Which payment methods can I accept? =

Whatever you have enabled in your Shoppex dashboard. The plugin itself doesn't decide — Shoppex shows the customer the methods you have turned on.

= Does this plugin sync products between WooCommerce and Shoppex? =

No. The plugin is a payment bridge only. Your products and inventory stay in WooCommerce.

= Can I refund an order from WooCommerce? =

Yes, for orders paid with Stripe or PayPal — use the standard WooCommerce refund flow. For other gateways (e.g. cryptocurrency payments), Shoppex does not currently offer programmatic refunds; the plugin will add an order note with a link to the Shoppex dashboard where you can issue the refund manually.

= Is my API key secure? =

The API key is stored in the WordPress options table, same as all other payment gateway credentials. It is never shown in the front-end. Only trusted administrators can view or edit it.

= Why does the customer get redirected off my store to pay? =

Shoppex is a hosted checkout. This means we handle PCI compliance, fraud checks, and payment-method rendering on the Shoppex side, and you don't need to touch card data. After payment, customers are sent back to your WooCommerce thank-you page.

== Changelog ==

= 1.0.0 =
* Initial release.
* Hosted checkout redirect via `POST /dev/v1/checkout/sessions`.
* Webhook handler with HMAC-SHA512 signature verification.
* WooCommerce Blocks checkout support.
* HPOS (High-Performance Order Storage) compatibility.
* Refund support for Stripe and PayPal orders.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
