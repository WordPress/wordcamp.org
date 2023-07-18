<?php
/**
 * Renders a different payment options layout, which shows Stripe as the preferred option
 */

namespace WordCamp\CampTix_Tweaks;

defined( 'WPINC' ) || die();

use CampTix_Addon;

/**
 * Class Payment_Options
 *
 * Renders payment options with a preference for Stripe
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Payment_Options extends CampTix_Addon {

	/**
	 * Initialize Payment_Options class
	 */
	public function camptix_init() {
		add_filter( 'tix_render_payment_options', array( $this, 'generate_payment_options' ), 15, 4 );
		$this->enqueue_scripts_and_styles();
	}

	/**
	 * Enqueue styles and scripts needed for the addon to work
	 */
	public function enqueue_scripts_and_styles() {
		wp_register_script(
			'payment_options',
			plugins_url( 'js/payment-options.js', __FILE__ ),
			array( 'stripe-checkout', 'camptix' ),
			filemtime( __DIR__ . '/js/payment-options.js' ),
			true
		);

		wp_register_style(
			'payment_options',
			plugins_url( 'css/payment-options.css', __FILE__ ),
			array(),
			filemtime( __DIR__ . '/css/payment-options.css' )
		);

		wp_enqueue_script( 'payment_options' );
		wp_enqueue_style( 'payment_options' );
	}

	/**
	 * We have stripe selected when there is no selected payment method, or when stripe is already selected
	 *
	 * @param array  $payment_methods
	 * @param string $selected_payment_method
	 *
	 * @return bool
	 */
	private function has_stripe_selected( $payment_methods, $selected_payment_method ) {
		return array_key_exists( 'stripe', $payment_methods ) && ( ! isset( $selected_payment_method ) || 'stripe' === $selected_payment_method );
	}

	/**
	 * Filter implementation for new payment layout.
	 *
	 * @param array  $payment_output Not needed since we are generating a new layout.
	 * @param float  $total Total amount.
	 * @param array  $payment_methods List of payment methods.
	 * @param string $selected_payment_method Already selected payment method.
	 */
	public function generate_payment_options( $payment_output, $total, $payment_methods, $selected_payment_method ) {
		ob_start();
		?>
		<div class="tix-submit">
			<?php if ( $total > 0 ) : ?>
				<div class="tix-payment-method">
					<?php $this->render_tab_bar( $payment_methods, $selected_payment_method ); ?>
				</div>
				<div
					class="tix-payment-method-container
					<?php
					if (
						$this->only_one_payment_method( $payment_methods ) ||
						$this->has_stripe_selected( $payment_methods, $selected_payment_method )
					) {
						echo 'tix-hidden ';
					}
					echo ! $this->is_stripe_available( $payment_methods ) ? 'tix-wide-tab' : '';
					?>"
					id="tix-payment-options-list"
				>
					<fieldset>
						<legend class="screen-reader-text">
							<?php esc_html_e( 'Payment methods', 'wordcamporg' ); ?>
						</legend>
						<?php $this->render_alternate_payment_options( $payment_methods, $selected_payment_method ); ?>
					</fieldset>
				</div>
				<input class="tix-checkout-button" type="submit" value="<?php esc_attr_e( 'Checkout &rarr;', 'wordcamporg' ); ?>" />
			<?php else : ?>
				<input class="tix-checkout-button" type="submit" value="<?php esc_attr_e( 'Claim Tickets &rarr;', 'wordcamporg' ); ?>" />
			<?php endif; ?>
			<br class="tix-clear" />
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a payment option as a separate tab. Used for rendering stripe tab, or a payment method tab when
	 * only 1 is available.
	 *
	 * @param array  $payment_methods
	 * @param string $key
	 * @param bool   $selected Whether this option is pre selected.
	 */
	private function render_payment_option_as_tab( $payment_methods, $key, $selected ) {
		$is_only_payment_option = $this->only_one_payment_method( $payment_methods );
		if ( $is_only_payment_option ) : ?>
			<input
				type="radio"
				name="tix_payment_method"
				value="<?php echo esc_html( $key ); ?>"
				checked="checked"
				style="display:none;"
			/>
			<div class="tix-payment-tab tix-wide-tab tix-tab-selected">
				<?php
					// translators: %s: Name of the available payment method.
					printf( esc_html__( 'Pay with %s', 'wordcamporg' ), esc_html( $payment_methods[ $key ]['name'] ) );
				?>
			</div>
		<?php else : ?>
			<input type="radio" name="tix_payment_method" id="tix-preferred-payment-option"
				style="display:none;"
				autocomplete="off"
				value="<?php echo esc_html( $key ); ?>"
				<?php checked( $selected ); ?>
			/>
			<button
				type="button"
				aria-pressed="true"
				tabindex="0"
				class="tix-payment-tab tix-preferred-payment-option <?php echo $selected ? ' tix-tab-selected' : ''; ?>">
				<?php
					// translators: %s: Name of the available payment method.
					printf( esc_html__( 'Pay with %s', 'wordcamporg' ), esc_html( $payment_methods[ $key ]['name'] ) );
				?>
			</button>
		<?php endif;
	}

	/**
	 * Renders tab of the payment options layout. Stripe is rendered as a different tab and is actually a label of an
	 * input
	 *
	 * @param array  $payment_methods
	 * @param string $selected_payment_method Pre selected payment method.
	 */
	public function render_tab_bar( $payment_methods, $selected_payment_method ) {

		if ( $this->only_one_payment_method( $payment_methods ) ) {
			// render payment option as a tab and bail.
			$payment_method_key = array_keys( $payment_methods )[0];
			$this->render_payment_option_as_tab( $payment_methods, $payment_method_key, true );
			return;
		}

		if ( $this->is_stripe_available( $payment_methods ) ) {
			$this->render_payment_option_as_tab(
				$payment_methods,
				'stripe',
				$this->has_stripe_selected( $payment_methods, $selected_payment_method )
			);
			?>
			<button
				type="button"
				aria-pressed="false"
				class="tix_other_payment_options tix-payment-tab
				<?php
					echo ! $this->has_stripe_selected( $payment_methods, $selected_payment_method ) ? 'tix-tab-selected ' : '';
				?>"
			>
				<?php esc_html_e( 'All payment methods', 'wordcamporg' ); ?>
			</button>
			<?php
		} else {
			?>
			<div class="tix_other_payment_options tix-payment-tab tix-wide-tab tix-tab-selected">
				<?php esc_html_e( 'Payment methods', 'wordcamporg' ); ?>
			</div>
			<?php
		}
	}

	/**
	 * Utility method to check if stripe payment gateway is enabled
	 *
	 * @param array $payment_methods
	 *
	 * @return bool
	 */
	private function is_stripe_available( $payment_methods ) {
		return array_key_exists( 'stripe', $payment_methods );
	}

	/**
	 * Utility function to check if only payment method is available
	 *
	 * @param array $payment_methods
	 *
	 * @return bool
	 */
	private function only_one_payment_method( $payment_methods ) {
		return count( $payment_methods ) === 1;
	}

	/**
	 * Renders all other payment methods except stripe
	 *
	 * @param array  $payment_methods
	 * @param string $selected_payment_method Pre selected payment method.
	 */
	public function render_alternate_payment_options( $payment_methods, $selected_payment_method ) {
		if ( $this->only_one_payment_method( $payment_methods ) ) {
			// bail if only one payment option is available.
			return;
		}

		// Pre-select first payment method if stripe is not available.
		if ( ! $this->is_stripe_available( $payment_methods ) && ! isset( $selected_payment_method ) ) {
			$selected_payment_method = array_keys( $payment_methods )[0];
		}

		foreach ( $payment_methods as $payment_method_key => $payment_method ) : ?>

			<div class="tix-alternate-payment-option">
				<input type="radio" name="tix_payment_method"
					id="tix-payment-method_<?php echo esc_attr( $payment_method_key ); ?>"
					value="<?php echo esc_attr( $payment_method_key ); ?>"
					autocomplete="off"
					required
					<?php checked( $selected_payment_method === $payment_method_key ); ?>
				/>

				<label for="tix-payment-method_<?php echo esc_attr( $payment_method_key ); ?>">
					<?php echo esc_html( $payment_method['name'] ); ?>
				</label>
			</div>

			<?php
		endforeach;
	}
}

camptix_register_addon( __NAMESPACE__ . '\Payment_Options' );
