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
