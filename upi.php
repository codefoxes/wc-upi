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
 * Register REST API routes for decoupled React frontend and external UPI webhook.
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

	register_rest_route( 'upi/v1', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'cf_upi_rest_webhook',
		'permission_callback' => '__return_true',
	) );

	register_rest_route( 'upi/v1', '/check-status', array(
		'methods'             => 'POST',
		'callback'            => 'cf_upi_rest_check_status',
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

/**
 * Create or update {prefix}_upi_payments table.
 */
function cf_upi_create_tables() {
	global $wpdb;
	$table_name      = $wpdb->prefix . 'upi_payments';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		title varchar(255) NOT NULL DEFAULT '',
		message text NOT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY created_at (created_at)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
register_activation_hook( __FILE__, 'cf_upi_create_tables' );

/**
 * Ensure {prefix}_upi_payments exists on runtime.
 */
function cf_upi_ensure_tables() {
	static $checked = false;
	if ( $checked ) {
		return;
	}
	global $wpdb;
	$table_name = $wpdb->prefix . 'upi_payments';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
		cf_upi_create_tables();
	}
	$checked = true;
}

/**
 * Delete records older than 5 minutes from {prefix}_upi_payments.
 */
function cf_upi_purge_expired_payments() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'upi_payments';
	cf_upi_ensure_tables();
	$cutoff = date( 'Y-m-d H:i:s', strtotime( '-5 minutes', current_time( 'timestamp' ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE created_at < %s", $cutoff ) );
}

/**
 * Webhook receiver for external UPI notifications.
 *
 * Checks hardcoded secret key header, accepts { t: "title", m: "message" },
 * deletes records older than 5 minutes, and saves new message.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function cf_upi_rest_webhook( WP_REST_Request $request ) {
	$settings     = get_option( 'woocommerce_upi_settings', array() );
	$expected_key = ! empty( $settings['webhook_key'] ) ? $settings['webhook_key'] : ( defined( 'UPI_WEBHOOK_KEY' ) ? UPI_WEBHOOK_KEY : '92Y^6BY$tLY%hE$SYDZv' );

	// Check key header across common header name variations
	$provided_key = $request->get_header( 'x-webhook-key' );
	if ( empty( $provided_key ) ) {
		$provided_key = $request->get_header( 'x-upi-key' );
	}
	if ( empty( $provided_key ) ) {
		$provided_key = $request->get_header( 'secret-key' );
	}
	if ( empty( $provided_key ) ) {
		$provided_key = $request->get_header( 'x-api-key' );
	}

	if ( empty( $provided_key ) || ! hash_equals( $expected_key, trim( $provided_key ) ) ) {
		return new WP_Error( 'unauthorized', __( 'Invalid or missing webhook key header.', 'cfupi' ), array( 'status' => 401 ) );
	}

	$params = $request->get_json_params();
	if ( empty( $params ) ) {
		$params = $request->get_params();
	}

	$title   = sanitize_text_field( $params['t'] ?? '' );
	$message = wp_unslash( $params['m'] ?? '' );

	if ( empty( $message ) ) {
		return new WP_Error( 'missing_message', __( 'Field "m" (message) is required.', 'cfupi' ), array( 'status' => 400 ) );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'upi_payments';
	cf_upi_ensure_tables();

	// Delete entries older than 5 minutes
	cf_upi_purge_expired_payments();

	// Insert received webhook notification
	$inserted = $wpdb->insert(
		$table_name,
		array(
			'title'      => $title,
			'message'    => $message,
			'created_at' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'db_error', __( 'Failed to save webhook payment entry.', 'cfupi' ), array( 'status' => 500 ) );
	}

	return rest_ensure_response( array(
		'success' => true,
		'id'      => $wpdb->insert_id,
		'message' => __( 'Webhook received and logged successfully.', 'cfupi' ),
	) );
}

/**
 * Check payment status for an order by searching latest 5-min webhook entries for exact amount.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function cf_upi_rest_check_status( WP_REST_Request $request ) {
	$order_id  = absint( $request->get_param( 'order_id' ) );
	$order_key = sanitize_text_field( $request->get_param( 'order_key' ) );
	$amount    = sanitize_text_field( $request->get_param( 'amount' ) );

	if ( empty( $order_id ) ) {
		return new WP_Error( 'missing_fields', __( 'Order ID is required.', 'cfupi' ), array( 'status' => 400 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return new WP_Error( 'invalid_order', __( 'Order not found.', 'cfupi' ), array( 'status' => 404 ) );
	}

	// Verify order key matches if provided
	if ( ! empty( $order_key ) && ! hash_equals( $order->get_order_key(), $order_key ) ) {
		return new WP_Error( 'unauthorized', __( 'Invalid order verification key.', 'cfupi' ), array( 'status' => 403 ) );
	}

	// If order has already been marked paid, return success immediately
	if ( $order->is_paid() || in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
		return rest_ensure_response( array(
			'success'  => true,
			'paid'     => true,
			'order_id' => $order->get_id(),
			'status'   => $order->get_status(),
			'message'  => __( 'Order is already marked as paid.', 'cfupi' ),
		) );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'upi_payments';
	cf_upi_ensure_tables();

	// Clean up entries older than 5 minutes
	cf_upi_purge_expired_payments();

	// Build amount matching candidates (e.g. "299.00" and "299")
	$clean_amount       = trim( $amount );
	$amount_candidates  = array();
	if ( ! empty( $clean_amount ) ) {
		$amount_candidates[] = $clean_amount;
		if ( strpos( $clean_amount, '.' ) !== false ) {
			$int_part = rtrim( rtrim( $clean_amount, '0' ), '.' );
			if ( ! in_array( $int_part, $amount_candidates, true ) ) {
				$amount_candidates[] = $int_part;
			}
		} else {
			$amount_candidates[] = number_format( (float) $clean_amount, 2, '.', '' );
		}
	} else {
		$order_total         = $order->get_total();
		$amount_candidates[] = number_format( (float) $order_total, 2, '.', '' );
		$amount_candidates[] = (string) ( (float) $order_total );
	}

	// Fetch all latest entries within the 5-minute window
	$cutoff = date( 'Y-m-d H:i:s', strtotime( '-5 minutes', current_time( 'timestamp' ) ) );
	$rows   = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, title, message FROM {$table_name} WHERE created_at >= %s ORDER BY id DESC",
		$cutoff
	) );

	$matched_row = null;
	if ( ! empty( $rows ) ) {
		foreach ( $rows as $row ) {
			foreach ( $amount_candidates as $cand ) {
				if ( ! empty( $cand ) && strpos( $row->message, $cand ) !== false ) {
					$matched_row = $row;
					break 2;
				}
			}
		}
	}

	if ( $matched_row ) {
		// Treat order as paid
		$order->payment_complete( 'auto_verif_' . $matched_row->id );
		$order->add_order_note( sprintf(
			__( 'UPI payment auto-verified via webhook match: "%s" (Title: %s)', 'cfupi' ),
			$matched_row->message,
			$matched_row->title
		) );
		$order->update_meta_data( '_upi_auto_verified', 'yes' );
		$order->update_meta_data( '_upi_webhook_matched_id', $matched_row->id );
		$order->save();

		// Remove matched entry so it cannot be matched again
		$wpdb->delete( $table_name, array( 'id' => $matched_row->id ), array( '%d' ) );

		return rest_ensure_response( array(
			'success'  => true,
			'paid'     => true,
			'order_id' => $order->get_id(),
			'status'   => $order->get_status(),
			'message'  => __( 'Payment confirmed via webhook match.', 'cfupi' ),
		) );
	}

	return rest_ensure_response( array(
		'success'  => true,
		'paid'     => false,
		'order_id' => $order->get_id(),
		'status'   => $order->get_status(),
		'message'  => __( 'Payment not detected yet.', 'cfupi' ),
	) );
}

