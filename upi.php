<?php
/*
 * Plugin Name: UPI Payments
 * Description: UPI Payment gateway
 * Version: 1.0.0
 * Author: Karthik
 * text-domain: cfupi
 */

defined( 'ABSPATH' ) or exit;

define( 'UPI_VERSION', '1.0.0' );
define( 'UPI_BASEPATH', plugin_dir_path( __FILE__ ) );
define( 'UPI_BASEURL', plugins_url( '/', __FILE__ ) );
define( 'UPI_NAME', 'upi' );

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + upi gateway
 */
function cf_upi_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_Upi';
	return $gateways;
}

/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function cf_upi_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=upi' ) . '">' . __( 'Configure', 'cfupi' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}

/**
 * Init UPI Payment gateway logic.
 */
function cf_upi_init() {
	require_once UPI_BASEPATH . '/includes/class-upi-gateway.php';
}

function cf_upi_block_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once 'includes/class-upi-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new WC_Upi_Blocks() );
			},
		);
	}
}

function cf_upi_scripts() {
	if ( is_checkout() ) {
		wp_enqueue_style( 'wc-upi', UPI_BASEURL . '/assets/css/blocks.css', [], UPI_VERSION );
	}
}

function cf_order_received_actions( $actions, $order ) {
	if ( ! is_wc_endpoint_url( 'order-received' ) ) return $actions;
	if ( $order->data['payment_method'] === UPI_NAME ) {
		unset( $actions['pay'] );
		unset( $actions['cancel'] );
	}
	return $actions;
}

add_filter( 'woocommerce_payment_gateways', 'cf_upi_add_gateway' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cf_upi_plugin_links' );
add_filter( 'woocommerce_my_account_my_orders_actions', 'cf_order_received_actions', 10, 2 );

add_action( 'plugins_loaded', 'cf_upi_init', 0 );
add_action( 'woocommerce_blocks_loaded', 'cf_upi_block_support' );
add_action( 'wp_enqueue_scripts', 'cf_upi_scripts', 20 );

/**
 * Register REST API routes for decoupled React frontend.
 */
function cf_upi_register_rest_routes() {
	register_rest_route( 'upi/v1', '/config', array(
		'methods'             => 'GET',
		'callback'            => 'cf_upi_rest_config',
		'permission_callback' => '__return_true',
	) );

	register_rest_route( 'upi/v1', '/confirm', array(
		'methods'             => 'POST',
		'callback'            => 'cf_upi_rest_confirm',
		'permission_callback' => '__return_true',
	) );
}
add_action( 'rest_api_init', 'cf_upi_register_rest_routes' );

/**
 * Get UPI gateway config for frontend.
 */
function cf_upi_rest_config() {
	$settings = get_option( 'woocommerce_upi_settings', array() );
	$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : array();
	$gateway  = isset( $gateways[ UPI_NAME ] ) ? $gateways[ UPI_NAME ] : null;
	$enabled  = $gateway ? $gateway->is_available() : ( ( isset( $settings['enabled'] ) && $settings['enabled'] === 'yes' ) );

	return rest_ensure_response( array(
		'enabled'      => (bool) $enabled,
		'title'        => isset( $settings['title'] ) && ! empty( $settings['title'] ) ? $settings['title'] : 'UPI Payment',
		'description'  => isset( $settings['description'] ) ? $settings['description'] : '',
		'instructions' => isset( $settings['instructions'] ) ? $settings['instructions'] : '',
		'upi_id'       => isset( $settings['upi_id'] ) ? $settings['upi_id'] : '',
		'upi_name'     => isset( $settings['upi_name'] ) && ! empty( $settings['upi_name'] ) ? $settings['upi_name'] : get_bloginfo( 'name' ),
		'timeout'      => isset( $settings['timeout'] ) && is_numeric( $settings['timeout'] ) ? (int) $settings['timeout'] : 300,
		'icons'        => class_exists( 'WC_Gateway_Upi' ) ? WC_Gateway_Upi::images() : array(
			'icon' => UPI_BASEURL . '/assets/upi.svg',
			'all'  => UPI_BASEURL . '/assets/upi-all.svg',
		),
	) );
}

/**
 * Confirm UPI payment with transaction ID / UTR from frontend.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function cf_upi_rest_confirm( WP_REST_Request $request ) {
	$order_id       = absint( $request->get_param( 'order_id' ) );
	$order_key      = sanitize_text_field( $request->get_param( 'order_key' ) );
	$transaction_id = sanitize_text_field( $request->get_param( 'transaction_id' ) );
	$customer_vpa   = sanitize_text_field( $request->get_param( 'customer_vpa' ) );

	if ( empty( $order_id ) || empty( $order_key ) ) {
		return new WP_Error( 'missing_fields', __( 'Order ID and Order Key are required.', 'cfupi' ), array( 'status' => 400 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return new WP_Error( 'invalid_order', __( 'Order not found.', 'cfupi' ), array( 'status' => 404 ) );
	}

	// Verify order key matches for security
	if ( $order->get_order_key() !== $order_key ) {
		return new WP_Error( 'unauthorized', __( 'Invalid order verification key.', 'cfupi' ), array( 'status' => 403 ) );
	}

	// Clean up transaction ID
	$transaction_id = preg_replace( '/\s+/', '', $transaction_id );

	if ( empty( $transaction_id ) ) {
		return new WP_Error( 'missing_transaction_id', __( 'Transaction / UTR Reference number is required.', 'cfupi' ), array( 'status' => 400 ) );
	}

	// Save transaction reference
	$order->set_transaction_id( $transaction_id );
	$order->update_meta_data( '_upi_transaction_id', $transaction_id );
	if ( ! empty( $customer_vpa ) ) {
		$order->update_meta_data( '_transaction_upi_id', $customer_vpa );
	}
	$order->update_meta_data( '_upi_payment_confirmed_at', current_time( 'mysql' ) );

	// Add order note
	$note = sprintf( __( 'Customer submitted UPI payment. UTR/Ref: %s', 'cfupi' ), $transaction_id );
	if ( ! empty( $customer_vpa ) ) {
		$note .= sprintf( ' (Payer VPA: %s)', $customer_vpa );
	}
	$order->add_order_note( $note, false );

	// Transition status to on-hold for admin verification
	$order->update_status( 'on-hold', __( 'Awaiting merchant verification of UPI payment.', 'cfupi' ) );
	$order->save();

	return rest_ensure_response( array(
		'success'  => true,
		'order_id' => $order->get_id(),
		'status'   => $order->get_status(),
		'message'  => __( 'Payment details recorded successfully.', 'cfupi' ),
	) );
}

