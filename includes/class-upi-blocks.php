<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Upi Payments Blocks integration
 *
 * @since 1.0.3
 */
final class WC_Upi_Blocks extends AbstractPaymentMethodType {

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_Upi
	 */
	private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = UPI_NAME;

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_upi_settings', [] );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];

		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'process_block_payment' ], 10, 2 );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_deps  = UPI_BASEPATH . 'assets/js/blocks.asset.php';
		$script_asset = file_exists( $script_deps ) ? require( $script_deps ) : [ 'dependencies' => [], 'version' => UPI_VERSION ];

		wp_register_script(
			'wc-upi-blocks',
			UPI_BASEURL . '/assets/js/blocks.js',
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-upi-blocks', 'cfupi', UPI_BASEPATH . 'languages/' );
		}

		return [ 'wc-upi-blocks' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return [
			'options'  => $this->settings,
			'amount'   => isset( WC()->cart->total ) ? WC()->cart->total : 0,
			'features' => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
			'images'   => WC_Gateway_Upi::images(),
		];
	}

	public function process_block_payment( $context, $result ) {
		if ( $context->payment_method === UPI_NAME ) {
			$upiData = $context->payment_data['upiData'];
			// Here we would use the $myGatewayCustomData to process the payment

			$result->set_status( 'success' );
			$result->set_payment_details(
				array_merge(
					$result->payment_details,
					[ 'redirect' => $context->order->get_checkout_order_received_url() ]
				)
			);
			// $result->set_redirect_url( $context->order->get_checkout_order_received_url() );
		}
	}
}
