<?php
/**
 * The message a comp carries to the person receiving it.
 *
 * Kim's request, 2026-08-30: an optional "note to comp recipient" on both doors -
 * her admin issuing screen and the Singers Hub cart - that appears in the email.
 *
 * NOT THE REASON. `_ans_comp_reason` already exists and is required, but it is the
 * internal record of why a comp was given ("Guest of the composer") and it is read
 * by the ledger and by anyone auditing what was given away. This is a different
 * thing entirely: a message TO the guest, optional, in the singer's own words.
 * Conflating them would either put an audit note in front of a guest or a personal
 * message into the ledger, and both are wrong.
 *
 * WHERE IT RENDERS. On the completed-order email - the one that already carries the
 * ticket PDF, so the note travels with the thing it is about. It goes ABOVE the
 * order table, because a message from a person should not read as order furniture
 * underneath a list of line items.
 *
 * ESCAPING IS THE WHOLE JOB HERE. This is free text, written by a singer, rendered
 * into an HTML email, sent to an address outside the organisation, reviewed by
 * nobody in between. It is sanitised on the way in (see ans_comp_issue) and escaped
 * again on the way out. Both, deliberately: the stored value could predate a change
 * to the sanitiser, and esc_html at render is the guard that does not depend on
 * when the row was written.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who the note is from, in words a guest will understand.
 *
 * A portal claim carries the singer who gave it. An admin comp does not - Kim
 * issuing a comp to a reviewer is Ars Nova doing it, not Kim personally - so it is
 * attributed to the organisation rather than to whoever happened to be logged in.
 *
 * @param WC_Order $order
 * @return string Display name, already decoded; caller escapes.
 */
if ( ! function_exists( 'ans_comp_note_attribution' ) ) {
	function ans_comp_note_attribution( $order ) {
		$keys     = ans_comp_meta_keys();
		$claimant = (int) $order->get_meta( $keys['claimant'] );

		if ( $claimant ) {
			$user = get_userdata( $claimant );
			if ( $user && $user->display_name ) {
				return $user->display_name;
			}
		}

		return get_bloginfo( 'name' );
	}
}

/**
 * Print the note on the customer's completed-order email.
 *
 * Guarded three ways: comp orders only, notes only, and customer-facing emails
 * only. The admin copy of an order email is a different audience and a personal
 * message to a guest has no business in it.
 *
 * @param WC_Order $order
 * @param bool     $sent_to_admin
 * @param bool     $plain_text
 * @param WC_Email $email
 * @return void
 */
if ( ! function_exists( 'ans_comp_render_recipient_note' ) ) {
	function ans_comp_render_recipient_note( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof WC_Order || $sent_to_admin ) {
			return;
		}
		if ( ! ans_comp_is_comp_order( $order ) ) {
			return;
		}

		$keys = ans_comp_meta_keys();
		$note = (string) $order->get_meta( $keys['note'] );
		if ( '' === trim( $note ) ) {
			return;
		}

		$from = ans_comp_note_attribution( $order );

		if ( $plain_text ) {
			echo "\n----------\n";
			/* translators: %s: the person or organisation the comp came from. */
			echo esc_html( sprintf( __( 'A note from %s', 'ans-comp-tickets' ), $from ) ) . "\n\n";
			echo esc_html( $note ) . "\n";
			echo "----------\n\n";
			return;
		}

		/*
		 * Inline styles, not a stylesheet: email clients strip <style> blocks
		 * unpredictably and there is no second chance to restyle a sent message.
		 */
		echo '<div style="margin:0 0 24px;padding:16px 18px;border-left:4px solid #b08d3f;background:#faf7f0;">';
		echo '<p style="margin:0 0 8px;font-weight:600;color:#5a4a25;">'
			. esc_html(
				sprintf(
					/* translators: %s: the person or organisation the comp came from. */
					__( 'A note from %s', 'ans-comp-tickets' ),
					$from
				)
			)
			. '</p>';
		echo '<div style="margin:0;color:#2b2b2b;">' . wp_kses_post( wpautop( esc_html( $note ) ) ) . '</div>';
		echo '</div>';
	}
}

/*
 * Priority 5: ahead of the order table, which core prints at 10.
 *
 * woocommerce_email_order_details fires for every order email; the guards inside
 * the callback are what keep this to customer-facing comp emails, rather than
 * trying to enumerate email classes here - a list that would go stale the first
 * time WooCommerce added one.
 */
add_action( 'woocommerce_email_order_details', 'ans_comp_render_recipient_note', 5, 4 );
