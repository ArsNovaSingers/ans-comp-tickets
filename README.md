# Ars Nova Comp Tickets

Issues complimentary Tickera tickets without a checkout.

Full spec: `claude/ticketing/ANS_Comp_Tickets_Plugin_Brief.md` in the Ars Nova Claude project.

## Why this exists

**An order is not a ticket.** The Tickera Bridge for WooCommerce does not listen for "order paid".
It hooks `woocommerce_new_order_item` and builds one `tc_tickets_instances` post per unit *as each
line item is written* — and it refuses to run unless the request carries live checkout data:

```php
$_post = ( $extension_data ? $extension_data : $_POST );
$_post = $this->handle_post( $_post, $item_id );
if ( !$extension_data && (!$_post || $_post && isset( $_post['requests'] )) ) {
    return;
}
```

So an order created in wp-admin, by WP-CLI, or through `wc/v3` REST reaches `completed`, shows the
ticket product, and **issues nothing**. No error anywhere. `tc_cart_contents` is an *output* of that
routine, not an input — writing it yourself does not cause generation.

The non-empty `$extension_data` argument is the only thing that clears that gate. The Bridge itself
uses exactly this path for block checkout, so this is a supported route rather than a hack.

## What it does

`ans_comp_issue()` creates a real WooCommerce order for the recipient, adds the ticket product as a
genuine line item with the retail price as `subtotal` and `0` as `total` (so the order reads
"$40.00 → $0.00" rather than looking like a ticket sold for nothing), stamps comp metadata, calls
the Bridge's ticket factory, **verifies by read-back**, and only then sets the order to `completed`
so the completed-order email carries the ticket PDFs.

If the ticket count does not match the quantity, the order is marked `failed` and nothing is
delivered. A success status is not proof of a write.

## Routes (all `manage_options`)

| Route | Does |
|---|---|
| `GET ans-comp/v1/diagnostics` | Read-only. HPOS state, Bridge availability, whether `get_post_type()` still resolves a real order. |
| `POST ans-comp/v1/issue` | Issue a comp. `performance_id`, `qty`, `recipient_name`, `recipient_email`, `reason`, `source`. |
| `GET ans-comp/v1/ledger` | Every comp order, with the retail value forgone. |

## Guardrails

- Refuses if the parent `tc_events` post is not published — a draft event silently disables both
  sales and ticket generation.
- Refuses if the product is not a Tickera ticket type (`_tc_is_ticket`).
- Requires a reason on every issue. Every comp is a decision and the ledger records it.
- `_ans_comp_generated` guard set *before* the factory runs — `create_order_ticket_instances()` is
  not idempotent and will happily create duplicate tickets.

## Prefix

`ans_comp_` only, every declaration guarded with `function_exists()`. `ans_sp_` (ticketing bridge,
season projects), `ans_spd_` (season packages), `ans_tb_`, `ansp_`, `ansc_`, `ansg_` and `ans_pkg_`
are taken. Do not "tidy" this prefix — the `ans_sp_`/`ans_spd_` near-miss would have fatalled the
bridge on every request.

## Rollback

Deactivate. That is the whole rollback — the plugin registers no cart or checkout hooks and rewrites
nothing existing ticketing depends on. Comp tickets already issued are ordinary Tickera ticket
instances and stay valid, downloadable and scannable without it.

## Status

**v0.1.0 — engine and diagnostics only.** No admin screen, no portal claim panel. Staging
verification first; see the brief's build order.
