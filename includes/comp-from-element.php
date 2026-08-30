<?php
/**
 * "Comp from ..." as a Ticket Designer element.
 *
 * Jonathan, 2026-08-30, looking at a comp ticket: "There is no name on the ticket
 * at all. I think we should mark the ticket somewhere with 'Comp From:' followed by
 * the singer who gave it to them, instead of the name of the person receiving it."
 *
 * WHY NOT THE RECIPIENT'S NAME. Two reasons, and the second is the real one.
 * Tickera's per-attendee `owner_data` is empty on comp orders, so there is no
 * recipient name on the ticket to print. But even with one, "Comp from Sarah's
 * choir friend" is the fact that matters at the door and to the person holding it -
 * a name they already know is theirs tells them nothing.
 *
 * HOW THE TICKET DESIGNER FINDS THIS
 * ----------------------------------
 * The designer's Field dropdown is a curated hardcoded list PLUS an auto-discovered
 * group built from anything registered through tickera_register_template_element().
 * Registered elements appear under "Add-on Fields" as el_<element_name>, and their
 * printed value comes from calling this class's own ticket_content().
 *
 * Two constraints inherited from ars-nova-ticketing-bridge's venue-address element,
 * which is the working precedent for all of this:
 *   1. element_name must not collide with Tickera's own core element names.
 *   2. element_name must NOT contain qr, barcode, logo, image, map, google or
 *      sponsor - any of those marks an element "visual" and drops it from the
 *      dropdown entirely. `ans_comp_from_element` is clear of all seven.
 *
 * WHY THIS LIVES HERE AND NOT IN THE BRIDGE. The bridge owns Tickera plumbing and
 * already has one element, so it was the obvious home. But this element reads comp
 * order meta and means nothing without comps - putting it in the bridge would teach
 * the ticketing layer about a concept it has no other reason to know, and would
 * leave a dead "Comp from" field in the designer on any site without this plugin.
 *
 * @package ans-comp-tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The line a comp ticket should carry, or '' for a ticket that is not a comp.
 *
 * Resolves the ORDER from the ticket instance's post_parent. That is the one place
 * the instance points at the order - `event_id` on the instance points at the
 * event, which is a different question and the trap the bridge's location element
 * documents.
 *
 * @param int $ticket_instance_id tc_tickets_instances post ID.
 * @return string Unescaped display text; the caller escapes.
 */
if ( ! function_exists( 'ans_comp_from_line' ) ) {
	function ans_comp_from_line( $ticket_instance_id ) {
		$ticket_instance_id = (int) $ticket_instance_id;
		if ( ! $ticket_instance_id || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order_id = (int) wp_get_post_parent_id( $ticket_instance_id );
		if ( ! $order_id ) {
			return '';
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! ans_comp_is_comp_order( $order ) ) {
			return ''; // A bought ticket carries no such line, and must not.
		}

		$from = ans_comp_note_attribution( $order );

		/* translators: %s: the singer or organisation that gave the comp. */
		return sprintf( __( 'Comp from %s', 'ans-comp-tickets' ), $from );
	}
}

/*
 * Registered on init, defensively: Tickera may be inactive, the designer add-on may
 * be absent, and this file may be loaded twice in one request. Each is a return,
 * not a fatal - a missing ticket element is a cosmetic loss, and taking the site
 * down over it would be a far worse trade.
 */
add_action(
	'init',
	function () {
		if ( ! class_exists( '\Tickera\TC_Ticket_Template_Elements' ) ) {
			return;
		}
		if ( ! function_exists( '\Tickera\tickera_register_template_element' ) ) {
			return;
		}
		if ( class_exists( 'ANS_Comp_From_Element' ) ) {
			return;
		}

		/**
		 * Prints "Comp from <singer>" on a comp ticket, and nothing at all on a
		 * ticket somebody paid for.
		 */
		class ANS_Comp_From_Element extends \Tickera\TC_Ticket_Template_Elements {

			public $element_name = 'ans_comp_from_element';
			public $element_title = 'Comp From';
			public $font_awesome_icon = '<i class="fa fa-gift"></i>';

			public function on_creation() {
				$this->element_title = 'Comp From';
			}

			public function advanced_admin_element_settings() {
				ob_start();
				foreach ( array( 'get_att_fonts', 'get_font_colors', 'get_font_sizes', 'get_font_style' ) as $maybe ) {
					if ( method_exists( $this, $maybe ) ) {
						$this->$maybe();
					}
				}
				if ( method_exists( $this, 'get_default_text_value' ) ) {
					$this->get_default_text_value( 'Comp from Jane Singer' );
				}
				return ob_get_clean();
			}

			/**
			 * The value Tickera prints on the PDF.
			 *
			 * @param int|false $ticket_instance_id
			 * @param int|false $ticket_type_id
			 * @return string
			 */
			public function ticket_content( $ticket_instance_id = false, $ticket_type_id = false ) {
				if ( ! $ticket_instance_id ) {
					// Designer preview with no ticket in hand.
					return 'Comp from Jane Singer';
				}

				return ans_comp_from_line( (int) $ticket_instance_id );
			}
		}

		\Tickera\tickera_register_template_element( 'ANS_Comp_From_Element' );
	},
	20
);
