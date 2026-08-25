<?php
/**
 * Email behaviour for comp orders.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this a comp order?
 *
 * @param mixed $order WC_Order or order id.
 * @return bool
 */
if ( ! function_exists( 'ans_comp_is_comp_order' ) ) {
	function ans_comp_is_comp_order( $order ) {
		if ( is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}
		$k = ans_comp_meta_keys();
		return 'yes' === $order->get_meta( $k['flag'] );
	}
}

/**
 * Suppress the "payment failed" and "order cancelled" ADMIN emails for comp orders.
 *
 * A comp has no payment, so "the payment for order #7188 has failed" is not
 * merely noise - it is untrue, and it reads to staff like a lost sale. The
 * first staging run produced exactly that email into info@arsnovasingers.org,
 * which is a shared inbox the office actually reads.
 *
 * Returning an empty recipient is WooCommerce's own supported way to skip an
 * email: WC_Email::trigger() bails when get_recipient() is empty.
 *
 * Scope is deliberately narrow. This touches ONLY the two admin notifications
 * that describe a payment that never existed, and ONLY on orders carrying
 * _ans_comp. Every real order's email behaviour is untouched, and so is the
 * customer-facing COMPLETED email - that one carries the ticket PDFs and is
 * the entire point of the exercise.
 *
 * @param string $recipient Comma-separated recipients.
 * @param mixed  $order     WC_Order (or null in some contexts).
 * @return string
 */
if ( ! function_exists( 'ans_comp_silence_admin_failure_email' ) ) {
	function ans_comp_silence_admin_failure_email( $recipient, $order = null ) {
		if ( $order && ans_comp_is_comp_order( $order ) ) {
			return '';
		}
		return $recipient;
	}
	add_filter( 'woocommerce_email_recipient_failed_order', 'ans_comp_silence_admin_failure_email', 10, 2 );
	add_filter( 'woocommerce_email_recipient_cancelled_order', 'ans_comp_silence_admin_failure_email', 10, 2 );
}
