<?php
/**
 * Minimal WordPress / WooCommerce doubles, so the plugin classes run under
 * PHPUnit without a WordPress install. Only what the plugin calls exists here.
 *
 * @package Shoppex_Pay
 */

// phpcs:ignoreFile -- test doubles mirror WordPress names and signatures.

define( 'ABSPATH', __DIR__ . '/' );
define( 'SHOPPEX_PAY_VERSION', 'test' );
define( 'SHOPPEX_PAY_PLUGIN_URL', 'https://store.test/wp-content/plugins/shoppex-pay/' );
define( 'SHOPPEX_PAY_API_BASE', 'https://api.shoppex.io' );
define( 'SHOPPEX_PAY_CHECKOUT_BASE', 'https://checkout.shoppex.io' );
define( 'DAY_IN_SECONDS', 86400 );

final class Test_State {
	/** @var array<string, mixed> */
	public static $options = array();
	/** @var array<int, WC_Order> */
	public static $orders = array();
	/** @var array<int, array> */
	public static $requests = array();
	/** @var array<int, array> */
	public static $responses = array();
	/** @var array<int, array> */
	public static $scripts = array();
	/** @var array<int, array> */
	public static $inline_scripts = array();
	/** @var array<int, string> */
	public static $notices = array();
	/** @var string[] What `woocommerce_order_is_paid_statuses` returns. */
	public static $paid_statuses = array( 'processing', 'completed' );

	public static function reset() {
		self::$options        = array();
		self::$orders         = array();
		self::$requests       = array();
		self::$responses      = array();
		self::$scripts        = array();
		self::$inline_scripts = array();
		self::$notices        = array();
		self::$paid_statuses  = array( 'processing', 'completed' );
	}
}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html_e( $text, $domain = null ) { echo htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function apply_filters( $tag, $value, ...$args ) { return $value; }
function home_url( $path = '' ) { return 'https://store.test' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wc_get_is_paid_statuses() { return Test_State::$paid_statuses; }
function wc_price( $amount, $args = array() ) { return (string) $amount; }
function wc_format_decimal( $number, $dp = false ) { return number_format( round( (float) $number, (int) $dp ), (int) $dp, '.', '' ); }
function wc_add_notice( $message, $type = 'success' ) { Test_State::$notices[] = $message; }
function wc_get_order( $id ) { return Test_State::$orders[ (int) $id ] ?? false; }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	Test_State::$scripts[] = compact( 'handle', 'src', 'deps', 'ver' );
}
function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	Test_State::$inline_scripts[] = compact( 'handle', 'data', 'position' );
}
function wp_remote_request( $url, $args ) {
	Test_State::$requests[] = array( 'url' => $url ) + $args;
	return array_shift( Test_State::$responses ) ?? new WP_Error( 'no_response', 'No queued response' );
}
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return json_encode( $response['body'] ); }

class WC_Payment_Gateway {
	public $id;
	public $method_title;
	public $method_description;
	public $has_fields;
	public $icon;
	public $supports = array();
	public $title;
	public $description;
	public $form_fields = array();
	public $settings    = array();

	public function init_settings() {
		$this->settings = Test_State::$options[ 'woocommerce_' . $this->id . '_settings' ] ?? array();
	}

	public function get_option( $key, $empty_value = null ) {
		if ( isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}
		return $this->form_fields[ $key ]['default'] ?? $empty_value;
	}

	public function get_return_url( $order ) {
		return 'https://store.test/checkout/order-received/' . $order->get_id() . '/?key=' . $order->get_order_key();
	}
}

class WC_Order {
	public $id;
	public $order_key;
	public $currency;
	public $total;
	public $status          = 'pending';
	public $meta            = array();
	public $notes           = array();
	public $transaction_id  = '';
	public $payment_completions = 0;
	public $saves           = 0;

	public function __construct( $id, $total = '49.99', $currency = 'EUR' ) {
		$this->id        = $id;
		$this->order_key = 'wc_order_key_' . $id;
		$this->total     = $total;
		$this->currency  = $currency;
	}

	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_order_key() { return $this->order_key; }
	public function get_currency() { return $this->currency; }
	public function get_total() { return $this->total; }
	public function get_billing_email() { return 'buyer@example.com'; }
	public function get_cancel_order_url_raw() { return 'https://store.test/cart/?cancel_order=true&order_id=' . $this->id; }
	public function get_checkout_payment_url( $on_checkout = false ) {
		return 'https://store.test/checkout/order-pay/' . $this->id . '/?key=' . $this->order_key;
	}
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save() { ++$this->saves; }
	public function get_status() { return $this->status; }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
	public function is_paid() { return $this->has_status( wc_get_is_paid_statuses() ); }
	public function needs_payment() { return $this->has_status( array( 'pending', 'failed' ) ); }
	public function get_transaction_id() { return $this->transaction_id; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}
	public function payment_complete( $transaction_id = '' ) {
		++$this->payment_completions;
		$this->transaction_id = $transaction_id;
		$this->status         = 'processing';
	}
}

class WC_Order_Refund {
	public $id;
	public $parent_id;
	public function __construct( $id, $parent_id ) {
		$this->id        = $id;
		$this->parent_id = $parent_id;
	}
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent_id; }
}

require dirname( __DIR__, 2 ) . '/shoppex-pay/includes/class-shoppex-api-client.php';
require dirname( __DIR__, 2 ) . '/shoppex-pay/includes/class-shoppex-webhook-handler.php';
require dirname( __DIR__, 2 ) . '/shoppex-pay/includes/class-wc-gateway-shoppex.php';
