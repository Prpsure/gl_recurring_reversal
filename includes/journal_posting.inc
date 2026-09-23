<?php
/**********************************************************************
 * Journal cart builders + post wrapper — gl_recurring_reversal
 * Uses items_cart + write_journal_entries ONLY (never void_*).
 ***********************************************************************/

include_once($path_to_root.'/includes/ui/items_cart.inc');
include_once($path_to_root.'/gl/includes/db/gl_journal.inc');
include_once($path_to_root.'/gl/includes/gl_db.inc');
include_once($path_to_root.'/admin/db/voiding_db.inc');

/**
 * Load ST_JOURNAL into a NEW cart (order_id=0). Never calls void.
 * @return items_cart|false
 */
function grr_build_cart_from_journal($trans_no, $new_date, $new_memo = null, $new_ref = null, $negate = false)
{
	global $Refs;

	$header = get_journal(ST_JOURNAL, $trans_no);
	if (!$header)
		return false;

	$cart = new items_cart(ST_JOURNAL);
	$cart->order_id = 0;
	$cart->tran_date = $cart->doc_date = $cart->event_date = $new_date;
	$cart->currency = $header['currency'];
	$cart->rate = $header['rate'];
	$cart->source_ref = $header['source_ref'];

	$result = get_gl_trans(ST_JOURNAL, $trans_no);
	if ($result)
	{
		while ($row = db_fetch($result))
		{
			$curr_amount = $cart->rate
				? round($row['amount'] / $cart->rate, user_price_dec())
				: $row['amount'];
			if ($negate)
				$curr_amount = -$curr_amount;
			if ($curr_amount)
			{
				$cart->add_gl_item($row['account'], $row['dimension_id'], $row['dimension2_id'],
					$curr_amount, $row['memo_'], '', $row['person_id']);
			}
		}
	}

	if ($new_memo !== null)
		$cart->memo_ = $new_memo;
	else
		$cart->memo_ = get_comments_string(ST_JOURNAL, $trans_no);

	if ($new_ref !== null && $new_ref !== '')
		$cart->reference = $new_ref;
	else
		$cart->reference = $Refs->get_next(ST_JOURNAL, null, $new_date);

	return $cart;
}

/**
 * Opposite amounts, same accounts/dims/persons/currency/rate; new date & memo.
 */
function grr_build_reversal_cart($trans_no, $reversal_date, $memo)
{
	return grr_build_cart_from_journal($trans_no, $reversal_date, $memo, null, true);
}

/**
 * Build cart from recurring def + lines; date = next_due (or override).
 */
function grr_build_cart_from_def($def_id, $post_date)
{
	global $Refs;

	$def = get_recurring_def($def_id);
	if (!$def)
		return false;

	$cart = new items_cart(ST_JOURNAL);
	$cart->order_id = 0;
	$cart->tran_date = $cart->doc_date = $cart->event_date = $post_date;
	$cart->currency = $def['currency'] !== '' ? $def['currency'] : get_company_currency();
	$cart->rate = $def['rate'] ? $def['rate'] : 1;
	$cart->memo_ = $def['memo_'];
	$cart->reference = $Refs->get_next(ST_JOURNAL, null, $post_date);
	$cart->source_ref = $def['name'];

	$result = get_recurring_def_lines($def_id);
	while ($row = db_fetch($result))
	{
		if (floatcmp($row['amount'], 0) == 0)
			continue;
		$person_id = $row['person_id'];
		$cart->add_gl_item($row['account'], $row['dimension_id'], $row['dimension2_id'],
			$row['amount'], $row['memo_'], '', $person_id);
	}

	return $cart;
}

/**
 * Post cart; returns new trans_no or false.
 * Caller owns outer transaction for batches (nested begin/commit OK).
 */
function grr_post_journal_cart(&$cart)
{
	global $Refs;

	if (!$cart || !count($cart->gl_items))
		return false;

	if ($cart->reference === '' || $cart->reference === null)
		$cart->reference = $Refs->get_next(ST_JOURNAL, null, $cart->tran_date);

	$cart->order_id = 0; // force new journal — never reenter/void
	return write_journal_entries($cart);
}
