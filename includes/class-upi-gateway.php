<?php

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\OrderStatus;

defined( 'ABSPATH' ) or exit;

/**
 * UPI Payment Gateway.
 *
 * Provides UPI Payment Gateway.
 *
 * @class       WC_Gateway_Upi
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     WooCommerce\Classes\Payment
 */
class WC_Gateway_Upi extends WC_Payment_Gateway {

	/**
	 * Unique ID for this gateway.
	 *
	 * @var string
	 */
	const ID = UPI_NAME;

	/**
	 * Gateway instructions that will be added to the thank you page and emails.
	 *
	 * @var string
	 */
	public $instructions;

	/**
	 * Enable for shipping methods.
	 *
	 * @var array
	 */
	public $enable_for_methods;

	/**
	 * Enable for virtual products.
	 *
	 * @var bool
	 */
	public $enable_for_virtual;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		// Setup general properties.
		$this->setup_properties();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Get settings.
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->instructions       = $this->get_option( 'instructions' );
		$this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
		$this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );

		// Customer Emails.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Setup general properties for the gateway.
	 */
	protected function setup_properties() {
		$this->id                 = self::ID;
		$this->icon               = self::images()['icon'];
		$this->method_title       = __( 'UPI Payment', 'cfupi' );
		$this->method_description = __( 'Let your shoppers pay using UPI.', 'cfupi' );
		$this->has_fields         = false;
	}

	public static function images() {
		return apply_filters( 'woocommerce_upi_images', [ 'icon' => UPI_BASEURL . '/assets/upi.svg', 'all' => UPI_BASEURL . '/assets/upi-all.svg' ] );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'       => __( 'Enable/Disable', 'cfupi' ),
				'label'       => __( 'Enable UPI Payment', 'cfupi' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'        => array(
				'title'       => __( 'Title', 'cfupi' ),
				'type'        => 'safe_text',
				'description' => __( 'Payment method title that the customer will see on checkout page.', 'cfupi' ),
				'default'     => __( 'UPI Payment', 'cfupi' ),
				'desc_tip'    => true,
			),
			'description'  => array(
				'title'       => __( 'Description', 'cfupi' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on checkout page.', 'cfupi' ),
				'default'     => __( '', 'cfupi' ),
				'desc_tip'    => true,
			),
			'instructions' => array(
				'title'       => __( 'Instructions', 'cfupi' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page.', 'cfupi' ),
				'default'     => __( '<div class="upi-instructions woocommerce-notice">If UPI payment is successful, your order will be processed soon.</div>', 'cfupi' ),
				'desc_tip'    => true,
			),
			'upi_id'       => array(
				'title'       => __( 'UPI ID', 'cfupi' ),
				'type'        => 'safe_text',
				'description' => __( 'UPI ID to be converted to QR Code on checkout page.', 'cfupi' ),
				'desc_tip'    => true,
			),
			'upi_name'     => array(
				'title'       => __( 'UPI Name', 'cfupi' ),
				'type'        => 'safe_text',
				'description' => __( 'Name to be shown with the QR Code and Customer\'s UPI App.', 'cfupi' ),
				'desc_tip'    => true,
			),
			'timeout'      => array(
				'title'       => __( 'QR Code Timeout', 'cfupi' ),
				'type'        => 'number',
				'default'     => '60',
				'description' => __( 'Number of seconds QR Code window to be open before redirecting to Thank you page', 'cfupi' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// if ( $order->get_total() > 0 ) {
		// 	/**
		// 	 * Filter the order status for UPI orders.
		// 	 *
		// 	 * @since 2.6.0
		// 	 *
		// 	 * @param string $order_status Default status for UPI orders.
		// 	 */
		// 	$process_payment_status = apply_filters( 'woocommerce_upi_process_payment_order_status', $order->has_downloadable_item() ? OrderStatus::ON_HOLD : OrderStatus::PROCESSING, $order );
		// 	// Mark as processing or on-hold (payment won't be taken until delivery).
		// 	$order->update_status( $process_payment_status, __( 'Payment to be made upon delivery.', 'cfupi' ) );
		// } else {
		// 	$order->payment_complete();
		// }

		// // Remove cart.
		// WC()->cart->empty_cart();

		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Output for the order received page.
	 */
	public function thankyou_page() {
		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
		}
	}

	/**
	 * Change payment complete order status to completed for UPI orders.
	 *
	 * @since  3.1.0
	 * @param  string         $status Current order status.
	 * @param  int            $order_id Order ID.
	 * @param  WC_Order|false $order Order object.
	 * @return string
	 */
	public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
		if ( $order && self::ID === $order->get_payment_method() ) {
			$status = OrderStatus::COMPLETED;
		}
		return $status;
	}

	/**
	 * Add content to the WC emails.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin  Sent to admin.
	 * @param bool     $plain_text Email format: plain text or HTML.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
		}
	}
}
