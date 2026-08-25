<?php
/**
 * Comp ticket engine.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta keys, in one place so nothing goes hunting for a string literal.
 */
if ( ! function_exists( 'ans_comp_meta_keys' ) ) {
	function ans_comp_meta_keys() {
		return array(
			'flag'      => '_ans_comp',              // 'yes' on every comp order
			'reason'    => '_ans_comp_reason',
			'issued_by' => '_ans_comp_issued_by',
			'source'    => '_ans_comp_source',       // admin | portal-claim | test
			'retail'    => '_ans_comp_retail_value', // value forgone, in store currency
			'claimant'  => '_ans_comp_claimant',     // portal claims only
			'generated' => '_ans_comp_generated',    // idempotency guard
		);
	}
}

/**
 * Is the Tickera Bridge present and usable?
 *
 * @return WC_Order|WP_Error|object The bridge instance, or WP_Error.
 */
if ( ! function_exists( 'ans_comp_bridge' ) ) {
	function ans_comp_bridge() {
		if ( ! class_exists( 'TC_WooCommerce_Bridge' ) ) {
			return new WP_Error(
				'ans_comp_no_bridge',
				'Tickera Bridge for WooCommerce is not active. Comp tickets cannot be issued without it.'
			);
		}
		if ( empty( $GLOBALS['tc_woocommerce_bridge'] ) ) {
			return new WP_Error(
				'ans_comp_no_bridge_instance',
				'The Tickera Bridge class exists but $tc_woocommerce_bridge is not set. Cannot reach the ticket factory.'
			);
		}
		$bridge = $GLOBALS['tc_woocommerce_bridge'];
		if ( ! method_exists( $bridge, 'create_order_ticket_instances' ) ) {
			return new WP_Error(
				'ans_comp_no_factory',
				'The Tickera Bridge is present but create_order_ticket_instances() is missing. The Bridge version may have changed.'
			);
		}
		return $bridge;
	}
}

/**
 * Count the Tickera ticket instances belonging to an order.
 *
 * Read-back verification. A success status is not proof of a write.
 */
if ( ! function_exists( 'ans_comp_count_tickets' ) ) {
	function ans_comp_count_tickets( $order_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'tc_tickets_instances',
				'post_parent'    => (int) $order_id,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return is_array( $ids ) ? $ids : array();
	}
}

/**
 * Issue a complimentary ticket.
 *
 * @param array $args {
 *     @type int    $performance_id  WooCommerce product id of the ticket type. Required.
 *     @type int    $qty             Seats. Default 1.
 *     @type string $recipient_name  Required.
 *     @type string $recipient_email Required.
 *     @type string $reason          Required - why this comp was given.
 *     @type string $source          admin | portal-claim | test. Default 'admin'.
 *     @type int    $issued_by       WP user id of the actor. Default current user.
 *     @type int    $claimant_user_id Portal claims only. Default 0.
 * }
 * @return array|WP_Error
 */
if ( ! function_exists( 'ans_comp_issue' ) ) {
	function ans_comp_issue( $args ) {

		$defaults = array(
			'performance_id'   => 0,
			'qty'              => 1,
			'recipient_name'   => '',
			'recipient_email'  => '',
			'reason'           => '',
			'source'           => 'admin',
			'issued_by'        => get_current_user_id(),
			'claimant_user_id' => 0,
		);
		$a = wp_parse_args( $args, $defaults );
		$k = ans_comp_meta_keys();

		// --- validate ---------------------------------------------------
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'ans_comp_no_woo', 'WooCommerce is not loaded.' );
		}

		$bridge = ans_comp_bridge();
		if ( is_wp_error( $bridge ) ) {
			return $bridge;
		}

		$pid = (int) $a['performance_id'];
		$qty = max( 1, (int) $a['qty'] );

		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return new WP_Error( 'ans_comp_no_product', sprintf( 'No product with id %d.', $pid ) );
		}

		if ( 'yes' !== get_post_meta( $pid, '_tc_is_ticket', true ) ) {
			return new WP_Error(
				'ans_comp_not_a_ticket',
				sprintf( 'Product %d is not a Tickera ticket type (_tc_is_ticket is not "yes"). Issuing it would produce an order with no ticket.', $pid )
			);
		}

		$event_id = (int) get_post_meta( $pid, '_event_name', true );
		if ( ! $event_id ) {
			return new WP_Error( 'ans_comp_no_event', sprintf( 'Ticket product %d has no parent event (_event_name).', $pid ) );
		}

		// A draft parent event silently disables sales AND ticket generation.
		// Refuse loudly rather than issue a dead ticket.
		$event_status = get_post_status( $event_id );
		if ( 'publish' !== $event_status ) {
			return new WP_Error(
				'ans_comp_event_not_published',
				sprintf( 'Parent event %d is "%s", not published. Publish the event first, or the ticket will not generate.', $event_id, $event_status )
			);
		}

		$email = sanitize_email( $a['recipient_email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'ans_comp_bad_email', 'A valid recipient email is required - it is where the ticket is sent.' );
		}

		$reason = trim( wp_strip_all_tags( (string) $a['reason'] ) );
		if ( '' === $reason ) {
			return new WP_Error( 'ans_comp_no_reason', 'A reason is required. Every comp is a decision and the ledger records it.' );
		}

		$name  = trim( wp_strip_all_tags( (string) $a['recipient_name'] ) );
		$parts = explode( ' ', $name, 2 );
		$first = $parts[0];
		$last  = isset( $parts[1] ) ? $parts[1] : '';

		// --- build the order --------------------------------------------
		$retail_each  = (float) $product->get_regular_price( 'edit' );
		$retail_total = round( $retail_each * $qty, 2 );

		$order = wc_create_order( array( 'status' => 'pending', 'customer_id' => 0 ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$order->set_address(
			array(
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
			),
			'billing'
		);

		// ---------------------------------------------------------------
		// Neutralise the Bridge's OWN auto-generation hook while we build.
		//
		// The Bridge hooks woocommerce_new_order_item and gates on live
		// checkout data - but handle_post() falls back to php://input when
		// $_POST is empty. In a REST context our own JSON request body
		// satisfies that gate, so the Bridge generates a full set of tickets
		// the moment add_product() runs. Our explicit call then generated a
		// SECOND set: the first staging run asked for 2 tickets and produced
		// 4. Caught by the read-back below, which is the whole reason it is
		// there.
		//
		// Removing the hook makes generation deterministic in every context -
		// REST, WP-CLI, cron, an admin form post - instead of depending on
		// whether the ambient request happens to carry a body.
		// ---------------------------------------------------------------
		remove_action( 'woocommerce_new_order_item', array( $bridge, 'create_order_ticket_instances' ), 11 );

		try {
			// Retail as subtotal, zero as total. WooCommerce renders that
			// natively as a discount, so the order reads "$40.00 -> $0.00"
			// rather than looking like a ticket sold for nothing.
			$item_id = $order->add_product(
				$product,
				$qty,
				array(
					'subtotal' => $retail_total,
					'total'    => 0,
				)
			);
		} finally {
			add_action( 'woocommerce_new_order_item', array( $bridge, 'create_order_ticket_instances' ), 11, 3 );
		}

		if ( ! $item_id ) {
			$order->update_status( 'failed', 'ans-comp-tickets: could not add the ticket line item. ' );
			return new WP_Error( 'ans_comp_no_item', 'Could not add the ticket line item to the order.' );
		}

		$order->update_meta_data( $k['flag'], 'yes' );
		$order->update_meta_data( $k['reason'], $reason );
		$order->update_meta_data( $k['issued_by'], (int) $a['issued_by'] );
		$order->update_meta_data( $k['source'], sanitize_key( $a['source'] ) );
		$order->update_meta_data( $k['retail'], $retail_total );
		if ( $a['claimant_user_id'] ) {
			$order->update_meta_data( $k['claimant'], (int) $a['claimant_user_id'] );
		}

		$order->calculate_totals( false ); // false = do not calculate taxes
		$order->set_total( 0 );

		// Idempotency guard, set BEFORE the factory runs.
		// create_order_ticket_instances() is not idempotent - calling it twice
		// on the same item creates duplicate tickets.
		if ( $order->get_meta( $k['generated'] ) ) {
			return new WP_Error( 'ans_comp_already_generated', 'This order has already had tickets generated.' );
		}
		$order->update_meta_data( $k['generated'], time() );
		$order->save();

		// --- call the Bridge's own ticket factory -----------------------
		foreach ( $order->get_items() as $line_id => $line ) {
			$bridge->create_order_ticket_instances(
				$line_id,
				$line,
				$order->get_id(),
				array( 'ans_comp' => 1 ), // non-empty: this is what clears the $_POST gate
				true,
				$order
			);
		}

		// --- verify by read-back ----------------------------------------
		$tickets = ans_comp_count_tickets( $order->get_id() );
		if ( count( $tickets ) !== $qty ) {

			// Trash whatever was generated. A failed comp order must not leave
			// live ticket instances behind - they carry real codes and would
			// scan at the door if the order were ever moved to completed.
			foreach ( $tickets as $ticket_id ) {
				wp_trash_post( $ticket_id );
			}

			$order->update_status(
				'failed',
				sprintf( 'ans-comp-tickets: expected %d ticket instances, found %d. Tickets trashed, nothing delivered. ', $qty, count( $tickets ) )
			);
			return new WP_Error(
				'ans_comp_generation_mismatch',
				sprintf( 'Ticket generation did not produce the expected count: wanted %d, got %d. Order %d left as failed and its tickets trashed.', $qty, count( $tickets ), $order->get_id() ),
				array(
					'order_id'         => $order->get_id(),
					'ticket_ids'       => $tickets,
					'tickets_trashed'  => true,
				)
			);
		}

		// Completing last means the completed-order email carries the PDFs.
		$order->update_status(
			'completed',
			sprintf( 'Complimentary ticket issued (%s). Reason: %s ', sanitize_key( $a['source'] ), $reason )
		);

		return array(
			'ok'           => true,
			'order_id'     => $order->get_id(),
			'order_status' => $order->get_status(),
			'event_id'     => $event_id,
			'product_id'   => $pid,
			'qty'          => $qty,
			'retail_value' => $retail_total,
			'ticket_ids'   => $tickets,
			'recipient'    => $email,
		);
	}
}
