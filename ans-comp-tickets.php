<?php
/**
 * Plugin Name: Ars Nova Comp Tickets
 * Plugin URI:  https://github.com/ArsNovaSingers/ans-comp-tickets
 * Description: Issues complimentary Tickera tickets without a checkout. Creates a real WooCommerce order, records the retail value forgone as a 100% comp discount, and calls the Tickera Bridge's own ticket factory directly so a genuine ticket PDF is generated. Two front doors are planned on this engine: an admin issue button for individual comps, and a Singers Portal claim panel for group allowances. v0.1.3 ships the engine and a diagnostics route only.
 * Version:     0.1.3
 * Author:      Ars Nova (Jonathan Raabe) + Claude
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 *
 * ---------------------------------------------------------------------------
 * WHY THIS PLUGIN EXISTS AT ALL
 *
 * An order is not a ticket. The Tickera Bridge for WooCommerce does NOT listen
 * for "order paid" - it hooks woocommerce_new_order_item and builds one
 * tc_tickets_instances post per unit as each line item is written, and it
 * refuses to run unless the request carries live checkout data:
 *
 *     $_post = ( $extension_data ? $extension_data : $_POST );
 *     $_post = $this->handle_post( $_post, $item_id );
 *     if ( !$extension_data && (!$_post || $_post && isset($_post['requests'])) ) {
 *         return;
 *     }
 *
 * So an order created in wp-admin, by WP-CLI or through wc/v3 REST reaches
 * "completed", shows the ticket product, and issues NOTHING. No error anywhere.
 * tc_cart_contents is an OUTPUT of that routine, not an input - writing it
 * ourselves does not cause generation.
 *
 * The non-empty $extension_data argument is the only thing that clears that
 * gate. The Bridge itself uses exactly this path for block checkout, so this is
 * a supported route, not a hack.
 *
 * PREFIX DISCIPLINE - ans_comp_ only, every declaration guarded.
 * ans_sp_ (ticketing bridge, season projects), ans_spd_ (season packages),
 * ans_tb_, ansp_, ansc_, ansg_ and ans_pkg_ are all taken. The ans_sp_/ans_spd_
 * near-miss would have fatalled the bridge on every request.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ANS_COMP_VERSION' ) ) {
	define( 'ANS_COMP_VERSION', '0.1.3' );
}

require_once __DIR__ . '/includes/engine.php';
require_once __DIR__ . '/includes/emails.php';
require_once __DIR__ . '/includes/rest.php';
