<?php
/**
 * Kim's Comp Tickets admin screen.
 *
 * This file is UI ONLY. Every state change goes through the engine:
 * ans_comp_issue() in engine.php and ans_comp_void() in void.php. Nothing here
 * creates an order, touches a ticket instance, or reimplements a guard - if a
 * rule needs changing it changes in the engine and both this screen and the
 * REST routes inherit it.
 *
 * WHY THIS EXISTS
 * v0.2.0 shipped a proven engine whose only caller was a REST route reachable
 * from a Claude session. Kim could not issue a comp. A capability with no human
 * caller is the same bug as unreachable code, which is a mistake this project
 * has already made twice (includes/display-names.php, and v0.1.0's routes in an
 * unreachable namespace).
 *
 * Post/Redirect/Get throughout: every write is an admin-post handler that
 * redirects back with a result in the query string, so a browser refresh can
 * never issue a second comp.
 *
 * PREFIX DISCIPLINE - ans_comp_ only, every declaration guarded.
 *
 * @package AnsCompTickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ans_comp_admin_cap' ) ) {
	/**
	 * The one capability this screen answers to.
	 *
	 * @return string
	 */
	function ans_comp_admin_cap() {
		return 'manage_options';
	}
}

if ( ! function_exists( 'ans_comp_admin_slug' ) ) {
	/**
	 * Menu slug, used by the page, both handlers and every redirect.
	 *
	 * @return string
	 */
	function ans_comp_admin_slug() {
		return 'ans-comp-tickets';
	}
}

if ( ! function_exists( 'ans_comp_admin_url' ) ) {
	/**
	 * URL back to this screen, with optional extra query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	function ans_comp_admin_url( $args = array() ) {
		$args = array_merge( array( 'page' => ans_comp_admin_slug() ), (array) $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}


if ( ! function_exists( 'ans_comp_admin_money' ) ) {
	/**
	 * A money string safe to pass through esc_html().
	 *
	 * wc_price() returns markup whose currency symbol is an HTML entity
	 * (&#36; for dollars). Stripping the tags leaves the entity behind, and
	 * esc_html() then double-encodes it, so the screen prints a literal
	 * "&#36;40.00". Decode once, here, and every caller can escape normally.
	 *
	 * @param float $amount Amount in store currency.
	 * @return string
	 */
	function ans_comp_admin_money( $amount ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return number_format_i18n( (float) $amount, 2 );
		}

		$plain = wp_strip_all_tags( wc_price( (float) $amount ) );

		return html_entity_decode( $plain, ENT_QUOTES, get_bloginfo( 'charset' ) );
	}
}

/* -------------------------------------------------------------------------
 * Menu
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'ans_comp_admin_parent_slug' ) ) {
	/**
	 * The menu this screen belongs under: the Singers Portal.
	 *
	 * @return string
	 */
	function ans_comp_admin_parent_slug() {
		return 'ansp-dashboard';
	}
}

if ( ! function_exists( 'ans_comp_admin_menu' ) ) {
	/**
	 * Register the screen UNDER the Singers Portal menu.
	 *
	 * Jonathan, 2026-08-28: "put it as a link under the main singers portal menu
	 * item." One place to look for Ars Nova operations, rather than a new
	 * top-level item per plugin.
	 *
	 * This replaces a top-level add_menu_page(). The original reasoning was only
	 * ever "not a WooCommerce submenu" - comps are an Ars Nova decision, not a
	 * store function, and Kim should not have to know a comp is implemented as a
	 * WooCommerce order to find it. That argument still holds; it simply never
	 * considered the Singers Portal as the third option, and went to top level
	 * by default.
	 *
	 * PRIORITY 11 IS LOAD-BEARING. ANSP_Dashboard registers 'ansp-dashboard' on
	 * admin_menu at priority 9, so by 11 the parent exists whenever the portal is
	 * active. At the default 10 the ordering would depend on plugin load order,
	 * which is alphabetical and would put this plugin FIRST - the parent would
	 * not exist yet and the submenu would silently vanish. It also stays below
	 * the portal's own reorder pass at 999.
	 *
	 * FALLS BACK TO TOP LEVEL. ans-comp-tickets is a standalone plugin: it needs
	 * WooCommerce and the Tickera Bridge, NOT the portal. If the portal is
	 * inactive there is no parent to attach to, and a submenu on a missing parent
	 * is simply unreachable. Kim losing the ability to issue a comp because a
	 * members plugin was switched off would be a worse bug than an extra menu
	 * item, so in that case it registers top level exactly as before.
	 *
	 * The page slug is unchanged, so ans_comp_admin_url(), both admin-post
	 * handlers and every redirect keep working untouched either way.
	 *
	 * @return void
	 */
	function ans_comp_admin_menu() {
		$parent = ans_comp_admin_parent_slug();

		// add_menu_page() records its slug here; this is how we know the portal
		// registered a parent we can hang off.
		$parent_exists = isset( $GLOBALS['admin_page_hooks'] )
			&& is_array( $GLOBALS['admin_page_hooks'] )
			&& isset( $GLOBALS['admin_page_hooks'][ $parent ] );

		if ( $parent_exists ) {
			add_submenu_page(
				$parent,
				__( 'Comp Tickets', 'ans-comp-tickets' ),
				__( 'Comp Tickets', 'ans-comp-tickets' ),
				ans_comp_admin_cap(),
				ans_comp_admin_slug(),
				'ans_comp_admin_page'
			);
			return;
		}

		add_menu_page(
			__( 'Comp Tickets', 'ans-comp-tickets' ),
			__( 'Comp Tickets', 'ans-comp-tickets' ),
			ans_comp_admin_cap(),
			ans_comp_admin_slug(),
			'ans_comp_admin_page',
			'dashicons-tickets-alt',
			58
		);
	}
	add_action( 'admin_menu', 'ans_comp_admin_menu', 11 );
}

/* -------------------------------------------------------------------------
 * Data helpers
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'ans_comp_admin_ticket_groups' ) ) {
	/**
	 * Every issuable ticket product, grouped by its parent event.
	 *
	 * Only published events appear. The engine refuses a draft event anyway
	 * (a draft silently disables both sales and ticket generation), so showing
	 * one here would only offer Kim a choice that always fails.
	 *
	 * @return array<int,array{label:string,when:string,products:array<int,array{label:string,price:float}>}>
	 */
	function ans_comp_admin_ticket_groups() {
		$out = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen, runs once.
				'meta_query'     => array(
					array(
						'key'   => '_tc_is_ticket',
						'value' => 'yes',
					),
				),
			)
		);

		foreach ( $query->posts as $product_id ) {
			$product_id = (int) $product_id;
			$event_id   = (int) get_post_meta( $product_id, '_event_name', true );

			if ( ! $event_id ) {
				continue;
			}

			$event = get_post( $event_id );
			if ( ! $event || 'publish' !== $event->post_status ) {
				continue;
			}

			if ( ! isset( $out[ $event_id ] ) ) {
				$out[ $event_id ] = array(
					'label'    => get_the_title( $event_id ),
					'when'     => (string) get_post_meta( $event_id, 'event_date_time', true ),
					'products' => array(),
				);
			}

			$product = wc_get_product( $product_id );

			$out[ $event_id ]['products'][ $product_id ] = array(
				'label' => get_the_title( $product_id ),
				'price' => $product ? (float) $product->get_regular_price() : 0.0,
			);
		}

		// Soonest first. event_date_time is Y-m-d H:i, so a string sort is a
		// date sort - no parsing, and no timezone to get wrong.
		uasort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) $a['when'], (string) $b['when'] );
			}
		);

		return $out;
	}
}

if ( ! function_exists( 'ans_comp_admin_ledger' ) ) {
	/**
	 * Every comp order, newest first.
	 *
	 * The set of comp orders IS the ledger - there is no separate table, which
	 * is what makes it impossible for the ledger and reality to disagree.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	function ans_comp_admin_ledger( $limit = 100 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$keys = ans_comp_meta_keys();

		$orders = wc_get_orders(
			array(
				'limit'      => max( 1, (int) $limit ),
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => array( 'completed', 'failed', 'cancelled' ),
				'meta_key'   => $keys['flag'],   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'yes',           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$rows = array();

		foreach ( $orders as $order ) {
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' x' . (int) $item->get_quantity();
			}

			$voided = $order->get_meta( '_ans_comp_voided' );

			$rows[] = array(
				'order_id'  => $order->get_id(),
				'date'      => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
				'items'     => implode( ', ', $items ),
				'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'     => $order->get_billing_email(),
				'qty'       => array_sum( array_map( static function ( $i ) { return (int) $i->get_quantity(); }, $order->get_items() ) ),
				'retail'    => (float) $order->get_meta( $keys['retail'] ),
				'reason'    => (string) $order->get_meta( $keys['reason'] ),
				'source'    => (string) $order->get_meta( $keys['source'] ),
				'status'    => $order->get_status(),
				'voided'    => ! empty( $voided ),
				'void_note' => (string) $order->get_meta( '_ans_comp_void_reason' ),
				'edit_url'  => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : '',
			);
		}

		return $rows;
	}
}

/* -------------------------------------------------------------------------
 * Write handlers - admin-post, then redirect. Never render from a POST.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'ans_comp_admin_handle_issue' ) ) {
	/**
	 * Issue a comp, then redirect back with the outcome.
	 *
	 * @return void
	 */
	function ans_comp_admin_handle_issue() {
		if ( ! current_user_can( ans_comp_admin_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to issue comp tickets.', 'ans-comp-tickets' ), 403 );
		}
		check_admin_referer( 'ans_comp_issue' );

		$result = ans_comp_issue(
			array(
				'performance_id'  => isset( $_POST['performance_id'] ) ? (int) $_POST['performance_id'] : 0,
				'qty'             => isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 1,
				'recipient_name'  => isset( $_POST['recipient_name'] ) ? sanitize_text_field( wp_unslash( $_POST['recipient_name'] ) ) : '',
				'recipient_email' => isset( $_POST['recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['recipient_email'] ) ) : '',
				'reason'          => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
				'recipient_note'  => isset( $_POST['recipient_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['recipient_note'] ) ) : '',
				'subject'         => isset( $_POST['comp_subject'] ) ? wp_unslash( $_POST['comp_subject'] ) : '',
				'source'          => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				ans_comp_admin_url(
					array(
						'ans_comp_error' => rawurlencode( $result->get_error_message() ),
					)
				)
			);
			exit;
		}

		wp_safe_redirect(
			ans_comp_admin_url(
				array(
					'ans_comp_issued'  => (int) $result['order_id'],
					'ans_comp_tickets' => count( (array) $result['ticket_ids'] ),
				)
			)
		);
		exit;
	}
	add_action( 'admin_post_ans_comp_issue', 'ans_comp_admin_handle_issue' );
}

if ( ! function_exists( 'ans_comp_admin_handle_void' ) ) {
	/**
	 * Void a comp, then redirect back with the outcome.
	 *
	 * @return void
	 */
	function ans_comp_admin_handle_void() {
		if ( ! current_user_can( ans_comp_admin_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to void comp tickets.', 'ans-comp-tickets' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		check_admin_referer( 'ans_comp_void_' . $order_id );

		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result = ans_comp_void( $order_id, $reason );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				ans_comp_admin_url(
					array(
						'ans_comp_error' => rawurlencode( $result->get_error_message() ),
					)
				)
			);
			exit;
		}

		wp_safe_redirect(
			ans_comp_admin_url(
				array(
					'ans_comp_voided'  => (int) $order_id,
					'ans_comp_deleted' => count( (array) $result['tickets_deleted'] ),
				)
			)
		);
		exit;
	}
	add_action( 'admin_post_ans_comp_void', 'ans_comp_admin_handle_void' );
}

/* -------------------------------------------------------------------------
 * Views
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'ans_comp_admin_notices' ) ) {
	/**
	 * Render the result of the last write, read from the query string.
	 *
	 * @return void
	 */
	function ans_comp_admin_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of our own redirect args.
		if ( isset( $_GET['ans_comp_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['ans_comp_error'] ) ) ) )
			);
		}

		if ( isset( $_GET['ans_comp_issued'] ) ) {
			$order_id = (int) $_GET['ans_comp_issued'];
			$tickets  = isset( $_GET['ans_comp_tickets'] ) ? (int) $_GET['ans_comp_tickets'] : 0;
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				sprintf(
					/* translators: 1: ticket count, 2: order id */
					esc_html( _n( '%1$d comp ticket issued on order #%2$d. The recipient has been emailed their ticket.', '%1$d comp tickets issued on order #%2$d. The recipient has been emailed their tickets.', $tickets, 'ans-comp-tickets' ) ),
					(int) $tickets,
					(int) $order_id
				)
			);
		}

		if ( isset( $_GET['ans_comp_voided'] ) ) {
			$order_id = (int) $_GET['ans_comp_voided'];
			$deleted  = isset( $_GET['ans_comp_deleted'] ) ? (int) $_GET['ans_comp_deleted'] : 0;
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				sprintf(
					/* translators: 1: order id, 2: ticket count */
					esc_html__( 'Comp order #%1$d voided. %2$d ticket(s) permanently deleted - nothing scannable remains.', 'ans-comp-tickets' ),
					(int) $order_id,
					(int) $deleted
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}

if ( ! function_exists( 'ans_comp_admin_render_issue_form' ) ) {
	/**
	 * The issue form.
	 *
	 * @return void
	 */
	function ans_comp_admin_render_issue_form() {
		$groups = ans_comp_admin_ticket_groups();
		?>
		<h2><?php esc_html_e( 'Issue a comp ticket', 'ans-comp-tickets' ); ?></h2>

		<?php if ( empty( $groups ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'No ticket products found on a published event. Publish the event first - a draft event silently disables both sales and ticket generation.', 'ans-comp-tickets' ); ?>
			</p></div>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ans_comp_issue" />
			<?php wp_nonce_field( 'ans_comp_issue' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ans_comp_performance"><?php esc_html_e( 'Performance and tier', 'ans-comp-tickets' ); ?></label></th>
					<td>
						<select id="ans_comp_performance" name="performance_id" required style="min-width:32em">
							<option value=""><?php esc_html_e( '- Choose -', 'ans-comp-tickets' ); ?></option>
							<?php foreach ( $groups as $group ) : ?>
								<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
									<?php foreach ( $group['products'] as $product_id => $product ) : ?>
										<option value="<?php echo esc_attr( (string) $product_id ); ?>">
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: tier name, 2: retail price */
													__( '%1$s - %2$s retail', 'ans-comp-tickets' ),
													$product['label'],
													ans_comp_admin_money( $product['price'] )
												)
											);
											?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The comp is recorded at full retail value with a 100% discount, so the order shows what was given away.', 'ans-comp-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ans_comp_qty"><?php esc_html_e( 'How many', 'ans-comp-tickets' ); ?></label></th>
					<td><input type="number" id="ans_comp_qty" name="qty" value="2" min="1" max="20" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ans_comp_name"><?php esc_html_e( 'Recipient name', 'ans-comp-tickets' ); ?></label></th>
					<td><input type="text" id="ans_comp_name" name="recipient_name" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ans_comp_email"><?php esc_html_e( 'Recipient email', 'ans-comp-tickets' ); ?></label></th>
					<td>
						<input type="email" id="ans_comp_email" name="recipient_email" class="regular-text" required />
						<p class="description"><?php esc_html_e( 'Required - this is where the ticket is sent.', 'ans-comp-tickets' ); ?></p>
					</td>
				</tr>
				<?php
				/*
				 * Jonathan, 2026-08-30, pointing at this form: "Set the subject
				 * line to what we just set it but have an 'edit subject line'
				 * just under Recipient Email and above Reason."
				 *
				 * Prefilled with the RESOLVED default, not the raw template. Kim
				 * never sees {from}, because a comp she issues has no singer to
				 * name - showing her a placeholder that resolves to nothing on
				 * every comp she will ever send would be a puzzle rather than a
				 * feature. She reads the sentence the guest will read.
				 *
				 * ADMIN ONLY. The Hub's cart has no equivalent field, per
				 * Jonathan: "Singers should not be able to edit, but Kim should
				 * in her admin portal comp paths." A subject line is the
				 * organisation's voice reaching a member of the public.
				 */
				$ans_comp_subject_default = function_exists( 'ans_comp_default_subject' )
					? trim( str_replace( '{from}', '', ans_comp_default_subject() ) )
					: '';
				?>
				<tr>
					<th scope="row"><label for="ans_comp_subject"><?php esc_html_e( 'Email subject', 'ans-comp-tickets' ); ?></label></th>
					<td>
						<input type="text" id="ans_comp_subject" name="comp_subject" class="large-text"
							value="<?php echo esc_attr( $ans_comp_subject_default ); ?>"
							maxlength="<?php echo esc_attr( (string) ( defined( 'ANS_COMP_SUBJECT_MAX' ) ? ANS_COMP_SUBJECT_MAX : 160 ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'The line the guest sees in their inbox. Edit it for this comp, or leave it as it is. Clear it and the standard wording is used.', 'ans-comp-tickets' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ans_comp_reason"><?php esc_html_e( 'Reason', 'ans-comp-tickets' ); ?></label></th>
					<td>
						<input type="text" id="ans_comp_reason" name="reason" class="large-text" required
							placeholder="<?php esc_attr_e( 'e.g. Guest of the composer', 'ans-comp-tickets' ); ?>" />
						<p class="description"><?php esc_html_e( 'Required. Every comp is a decision and the ledger records it.', 'ans-comp-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ans_comp_note"><?php esc_html_e( 'Note to recipient', 'ans-comp-tickets' ); ?></label></th>
					<td>
						<textarea id="ans_comp_note" name="recipient_note" class="large-text" rows="3"
							maxlength="<?php echo esc_attr( (string) ANS_COMP_NOTE_MAX ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. We are so glad you can join us - come and say hello afterwards.', 'ans-comp-tickets' ); ?>"></textarea>
						<p class="description">
							<?php
							printf(
								/* translators: %d: maximum characters. */
								esc_html__( 'Optional. Appears in the email with the ticket. This is a message to the GUEST - the Reason above is the internal record and is never shown to them. Up to %d characters.', 'ans-comp-tickets' ),
								(int) ANS_COMP_NOTE_MAX
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Issue comp ticket', 'ans-comp-tickets' ) ); ?>
		</form>
		<?php
	}
}

if ( ! function_exists( 'ans_comp_admin_render_ledger' ) ) {
	/**
	 * The ledger table.
	 *
	 * @return void
	 */
	function ans_comp_admin_render_ledger() {
		$rows  = ans_comp_admin_ledger();
		$total = 0.0;

		foreach ( $rows as $row ) {
			if ( ! $row['voided'] ) {
				$total += (float) $row['retail'];
			}
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Comp ledger', 'ans-comp-tickets' ); ?></h2>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No comps issued yet.', 'ans-comp-tickets' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: comp count, 2: retail total */
					__( '%1$d comp order(s). Retail value given away, excluding voided: %2$s', 'ans-comp-tickets' ),
					count( $rows ),
					ans_comp_admin_money( $total )
				)
			);
			?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:6em"><?php esc_html_e( 'Order', 'ans-comp-tickets' ); ?></th>
					<th scope="col" style="width:10em"><?php esc_html_e( 'Issued', 'ans-comp-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ticket', 'ans-comp-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipient', 'ans-comp-tickets' ); ?></th>
					<th scope="col" style="width:7em"><?php esc_html_e( 'Retail', 'ans-comp-tickets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'ans-comp-tickets' ); ?></th>
					<th scope="col" style="width:9em"><?php esc_html_e( 'Status', 'ans-comp-tickets' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td>
						<?php if ( $row['edit_url'] ) : ?>
							<a href="<?php echo esc_url( $row['edit_url'] ); ?>">#<?php echo (int) $row['order_id']; ?></a>
						<?php else : ?>
							#<?php echo (int) $row['order_id']; ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row['date'] ); ?></td>
					<td><?php echo esc_html( $row['items'] ); ?></td>
					<td>
						<?php echo esc_html( $row['name'] ); ?><br />
						<span class="description"><?php echo esc_html( $row['email'] ); ?></span>
					</td>
					<td><?php echo esc_html( ans_comp_admin_money( (float) $row['retail'] ) ); ?></td>
					<td><?php echo esc_html( $row['reason'] ); ?></td>
					<td>
						<?php if ( $row['voided'] ) : ?>
							<strong><?php esc_html_e( 'Voided', 'ans-comp-tickets' ); ?></strong>
							<?php if ( $row['void_note'] ) : ?>
								<br /><span class="description"><?php echo esc_html( $row['void_note'] ); ?></span>
							<?php endif; ?>
						<?php elseif ( 'completed' === $row['status'] ) : ?>
							<a href="<?php echo esc_url( ans_comp_admin_url( array( 'ans_comp_action' => 'void', 'order' => (int) $row['order_id'] ) ) ); ?>">
								<?php esc_html_e( 'Void', 'ans-comp-tickets' ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( $row['status'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

if ( ! function_exists( 'ans_comp_admin_render_void_confirm' ) ) {
	/**
	 * Confirmation step for a void. Destructive and irreversible - the ticket
	 * instances are force-deleted, because a trashed ticket still owns its code
	 * and can be restored by anyone with access to the trash.
	 *
	 * @param int $order_id Order to void.
	 * @return void
	 */
	function ans_comp_admin_render_void_confirm( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'That order no longer exists.', 'ans-comp-tickets' )
			);
			return;
		}

		$tickets = ans_comp_count_tickets( $order_id );
		?>
		<h2><?php esc_html_e( 'Void this comp?', 'ans-comp-tickets' ); ?></h2>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: order id, 2: recipient email, 3: ticket count */
					__( 'Order #%1$d for %2$s. %3$d ticket(s) will be permanently deleted and the order cancelled.', 'ans-comp-tickets' ),
					(int) $order_id,
					$order->get_billing_email(),
					count( $tickets )
				)
			);
			?>
		</p>
		<p><strong><?php esc_html_e( 'This cannot be undone.', 'ans-comp-tickets' ); ?></strong></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ans_comp_void" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) (int) $order_id ); ?>" />
			<?php wp_nonce_field( 'ans_comp_void_' . (int) $order_id ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ans_comp_void_reason"><?php esc_html_e( 'Reason', 'ans-comp-tickets' ); ?></label></th>
					<td>
						<input type="text" id="ans_comp_void_reason" name="reason" class="large-text" required
							placeholder="<?php esc_attr_e( 'e.g. Issued to the wrong performance', 'ans-comp-tickets' ); ?>" />
						<p class="description"><?php esc_html_e( 'Required. The ledger records why.', 'ans-comp-tickets' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Void this comp', 'ans-comp-tickets' ); ?></button>
				<a class="button" href="<?php echo esc_url( ans_comp_admin_url() ); ?>"><?php esc_html_e( 'Cancel', 'ans-comp-tickets' ); ?></a>
			</p>
		</form>
		<?php
	}
}

if ( ! function_exists( 'ans_comp_admin_page' ) ) {
	/**
	 * Screen router.
	 *
	 * @return void
	 */
	function ans_comp_admin_page() {
		if ( ! current_user_can( ans_comp_admin_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to view comp tickets.', 'ans-comp-tickets' ), 403 );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Comp Tickets', 'ans-comp-tickets' ) . '</h1>';

		ans_comp_admin_notices();

		if ( ! function_exists( 'wc_get_order' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce is not active. Comp tickets cannot be issued.', 'ans-comp-tickets' ) . '</p></div></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch; the write itself is nonce-checked.
		$action = isset( $_GET['ans_comp_action'] ) ? sanitize_key( wp_unslash( $_GET['ans_comp_action'] ) ) : '';

		if ( 'void' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			ans_comp_admin_render_void_confirm( isset( $_GET['order'] ) ? (int) $_GET['order'] : 0 );
			echo '</div>';
			return;
		}

		ans_comp_admin_render_issue_form();
		ans_comp_admin_render_ledger();

		echo '</div>';
	}
}
