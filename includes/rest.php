<?php
/**
 * REST surface for ans-comp-tickets. Admin only.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST surface. Admin only.
 */
if ( ! function_exists( 'ans_comp_register_routes' ) ) {
	/**
	 * Register the trio into one namespace.
	 *
	 * @param string $namespace REST namespace.
	 * @param string $prefix    Route prefix, '' or 'comp'.
	 */
	function ans_comp_register_trio( $namespace, $prefix = '' ) {

		$can = function () {
			return current_user_can( 'manage_options' );
		};

		$base = $prefix ? '/' . trim( $prefix, '/' ) : '';

		register_rest_route(
			$namespace,
			$base . '/diagnostics',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'ans_comp_rest_diagnostics',
			)
		);

		register_rest_route(
			$namespace,
			$base . '/issue',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => 'ans_comp_rest_issue',
			)
		);

		register_rest_route(
			$namespace,
			$base . '/void',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => 'ans_comp_rest_void',
			)
		);

		register_rest_route(
			$namespace,
			$base . '/sweep-orphans',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => 'ans_comp_rest_sweep',
			)
		);

		register_rest_route(
			$namespace,
			$base . '/ledger',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'ans_comp_rest_ledger',
			)
		);
	}

	/**
	 * Registered TWICE, on purpose.
	 *
	 * ans-comp/v1 is the plugin's own namespace and is what a browser or any
	 * ordinary REST client should use.
	 *
	 * ars-nova/v1/comp/* is a mirror, and it exists because the Ars Nova
	 * WordPress MCP connector's ans_rest_call validates the namespace against a
	 * CLOSED enum - ars-nova/v1, ans-ops/v1, ans-notes/v1, ansg/v1. A route in
	 * any other namespace is unreachable from a Claude session no matter how
	 * correctly it is written. Found the hard way on 2026-08-25: v0.1.0 shipped
	 * with ans-comp/v1 only and could not be called at all.
	 *
	 * This is the project's own recurring failure mode - a documented capability
	 * with no caller is the same bug as unreachable code. Remove the mirror only
	 * once the connector accepts arbitrary ans-* namespaces.
	 */
	function ans_comp_register_routes() {
		ans_comp_register_trio( 'ans-comp/v1', '' );
		ans_comp_register_trio( 'ars-nova/v1', 'comp' );
	}
	add_action( 'rest_api_init', 'ans_comp_register_routes' );
}

/**
 * Read-only. Answers the questions we could not answer from source alone -
 * above all whether HPOS is really in play on this install.
 */
if ( ! function_exists( 'ans_comp_rest_diagnostics' ) ) {
	function ans_comp_rest_diagnostics() {

		$hpos_enabled = null;
		$hpos_sync    = null;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			if ( method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'is_custom_order_tables_in_sync' ) ) {
				$hpos_sync = \Automattic\WooCommerce\Utilities\OrderUtil::is_custom_order_tables_in_sync();
			}
		}

		$bridge     = ans_comp_bridge();
		$bridge_err = is_wp_error( $bridge ) ? $bridge->get_error_message() : null;

		// The Bridge's generator gates on get_post_type($order_id). Under full
		// HPOS that returns false and generation aborts. Test it against the
		// most recent real order rather than theorising.
		$probe = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$recent = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) );
			if ( ! empty( $recent[0] ) ) {
				$probe = array(
					'probe_order_id'        => (int) $recent[0],
					'get_post_type_returns' => get_post_type( (int) $recent[0] ),
				);
			}
		}

		return rest_ensure_response(
			array(
				'plugin_version'          => ANS_COMP_VERSION,
				'woocommerce_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'hpos_usage_enabled'      => $hpos_enabled,
				'hpos_tables_in_sync'     => $hpos_sync,
				'option_cot_enabled'      => get_option( 'woocommerce_custom_orders_table_enabled' ),
				'bridge_class_exists'     => class_exists( 'TC_WooCommerce_Bridge' ),
				'bridge_global_set'       => ! empty( $GLOBALS['tc_woocommerce_bridge'] ),
				'bridge_factory_callable' => ! is_wp_error( $bridge ),
				'bridge_error'            => $bridge_err,
				'tc_wb_hpos_helper'       => function_exists( 'tc_wb_hpos' ),
				'ticket_instance_cpt'     => post_type_exists( 'tc_tickets_instances' ),
				'ticket_cpt_in_core_rest' => (bool) ( get_post_type_object( 'tc_tickets_instances' ) ? get_post_type_object( 'tc_tickets_instances' )->show_in_rest : false ),
				'failed_email_silenced'   => (bool) has_filter( 'woocommerce_email_recipient_failed_order', 'ans_comp_silence_admin_failure_email' ),
				'mailchimp_addon_present' => ans_comp_mailchimp_present(),
			) + $probe
		);
	}
}

if ( ! function_exists( 'ans_comp_rest_issue' ) ) {
	function ans_comp_rest_issue( WP_REST_Request $request ) {

		$result = ans_comp_issue(
			array(
				'performance_id'  => (int) $request->get_param( 'performance_id' ),
				'qty'             => (int) $request->get_param( 'qty' ),
				'recipient_name'  => (string) $request->get_param( 'recipient_name' ),
				'recipient_email' => (string) $request->get_param( 'recipient_email' ),
				'reason'          => (string) $request->get_param( 'reason' ),
				'source'          => $request->get_param( 'source' ) ? (string) $request->get_param( 'source' ) : 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				),
				400
			);
		}

		return rest_ensure_response( $result );
	}
}

/**
 * The ledger is the set of comp orders - no separate table needed.
 */
if ( ! function_exists( 'ans_comp_rest_ledger' ) ) {
	function ans_comp_rest_ledger( WP_REST_Request $request ) {

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'WooCommerce is not loaded.' ), 500 );
		}

		$k      = ans_comp_meta_keys();
		$limit  = (int) $request->get_param( 'limit' );
		$limit  = $limit > 0 ? min( $limit, 200 ) : 50;

		$orders = wc_get_orders(
			array(
				'limit'      => $limit,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => array( 'completed', 'failed', 'cancelled' ),
				'meta_key'   => $k['flag'],
				'meta_value' => 'yes',
			)
		);

		$rows  = array();
		$total = 0.0;

		foreach ( $orders as $order ) {
			$retail = (float) $order->get_meta( $k['retail'] );
			$total += ( 'completed' === $order->get_status() ) ? $retail : 0.0;

			$items = array();
			foreach ( $order->get_items() as $line ) {
				$items[] = array(
					'product_id' => $line->get_product_id(),
					'name'       => $line->get_name(),
					'qty'        => $line->get_quantity(),
				);
			}

			$rows[] = array(
				'order_id'     => $order->get_id(),
				'status'       => $order->get_status(),
				'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : null,
				'recipient'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'        => $order->get_billing_email(),
				'reason'       => $order->get_meta( $k['reason'] ),
				'source'       => $order->get_meta( $k['source'] ),
				'issued_by'    => (int) $order->get_meta( $k['issued_by'] ),
				'retail_value' => $retail,
				'items'        => $items,
				'ticket_count' => count( ans_comp_count_tickets( $order->get_id() ) ),
			);
		}

		return rest_ensure_response(
			array(
				'ok'                   => true,
				'count'                => count( $rows ),
				'retail_value_forgone' => round( $total, 2 ),
				'rows'                 => $rows,
			)
		);
	}
}

/**
 * Void one comp order.
 */
if ( ! function_exists( 'ans_comp_rest_void' ) ) {
	function ans_comp_rest_void( WP_REST_Request $request ) {

		$result = ans_comp_void(
			(int) $request->get_param( 'order_id' ),
			(string) $request->get_param( 'reason' ),
			(bool) $request->get_param( 'force' )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return rest_ensure_response( $result );
	}
}

/**
 * Sweep ticket instances left behind on failed or cancelled comp orders.
 *
 * DRY RUN BY DEFAULT. Pass apply=true to actually delete.
 */
if ( ! function_exists( 'ans_comp_rest_sweep' ) ) {
	function ans_comp_rest_sweep( WP_REST_Request $request ) {
		return rest_ensure_response( ans_comp_sweep_orphans( (bool) $request->get_param( 'apply' ) ) );
	}
}
