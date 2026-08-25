<?php
/**
 * Voiding a comp, and sweeping up after a failed one.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Why this exists rather than a core REST call.
 *
 * Tickera registers tc_tickets_instances with show_in_rest => false - confirmed
 * against the live type list, where tc_events appears and tc_tickets_instances
 * does not. That is a deliberate choice by Tickera and a correct one: a ticket
 * instance carries a live scannable code, and exposing the whole type through
 * /wp/v2/ would put every ticket on the site behind one generic permission
 * check for the benefit of one admin chore.
 *
 * This route does the same job with a fraction of the blast radius: it deletes
 * ticket instances ONLY as part of voiding a comp order this plugin issued, it
 * refuses to touch an order that is not ours, and it writes an order note
 * naming who did it and why.
 */

/**
 * Void a comp order: trash its tickets, cancel the order, record the reason.
 *
 * @param int    $order_id Order to void.
 * @param string $reason   Required - why.
 * @param bool   $force    Void even if the order is not flagged as a comp.
 * @return array|WP_Error
 */
if ( ! function_exists( 'ans_comp_void' ) ) {
	function ans_comp_void( $order_id, $reason = '', $force = false ) {

		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'ans_comp_no_woo', 'WooCommerce is not loaded.' );
		}

		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'ans_comp_no_order', sprintf( 'No order with id %d.', $order_id ) );
		}

		$k = ans_comp_meta_keys();

		// Refuse anything that is not ours. A real customer order must never be
		// reachable through a tool built to tidy up comps.
		if ( ! $force && 'yes' !== $order->get_meta( $k['flag'] ) ) {
			return new WP_Error(
				'ans_comp_not_a_comp',
				sprintf( 'Order %d is not a comp order (no %s meta). Refusing to void it. Pass force=true only if you are certain.', $order_id, $k['flag'] )
			);
		}

		$reason = trim( wp_strip_all_tags( (string) $reason ) );
		if ( '' === $reason ) {
			return new WP_Error( 'ans_comp_no_reason', 'A reason is required to void a comp. The ledger records it.' );
		}

		$tickets = ans_comp_count_tickets( $order_id );
		$trashed = array();
		$failed  = array();

		foreach ( $tickets as $ticket_id ) {
			// force_delete: a trashed ticket still occupies its code and can be
			// restored by anyone with the trash. A voided comp should leave
			// nothing scannable behind.
			if ( wp_delete_post( $ticket_id, true ) ) {
				$trashed[] = $ticket_id;
			} else {
				$failed[] = $ticket_id;
			}
		}

		$order->update_meta_data( '_ans_comp_voided', time() );
		$order->update_meta_data( '_ans_comp_void_reason', $reason );
		$order->update_meta_data( '_ans_comp_voided_by', get_current_user_id() );
		$order->save();

		if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			$order->update_status(
				'cancelled',
				sprintf( 'Comp voided: %s (%d ticket(s) deleted). ', $reason, count( $trashed ) )
			);
		}

		// Read back rather than trusting the delete calls.
		$remaining = ans_comp_count_tickets( $order_id );

		return array(
			'ok'              => empty( $failed ) && empty( $remaining ),
			'order_id'        => $order_id,
			'order_status'    => $order->get_status(),
			'tickets_deleted' => $trashed,
			'tickets_failed'  => $failed,
			'tickets_remaining' => $remaining,
			'reason'          => $reason,
		);
	}
}

/**
 * Find comp orders that are failed or cancelled but still hold ticket instances.
 *
 * This is the cleanup for the class of mess a mid-flight defect leaves behind:
 * order 7188 on staging held four orphan tickets after the double-generation
 * bug, and nothing in core REST could reach them.
 *
 * @param bool $apply False (default) reports only. True actually deletes.
 * @return array
 */
if ( ! function_exists( 'ans_comp_sweep_orphans' ) ) {
	function ans_comp_sweep_orphans( $apply = false ) {

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'ok' => false, 'message' => 'WooCommerce is not loaded.' );
		}

		$k      = ans_comp_meta_keys();
		$orders = wc_get_orders(
			array(
				'limit'      => 200,
				'status'     => array( 'failed', 'cancelled' ),
				'meta_key'   => $k['flag'],
				'meta_value' => 'yes',
			)
		);

		$found = array();
		foreach ( $orders as $order ) {
			$tickets = ans_comp_count_tickets( $order->get_id() );
			if ( empty( $tickets ) ) {
				continue;
			}
			$row = array(
				'order_id'   => $order->get_id(),
				'status'     => $order->get_status(),
				'ticket_ids' => $tickets,
				'deleted'    => array(),
			);
			if ( $apply ) {
				foreach ( $tickets as $ticket_id ) {
					if ( wp_delete_post( $ticket_id, true ) ) {
						$row['deleted'][] = $ticket_id;
					}
				}
				$row['remaining'] = ans_comp_count_tickets( $order->get_id() );
			}
			$found[] = $row;
		}

		return array(
			'ok'       => true,
			'dry_run'  => ! $apply,
			'orders'   => count( $found ),
			'findings' => $found,
			'note'     => $apply
				? 'Tickets permanently deleted. Orders left as they were.'
				: 'Nothing changed. Call again with apply=true to delete these ticket instances.',
		);
	}
}
