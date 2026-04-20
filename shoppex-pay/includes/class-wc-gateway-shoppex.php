<?php
/**
 * Shoppex Payment Gateway for WooCommerce.
 *
 * @package Shoppex_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_Shoppex
 */
class WC_Gateway_Shoppex extends WC_Payment_Gateway {

	const META_UNIQID         = '_shoppex_uniqid';
	const META_SESSION_URL    = '_shoppex_session_url';
	const META_LAST_STATUS    = '_shoppex_last_status';
	const META_PAYMENT_METHOD = '_shoppex_payment_method';

	/**
	 * API key.
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Webhook secret (HMAC-SHA512 shared secret).
	 *
	 * @var string
	 */
	public $webhook_secret;

	/**
	 * White-label hosted checkout toggle.
	 *
	 * @var bool
	 */
	public $white_label;

	/**
	 * Order title prefix.
	 *
	 * @var string
	 */
	public $order_prefix;

	/**
	 * Debug mode.
	 *
	 * @var bool
	 */
	public $debug;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'shoppex';
		$this->method_title       = __( 'Shoppex Pay', 'shoppex-pay' );
		$this->method_description = __( 'Accept Stripe, PayPal, Cryptocurrencies, SumUp, Square, CashApp and more via the Shoppex hosted checkout. Payment methods that appear at checkout depend on what you have enabled in your Shoppex dashboard.', 'shoppex-pay' );
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'woocommerce_shoppex_icon', SHOPPEX_PAY_PLUGIN_URL . 'assets/images/logo.svg' );
		$this->supports           = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title          = $this->get_option( 'title', __( 'Shoppex Pay', 'shoppex-pay' ) );
		$this->description    = $this->get_option( 'description', __( 'Pay with credit card, PayPal, cryptocurrencies, and more via Shoppex.', 'shoppex-pay' ) );
		$this->api_key        = trim( (string) $this->get_option( 'api_key' ) );
		$this->webhook_secret = trim( (string) $this->get_option( 'webhook_secret' ) );
		$this->white_label    = 'yes' === $this->get_option( 'white_label', 'no' );
		$this->order_prefix   = (string) $this->get_option( 'order_prefix', 'WC Order #' );
		$this->debug          = 'yes' === $this->get_option( 'debug', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings schema.
	 */
	public function init_form_fields() {
		$webhook_url = add_query_arg( 'wc-api', 'shoppex_webhook', home_url( '/' ) );

		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'shoppex-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Shoppex Pay', 'shoppex-pay' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'shoppex-pay' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'shoppex-pay' ),
				'default'     => __( 'Shoppex Pay', 'shoppex-pay' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'shoppex-pay' ),
				'type'        => 'textarea',
				'description' => __( 'Shown below the title at checkout.', 'shoppex-pay' ),
				'default'     => __( 'Pay with credit card, PayPal, cryptocurrencies, and more via Shoppex.', 'shoppex-pay' ),
			),
			'api_key'        => array(
				'title'       => __( 'API Key', 'shoppex-pay' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: link to the Shoppex developer dashboard. */
					__( 'Your Shoppex Dev API key. Create one in the %s (Settings → Developer Tools).', 'shoppex-pay' ),
					'<a href="https://dashboard.shoppex.io/store/settings?tab=developer" target="_blank" rel="noopener">Shoppex dashboard</a>'
				),
				'default'     => '',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook Secret', 'shoppex-pay' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: webhook URL. */
					__( 'HMAC-SHA512 shared secret from your Shoppex webhook. Register this URL in the Shoppex dashboard: %s', 'shoppex-pay' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				),
				'default'     => '',
			),
			'white_label'    => array(
				'title'       => __( 'White-label checkout', 'shoppex-pay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use a branded (white-label) hosted checkout URL', 'shoppex-pay' ),
				'description' => __( 'Requires the feature to be enabled on your Shoppex account. Leave off if unsure.', 'shoppex-pay' ),
				'default'     => 'no',
			),
			'order_prefix'   => array(
				'title'       => __( 'Order title prefix', 'shoppex-pay' ),
				'type'        => 'text',
				'description' => __( 'Prefix shown on the Shoppex hosted checkout page, e.g. "WC Order #" will display "WC Order #1234".', 'shoppex-pay' ),
				'default'     => 'WC Order #',
				'desc_tip'    => true,
			),
			'debug'          => array(
				'title'   => __( 'Debug logging', 'shoppex-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log API requests and webhook events to the WooCommerce logs', 'shoppex-pay' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Build an API client with the current settings.
	 *
	 * @return Shoppex_Api_Client
	 */
	public function api() {
		/**
		 * Filter the Shoppex API base URL. Production default is `https://api.shoppex.io`.
		 * Override only for local development / E2E tests.
		 *
		 * @param string $api_base Base URL without trailing slash.
		 */
		$api_base = apply_filters( 'shoppex_pay_api_base', SHOPPEX_PAY_API_BASE );
		return new Shoppex_Api_Client( $this->api_key, $api_base, $this->debug );
	}

	/**
	 * Process a checkout payment — create a Shoppex session and redirect.
	 *
	 * @param int $order_id WC order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'shoppex-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( '' === $this->api_key ) {
			wc_add_notice( __( 'Shoppex Pay is not configured. Please contact the site administrator.', 'shoppex-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$payload = array(
			'product'        => array(
				'name'     => $this->order_prefix . $order->get_order_number(),
				'quantity' => 1,
			),
			'amount'         => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'customer_email' => $order->get_billing_email(),
			'success_url'    => $this->get_return_url( $order ),
			'cancel_url'     => $order->get_cancel_order_url_raw(),
			'metadata'       => array(
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => (string) $order->get_order_key(),
				'source'       => 'woocommerce',
			),
			'white_label'    => $this->white_label,
		);

		/**
		 * Filter the checkout session payload before it is sent to Shoppex.
		 *
		 * @param array    $payload Checkout session payload.
		 * @param WC_Order $order   Current WC order.
		 */
		$payload = apply_filters( 'shoppex_pay_checkout_session_payload', $payload, $order );

		$response = $this->api()->create_checkout_session( $payload, 'woo_order_' . $order->get_id() );

		if ( ! $response['ok'] || empty( $response['data']['url'] ) ) {
			$message = $response['error'] ? $response['error'] : __( 'Could not start Shoppex checkout.', 'shoppex-pay' );
			wc_add_notice( $message, 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message. */
					__( 'Shoppex Pay: failed to create checkout session. %s', 'shoppex-pay' ),
					$message
				)
			);
			return array( 'result' => 'failure' );
		}

		$data = $response['data'];

		if ( ! empty( $data['id'] ) ) {
			$order->update_meta_data( self::META_UNIQID, (string) $data['id'] );
		}
		if ( ! empty( $data['url'] ) ) {
			$order->update_meta_data( self::META_SESSION_URL, (string) $data['url'] );
		}
		$order->update_status(
			'pending',
			__( 'Awaiting Shoppex Pay checkout.', 'shoppex-pay' )
		);
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $data['url'],
		);
	}

	/**
	 * Process a refund via Shoppex. Only Stripe / PayPal underlying gateways
	 * are supported by the Shoppex API; other PSPs return a validation error
	 * that is surfaced as an order note with a manual-refund hint.
	 *
	 * @param int        $order_id WC order ID.
	 * @param float|null $amount   Refund amount; null = full refund.
	 * @param string     $reason   Optional reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'shoppex_refund_no_order', __( 'Order not found.', 'shoppex-pay' ) );
		}

		$uniqid = (string) $order->get_meta( self::META_UNIQID );
		if ( '' === $uniqid ) {
			return new WP_Error(
				'shoppex_refund_no_uniqid',
				__( 'No Shoppex order reference on this order — cannot issue refund.', 'shoppex-pay' )
			);
		}

		$response = $this->api()->refund_order( $uniqid, $amount, $reason );

		if ( $response['ok'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: amount. */
					__( 'Shoppex Pay: refund issued for %s.', 'shoppex-pay' ),
					null === $amount ? __( 'full amount', 'shoppex-pay' ) : wc_price( $amount, array( 'currency' => $order->get_currency() ) )
				)
			);
			return true;
		}

		$error = $response['error'] ? $response['error'] : __( 'Unknown error', 'shoppex-pay' );
		if ( false !== stripos( $error, 'refunds are not supported' ) ) {
			$dashboard_link = sprintf(
				'<a href="https://dashboard.shoppex.io/store/orders/%s" target="_blank" rel="noopener">%s</a>',
				esc_attr( $uniqid ),
				esc_html__( 'Shoppex dashboard', 'shoppex-pay' )
			);
			$order->add_order_note(
				sprintf(
					/* translators: 1: gateway error message. 2: link to Shoppex dashboard. */
					__( 'Shoppex Pay: automatic refund not supported for this payment gateway (%1$s). Please issue the refund manually in the %2$s.', 'shoppex-pay' ),
					$error,
					$dashboard_link
				)
			);
			return new WP_Error( 'shoppex_refund_unsupported', $error );
		}

		return new WP_Error( 'shoppex_refund_failed', $error );
	}
}
