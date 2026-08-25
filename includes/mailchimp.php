<?php
/**
 * Keep comp recipients out of Mailchimp until Kim decides otherwise.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lift the Tickera Mailchimp add-on's subscribe hooks.
 *
 * The add-on (mailchimp-newsletter/index.php, TC_Mailchimp) offers NO skip
 * filter of any kind - verified by reading its source on Live 2026-08-25. Its
 * only filters shape the payload (tc_buyer_merge_fields, tc_owner_merge_fields,
 * tc_mc_subscriber_tags); none can prevent the subscribe, because
 * $mailchimp->subscribe() has already run by the time tags are filtered.
 * remove_action is therefore the only clean route.
 *
 * It subscribes at ORDER CREATION, not at payment, and on Live it currently has
 * no opt-in gate and no double opt-in - so every buyer AND every ticket owner
 * goes onto the list immediately. That is a consent question well beyond comps
 * and is flagged separately; this file only stops comps adding to it.
 *
 * ⚠️ The priorities below mirror the add-on's own registrations exactly. A
 * Tickera Mailchimp update that changes them will make these calls no-ops
 * SILENTLY. Re-read its constructor after any update to that add-on.
 *
 * @return bool True if hooks were lifted and must be restored.
 */
if ( ! function_exists( 'ans_comp_mailchimp_suppress' ) ) {
	function ans_comp_mailchimp_suppress() {
		global $tc_mailchimp;
		if ( ! isset( $tc_mailchimp ) || ! is_object( $tc_mailchimp ) ) {
			return false; // Add-on not active on this environment.
		}
		$cb = array( $tc_mailchimp, 'subscribe_to_mailchimp' );
		remove_action( 'tc_order_created', $cb, 10 );
		remove_action( 'woocommerce_new_order', $cb, 20 );
		remove_action( 'woocommerce_resume_order', $cb, 20 );
		remove_action( 'woocommerce_api_create_order', $cb, 20 );
		remove_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			array( $tc_mailchimp, 'woocommerce_store_api_checkout' ),
			10
		);
		return true;
	}
}

/**
 * Put back exactly what ans_comp_mailchimp_suppress() lifted.
 */
if ( ! function_exists( 'ans_comp_mailchimp_restore' ) ) {
	function ans_comp_mailchimp_restore() {
		global $tc_mailchimp;
		if ( ! isset( $tc_mailchimp ) || ! is_object( $tc_mailchimp ) ) {
			return;
		}
		$cb = array( $tc_mailchimp, 'subscribe_to_mailchimp' );
		add_action( 'tc_order_created', $cb, 10, 1 );
		add_action( 'woocommerce_new_order', $cb, 20, 1 );
		add_action( 'woocommerce_resume_order', $cb, 20, 1 );
		add_action( 'woocommerce_api_create_order', $cb, 20, 1 );
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			array( $tc_mailchimp, 'woocommerce_store_api_checkout' ),
			10,
			2
		);
	}
}

/**
 * Is the add-on present at all? Reported by diagnostics so the difference
 * between "suppressed" and "was never there" is visible rather than assumed.
 */
if ( ! function_exists( 'ans_comp_mailchimp_present' ) ) {
	function ans_comp_mailchimp_present() {
		return isset( $GLOBALS['tc_mailchimp'] ) && is_object( $GLOBALS['tc_mailchimp'] );
	}
}
