<?php
/**
 * Plugin Name:          Shoppex Pay for WooCommerce
 * Plugin URI:           https://github.com/ShoppexIO/woocommerce-plugin
 * Description:          Accept payments in WooCommerce via Shoppex: Stripe, PayPal, Cryptocurrencies (Bitcoin, Ethereum, Litecoin, Solana, USDT, and more), SumUp, Square, CashApp, and other gateways configured in your Shoppex account.
 * Version:              1.0.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Author:               Shoppex
 * Author URI:           https://shoppex.io
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          shoppex-pay
 * Domain Path:          /languages
 * WC requires at least: 7.0
 * WC tested up to:      9.5
 *
 * @package Shoppex_Pay
 */

defined( 'ABSPATH' ) || exit;

define( 'SHOPPEX_PAY_VERSION', '1.0.0' );
define( 'SHOPPEX_PAY_PLUGIN_FILE', __FILE__ );
define( 'SHOPPEX_PAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOPPEX_PAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHOPPEX_PAY_API_BASE', 'https://api.shoppex.io' );

/**
 * Declare HPOS + Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Bootstrap after WooCommerce is loaded.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Shoppex Pay requires WooCommerce to be installed and active.', 'shoppex-pay' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once SHOPPEX_PAY_PLUGIN_DIR . 'includes/class-shoppex-api-client.php';
		require_once SHOPPEX_PAY_PLUGIN_DIR . 'includes/class-shoppex-webhook-handler.php';
		require_once SHOPPEX_PAY_PLUGIN_DIR . 'includes/class-wc-gateway-shoppex.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WC_Gateway_Shoppex';
				return $gateways;
			}
		);

		Shoppex_Webhook_Handler::register();
	},
	11
);

/**
 * Register Blocks-based checkout payment method.
 */
add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once SHOPPEX_PAY_PLUGIN_DIR . 'includes/class-wc-gateway-shoppex-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new WC_Gateway_Shoppex_Blocks() );
			}
		);
	}
);

/**
 * Plugin action links in the Plugins list.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=shoppex' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'shoppex-pay' ) . '</a>'
		);
		return $links;
	}
);
