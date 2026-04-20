<?php
/**
 * Block-based checkout integration for Shoppex Pay.
 *
 * @package Shoppex_Pay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class WC_Gateway_Shoppex_Blocks
 */
final class WC_Gateway_Shoppex_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (must match the gateway ID).
	 *
	 * @var string
	 */
	protected $name = 'shoppex';

	/**
	 * Init settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_shoppex_settings', array() );
	}

	/**
	 * Is this payment method active?
	 *
	 * @return bool
	 */
	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Enqueue the front-end asset.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$handle = 'wc-shoppex-blocks';
		wp_register_script(
			$handle,
			SHOPPEX_PAY_PLUGIN_URL . 'assets/js/blocks-checkout.js',
			array( 'wp-element', 'wc-blocks-registry', 'wp-html-entities', 'wp-i18n' ),
			SHOPPEX_PAY_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'shoppex-pay' );
		}
		return array( $handle );
	}

	/**
	 * Data forwarded into the React component.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) ? (string) $this->settings['title'] : __( 'Shoppex Pay', 'shoppex-pay' ),
			'description' => isset( $this->settings['description'] ) ? (string) $this->settings['description'] : '',
			'supports'    => array( 'products', 'refunds' ),
			'icon'        => SHOPPEX_PAY_PLUGIN_URL . 'assets/images/logo.svg',
		);
	}
}
