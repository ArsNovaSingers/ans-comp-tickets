<?php
/**
 * What a comp guest sees in their inbox.
 *
 * Jonathan, 2026-08-30: "Is there any way to change the subject for just comp
 * tickets. Seems weird we can customize emails for different situations... It
 * should say something like Here is your Ars Nova comp ticket from: persons
 * name."
 *
 * WHAT WAS WRONG. A comp arrives on WooCommerce's completed-order email, so
 * until now the guest received the stock strings: subject "Your Ars Nova
 * Singers order is now complete", heading "Thanks for shopping with us". Both
 * are untrue of a comp - the person did not order anything and did not shop -
 * and the first thing they see is a sentence describing a transaction that
 * never happened.
 *
 * WHO CAN CHANGE IT, AND WHERE. Jonathan's ruling: "Singers should not be able
 * to edit, but Kim should in her admin portal comp paths." So the subject is a
 * PER-COMP field on the admin issue form and nowhere else. A singer claiming
 * from the Hub gets the default with their own name in it and no way to alter
 * it - which is right: a subject line is the organisation's voice reaching a
 * member of the public, not a singer's.
 *
 * "COMPLIMENTARY", NOT "COMP". The default deliberately does not use the word
 * Jonathan used. "Comp" is our word - it is how the office talks about these -
 * and the person reading the subject line is a member of the public who may
 * never have heard it. Kim can type whatever she likes into the field; this is
 * only what the field starts with.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ANS_COMP_SUBJECT_MAX' ) ) {
	define( 'ANS_COMP_SUBJECT_MAX', 160 );
}

/**
 * The subject a comp gets when nobody has typed one.
 *
 * `{from}` carries its own leading space and resolves to nothing at all on a
 * comp Kim issued herself. That is the whole reason it exists rather than a
 * bare `{singer}`: an admin comp has no singer, and a template written as
 * "... ticket from {singer}" would leave a guest reading "Your complimentary
 * Ars Nova ticket from" with the sentence hanging open.
 *
 * @return string Template, placeholders unresolved.
 */
if ( ! function_exists( 'ans_comp_default_subject' ) ) {
	function ans_comp_default_subject() {
		return (string) apply_filters(
			'ans_comp_default_subject',
			__( 'Your complimentary Ars Nova ticket{from}', 'ans-comp-tickets' )
		);
	}
}

/**
 * The heading printed at the top of the email body.
 *
 * Not per-comp and not editable, deliberately - Jonathan asked for the subject
 * line. It is fixed here only because leaving WooCommerce's "Thanks for
 * shopping with us" above a ticket nobody bought would be worse than either.
 *
 * @return string
 */
if ( ! function_exists( 'ans_comp_default_heading' ) ) {
	function ans_comp_default_heading() {
		return (string) apply_filters(
			'ans_comp_default_heading',
			__( 'Your ticket is attached', 'ans-comp-tickets' )
		);
	}
}

/**
 * Which production a comp is for, in words, or '' when it cannot be worked out.
 *
 * The portal stamps `_ans_comp_event`; an admin comp does not, so this falls
 * back to the ticket instance, whose `event_id` is the performance. Both routes
 * can come up empty - a comp whose tickets were trashed, an event since
 * deleted - and an empty answer is returned rather than guessed at.
 *
 * @param WC_Order $order
 * @return string
 */
if ( ! function_exists( 'ans_comp_order_concert' ) ) {
	function ans_comp_order_concert( $order ) {
		$event_id = (int) $order->get_meta( '_ans_comp_event' );

		if ( ! $event_id && function_exists( 'ans_comp_count_tickets' ) ) {
			foreach ( ans_comp_count_tickets( $order->get_id() ) as $tid ) {
				$event_id = (int) get_post_meta( $tid, 'event_id', true );
				if ( $event_id ) {
					break;
				}
			}
		}

		if ( ! $event_id || ! get_post( $event_id ) ) {
			return '';
		}

		return html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' );
	}
}

/**
 * Resolve the placeholders in a subject template.
 *
 * THE TIDY PASS AT THE END IS NOT COSMETIC. Every placeholder here can legally
 * resolve to nothing, and the string it leaves behind is read by a stranger. A
 * template of "Your ticket{from} - {concert}" with neither available must not
 * arrive as "Your ticket  -" with a dangling dash and a double space. So after
 * substitution the result is stripped of orphaned separators and collapsed
 * whitespace, and only then trimmed.
 *
 * @param string   $template
 * @param WC_Order $order
 * @return string
 */
if ( ! function_exists( 'ans_comp_resolve_subject' ) ) {
	function ans_comp_resolve_subject( $template, $order ) {
		$singer = '';

		/*
		 * A singer only, never the organisation. ans_comp_note_attribution()
		 * falls back to the site name, which is right for "A note from ..." but
		 * wrong here: "Your complimentary Ars Nova ticket from Ars Nova Singers"
		 * says the same thing twice.
		 */
		$claimant = (int) $order->get_meta( '_ans_comp_claimant' );
		if ( $claimant ) {
			$user = get_userdata( $claimant );
			if ( $user && $user->display_name ) {
				$singer = (string) $user->display_name;
			}
		}

		$concert = ans_comp_order_concert( $order );

		$out = strtr(
			(string) $template,
			array(
				/* translators: %s: the singer who gave the comp. */
				'{from}'       => '' !== $singer ? sprintf( __( ' from %s', 'ans-comp-tickets' ), $singer ) : '',
				'{singer}'     => $singer,
				'{concert}'    => $concert,
				'{site_title}' => (string) get_bloginfo( 'name' ),
			)
		);

		// Orphaned separators left where a placeholder resolved to nothing.
		$out = preg_replace( '/\s*[-–—:·|]\s*(?=$)/u', '', $out );
		$out = preg_replace( '/\(\s*\)|\[\s*\]/u', '', $out );
		$out = preg_replace( '/\s+/u', ' ', $out );

		return trim( $out );
	}
}

/**
 * Tidy and cap a subject typed by a person.
 *
 * Newlines are stripped rather than trimmed: a line break in a mail header is
 * header injection, and sanitize_text_field alone does not make that safe to
 * assume. The cap is generous - 160 characters is far past where any client
 * truncates - and exists so a paste accident cannot produce an absurd header.
 *
 * @param string $subject
 * @return string
 */
if ( ! function_exists( 'ans_comp_clean_subject' ) ) {
	function ans_comp_clean_subject( $subject ) {
		$subject = (string) $subject;
		$subject = str_replace( array( "\r", "\n", "\t" ), ' ', $subject );
		$subject = sanitize_text_field( $subject );
		$subject = preg_replace( '/\s+/u', ' ', $subject );
		$subject = trim( $subject );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $subject, 0, ANS_COMP_SUBJECT_MAX );
		}
		return substr( $subject, 0, ANS_COMP_SUBJECT_MAX );
	}
}

/**
 * The subject this particular comp should carry.
 *
 * Kim's per-comp text wins; otherwise the default template. A subject she
 * blanked falls back to the default rather than to WooCommerce's stock string -
 * clearing a field should not resurrect "your order is now complete".
 *
 * @param WC_Order $order
 * @return string
 */
if ( ! function_exists( 'ans_comp_subject_for' ) ) {
	function ans_comp_subject_for( $order ) {
		$k      = ans_comp_meta_keys();
		$stored = isset( $k['subject'] ) ? (string) $order->get_meta( $k['subject'] ) : '';
		$tpl    = '' !== trim( $stored ) ? $stored : ans_comp_default_subject();

		return ans_comp_resolve_subject( $tpl, $order );
	}
}

/*
 * WooCommerce builds both strings through
 * woocommerce_email_subject_{$id} / woocommerce_email_heading_{$id}, and the
 * id of the email carrying the ticket PDFs is customer_completed_order.
 *
 * Gated on ans_comp_is_comp_order() so that every real sale's email is
 * untouched - this must never be able to rewrite the subject of an order
 * somebody paid for.
 */
if ( ! function_exists( 'ans_comp_filter_email_subject' ) ) {
	function ans_comp_filter_email_subject( $subject, $order = null, $email = null ) {
		if ( ! $order instanceof WC_Order || ! ans_comp_is_comp_order( $order ) ) {
			return $subject;
		}

		$ours = ans_comp_subject_for( $order );

		return '' !== $ours ? $ours : $subject;
	}
	add_filter( 'woocommerce_email_subject_customer_completed_order', 'ans_comp_filter_email_subject', 10, 3 );
}

if ( ! function_exists( 'ans_comp_filter_email_heading' ) ) {
	function ans_comp_filter_email_heading( $heading, $order = null, $email = null ) {
		if ( ! $order instanceof WC_Order || ! ans_comp_is_comp_order( $order ) ) {
			return $heading;
		}

		$ours = ans_comp_resolve_subject( ans_comp_default_heading(), $order );

		return '' !== $ours ? $ours : $heading;
	}
	add_filter( 'woocommerce_email_heading_customer_completed_order', 'ans_comp_filter_email_heading', 10, 3 );
}
