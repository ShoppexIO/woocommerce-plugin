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
	const META_REFUND_PENDING = '_shoppex_refund_pending';
	const META_EVENTS         = '_shoppex_processed_events';
	const META_HELD_UNIQID    = '_shoppex_held_uniqid';

	/**
	 * The refund WooCommerce is creating in this request.
	 *
	 * @var WC_Order_Refund|null
	 */
	private static $current_refund = null;

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
	 * Checkout display: `redirect` (full page) or `overlay` (embed window).
	 *
	 * @var string
	 */
	public $checkout_display;

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
		$this->order_prefix   = (string) $this->get_option( 'order_prefix', 'WC Order #' );
		$this->debug          = 'yes' === $this->get_option( 'debug', 'no' );

		$this->checkout_display = 'overlay' === $this->get_option( 'checkout_display', 'redirect' ) ? 'overlay' : 'redirect';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	/**
	 * Admin settings schema.
	 */
	public function init_form_fields() {
		$webhook_url = add_query_arg( 'wc-api', 'shoppex_webhook', home_url( '/' ) );

		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'shoppex-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Shoppex Pay', 'shoppex-pay' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'shoppex-pay' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'shoppex-pay' ),
				'default'     => __( 'Shoppex Pay', 'shoppex-pay' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'shoppex-pay' ),
				'type'        => 'textarea',
				'description' => __( 'Shown below the title at checkout.', 'shoppex-pay' ),
				'default'     => __( 'Pay with credit card, PayPal, cryptocurrencies, and more via Shoppex.', 'shoppex-pay' ),
			),
			'api_key'          => array(
				'title'       => __( 'API Key', 'shoppex-pay' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: link to the Shoppex developer dashboard. */
					__( 'Your Shoppex Dev API key with the payments.write and orders.write scopes. Create one in the %s.', 'shoppex-pay' ),
					'<a href="https://dashboard.shoppex.io/settings/developer/api-keys" target="_blank" rel="noopener">Shoppex dashboard</a>'
				),
				'default'     => '',
			),
			'webhook_secret'   => array(
				'title'       => __( 'Webhook Secret', 'shoppex-pay' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: 1: webhook URL, 2: link to the Shoppex webhook settings. */
					__( 'Secret of your Shoppex webhook. Register %1$s in the %2$s for order:paid, order:partial, order:cancelled, order:disputed and order:updated.', 'shoppex-pay' ),
					'<code>' . esc_html( $webhook_url ) . '</code>',
					'<a href="https://dashboard.shoppex.io/settings/developer/webhooks" target="_blank" rel="noopener">Shoppex dashboard</a>'
				),
				'default'     => '',
			),
			'checkout_display' => array(
				'title'       => __( 'Checkout display', 'shoppex-pay' ),
				'type'        => 'select',
				'description' => __( 'Overlay window opens the Shoppex payment in a window on your order payment page. The customer can always continue on the full payment page instead.', 'shoppex-pay' ),
				'default'     => 'redirect',
				'options'     => array(
					'redirect' => __( 'Redirect', 'shoppex-pay' ),
					'overlay'  => __( 'Overlay window', 'shoppex-pay' ),
				),
			),
			'order_prefix'     => array(
				'title'       => __( 'Order title prefix', 'shoppex-pay' ),
				'type'        => 'text',
				'description' => __( 'Prefix shown on the Shoppex hosted checkout page, e.g. "WC Order #" will display "WC Order #1234".', 'shoppex-pay' ),
				'default'     => 'WC Order #',
				'desc_tip'    => true,
			),
			'debug'            => array(
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
		);

		/**
		 * Filter the checkout session payload before it is sent to Shoppex.
		 *
		 * @param array    $payload Checkout session payload.
		 * @param WC_Order $order   Current WC order.
		 */
		$payload = apply_filters( 'shoppex_pay_checkout_session_payload', $payload, $order );

		// A retry of a create whose answer never arrived replays that session.
		// Any later attempt for the order gets a new one: Shoppex refuses a
		// reused key with a changed body (WooCommerce reuses a pending order
		// when the cart changes) and would replay an expired session for 24h.
		$idempotency_key = 'woo_order_' . $order->get_id() . '_' . substr(
			hash( 'sha256', (string) $order->get_meta( self::META_UNIQID ) . '|' . wp_json_encode( $payload ) ),
			0,
			24
		);
		$response        = $this->api()->create_checkout_session( $payload, $idempotency_key );

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
			// The order payment page opens the overlay and links the full page.
			'redirect' => 'overlay' === $this->checkout_display ? $order->get_checkout_payment_url( true ) : $data['url'],
		);
	}

	/**
	 * Order payment page in overlay mode: open the Shoppex invoice in the embed
	 * window. The page keeps a link to the full payment page, and sends the
	 * buyer there when the embed script cannot open an invoice. Payment status
	 * still comes only from the signed webhook.
	 *
	 * @param int $order_id WC order ID.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$uniqid      = (string) $order->get_meta( self::META_UNIQID );
		$session_url = (string) $order->get_meta( self::META_SESSION_URL );
		if ( ! $order->needs_payment() || '' === $uniqid || '' === $session_url ) {
			return;
		}

		/**
		 * Filter the Shoppex checkout origin the overlay loads. Production default
		 * is `https://checkout.shoppex.io`. Override only for local development.
		 *
		 * @param string $checkout_base Base URL without trailing slash.
		 */
		$checkout_base = untrailingslashit( apply_filters( 'shoppex_pay_checkout_base', SHOPPEX_PAY_CHECKOUT_BASE ) );

		// The embed script URL is mutable by design (no version, no SRI).
		wp_enqueue_script( 'shoppex-embed', $checkout_base . '/embed/embed.iife.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'shoppex-pay-overlay', SHOPPEX_PAY_PLUGIN_URL . 'assets/js/overlay.js', array( 'shoppex-embed' ), SHOPPEX_PAY_VERSION, true );
		wp_add_inline_script(
			'shoppex-pay-overlay',
			'window.shoppexPayOverlay = ' . wp_json_encode(
				array(
					'invoiceId'       => $uniqid,
					'checkoutBaseUrl' => $checkout_base,
					'paymentUrl'      => $session_url,
					'returnUrl'       => $this->get_return_url( $order ),
				)
			) . ';',
			'before'
		);
		?>
		<div id="shoppex-pay-overlay">
			<p data-shoppex-pay-redirecting hidden role="status"><?php esc_html_e( 'Redirecting you to the secure payment page…', 'shoppex-pay' ); ?></p>
			<button type="button" class="button alt" data-shoppex-pay-open><?php esc_html_e( 'Pay now', 'shoppex-pay' ); ?></button>
			<a class="button" href="<?php echo esc_url( $session_url ); ?>"><?php esc_html_e( 'Open the payment page instead', 'shoppex-pay' ); ?></a>
		</div>
		<?php
	}

	/**
	 * `woocommerce_create_refund` hook: remember the refund WooCommerce is about
	 * to save and send to process_refund().
	 *
	 * @param WC_Order_Refund|null $refund Refund being created.
	 */
	public static function remember_refund( $refund ) {
		self::$current_refund = $refund;
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

		// An order held for a short or foreign-currency payment was paid through
		// that invoice, which need not be the order's newest checkout session.
		$uniqid = (string) $order->get_meta( self::META_HELD_UNIQID );
		$uniqid = '' === $uniqid ? (string) $order->get_meta( self::META_UNIQID ) : $uniqid;
		if ( '' === $uniqid ) {
			return new WP_Error(
				'shoppex_refund_no_uniqid',
				__( 'No Shoppex order reference on this order — cannot issue refund.', 'shoppex-pay' )
			);
		}

		// WooCommerce saved this refund right before it called the gateway. Its id
		// never repeats, so a refund recorded again after the merchant deleted
		// the first record is sent as a new refund, not replayed.
		$refund               = self::$current_refund;
		self::$current_refund = null;
		if ( ! $refund || (int) $refund->get_parent_id() !== (int) $order->get_id() || ! $refund->get_id() ) {
			return new WP_Error( 'shoppex_refund_no_refund', __( 'Create the refund in WooCommerce to send it through Shoppex Pay.', 'shoppex-pay' ) );
		}

		// Shoppex takes at most two decimals, whatever the store's price precision.
		$amount = null === $amount ? null : wc_format_decimal( $amount, 2 );
		$slot   = null === $amount ? 'full' : $amount;
		// A refund whose answer was lost may have gone through, and WooCommerce
		// deleted its record. A retry of that amount reuses the lost key within
		// Shoppex's 24-hour replay window, so Shoppex replays that refund (or
		// answers that it is still processing, or refuses a changed reason)
		// instead of paying twice. Only a success, replayed or new, settles it.
		$pending = $order->get_meta( self::META_REFUND_PENDING );
		$pending = is_array( $pending ) ? $pending : array();
		foreach ( $pending as $lost_slot => $lost ) {
			if ( $lost['at'] <= time() - DAY_IN_SECONDS ) {
				unset( $pending[ $lost_slot ] );
			}
		}
		$retry = isset( $pending[ $slot ] );
		$key   = $retry ? $pending[ $slot ]['key'] : 'woo_refund_' . $uniqid . '_' . $refund->get_id();

		$response = $this->api()->refund_order( $uniqid, $amount, $reason, $key );

		if ( $retry && $response['ok'] ) {
			unset( $pending[ $slot ] );
		} elseif ( ! $retry && ( 0 === $response['status'] || $response['status'] >= 500 ) ) {
			$pending[ $slot ] = array(
				'key' => $key,
				'at'  => time(),
			);
		}
		$order->update_meta_data( self::META_REFUND_PENDING, $pending );
		$order->save();

		// The replayed refund may already be on this order: the merchant can
		// record a lost refund by hand. Recording it again would show more
		// refunded than the buyer got. The slot is settled, so the next attempt
		// sends a new refund.
		if ( $response['ok'] && 'cached' === $response['idempotency_status'] ) {
			$replayed = sprintf(
				/* translators: %s: amount. */
				__( 'Shoppex Pay: Shoppex had already refunded %s for an earlier attempt whose answer was lost, so no new refund was sent. If that refund is not recorded on this order yet, record it with "Refund manually". Otherwise create the refund again to send a new one.', 'shoppex-pay' ),
				// Plain text: WooCommerce shows the error in a browser alert.
				null === $amount ? __( 'the full amount', 'shoppex-pay' ) : $amount . ' ' . $order->get_currency()
			);
			$order->add_order_note( $replayed );
			return new WP_Error( 'shoppex_refund_replayed', $replayed );
		}

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
				'<a href="https://dashboard.shoppex.io/invoices/%s" target="_blank" rel="noopener">%s</a>',
				rawurlencode( $uniqid ),
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
