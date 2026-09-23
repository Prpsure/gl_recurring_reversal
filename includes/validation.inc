<?php
/**********************************************************************
 * Validation VR-1..VR-9 — gl_recurring_reversal
 ***********************************************************************/

class GRR_ValidationResult
{
	public $abort = false;
	public $abort_message = '';
	public $items = array();
}

class GRR_ItemResult
{
	public $source_id;
	public $source_date;
	public $amount;
	public $status; // 'ok'|'skip'
	public $reason = '';
	public $rule_id = '';
}

/**
 * Return true if cart or line array balances (sum ≈ 0).
 */
function grr_journal_balances($cart_or_lines)
{
	$total = 0;
	if (is_object($cart_or_lines) && isset($cart_or_lines->gl_items))
	{
		foreach ($cart_or_lines->gl_items as $gl)
			$total += $gl->amount;
	}
	elseif (is_array($cart_or_lines))
	{
		foreach ($cart_or_lines as $line)
		{
			if (is_object($line))
				$total += isset($line->amount) ? $line->amount : 0;
			else
				$total += isset($line['amount']) ? $line['amount'] : 0;
		}
	}
	else
		return false;

	return floatcmp($total, 0) == 0;
}

function grr_validate_copy_source($trans_no)
{
	$result = new GRR_ValidationResult();
	$item = new GRR_ItemResult();
	$item->source_id = $trans_no;

	$header = get_journal(ST_JOURNAL, $trans_no);
	if (!$header)
	{
		// Might be wrong type or missing — try any type via gl? Spec: ST_JOURNAL only
		$item->status = 'skip';
		$item->rule_id = 'VR-5';
		$item->reason = _('Unsupported document type');
		$result->abort = true;
		$result->abort_message = $item->reason;
		$result->items[] = $item;
		return $result;
	}

	$item->source_date = sql2date($header['tran_date']);
	$item->amount = $header['amount'];

	if (get_voided_entry(ST_JOURNAL, $trans_no))
	{
		$item->status = 'skip';
		$item->rule_id = 'VR-6';
		$item->reason = _('Cannot copy a voided journal');
		$result->abort = true;
		$result->abort_message = $item->reason;
		$result->items[] = $item;
		return $result;
	}

	$item->status = 'ok';
	$result->items[] = $item;
	return $result;
}

function grr_validate_generate_item($def_row)
{
	$item = new GRR_ItemResult();
	$item->source_id = $def_row['id'];
	$item->source_date = isset($def_row['next_due_date']) ? sql2date($def_row['next_due_date']) : '';
	$item->amount = 0;

	if (!empty($def_row['inactive']))
	{
		$item->status = 'skip';
		$item->rule_id = 'VR-8';
		$item->reason = _('Inactive template');
		return $item;
	}

	if (empty($def_row['next_due_date']) || !is_date(sql2date($def_row['next_due_date'])))
	{
		$item->status = 'skip';
		$item->rule_id = 'VR-7';
		$item->reason = _('Invalid next-due date');
		return $item;
	}

	$lines = array();
	$result = get_recurring_def_lines($def_row['id']);
	$sum = 0;
	while ($row = db_fetch($result))
	{
		$lines[] = $row;
		$sum += $row['amount'];
	}
	$item->amount = abs($sum) > 0 ? abs($sum) / 2 : 0; // display approx debit total

	if (!grr_journal_balances($lines))
	{
		$item->status = 'skip';
		$item->rule_id = 'VR-9';
		$item->reason = _('Journal does not balance');
		return $item;
	}

	$item->status = 'ok';
	return $item;
}

/**
 * Phase 1: VR-1, VR-2 abort. Phase 2: per-item VR-3..5,9.
 */
function grr_validate_bulk_reverse($selected_trans_nos, $reversal_date)
{
	$result = new GRR_ValidationResult();

	if (!is_array($selected_trans_nos) || count($selected_trans_nos) == 0)
	{
		$result->abort = true;
		$result->abort_message = _('Select at least one journal to reverse.');
		return $result;
	}

	if ($reversal_date === '' || !is_date($reversal_date))
	{
		$result->abort = true;
		$result->abort_message = _('The entered date is invalid.');
		return $result;
	}
	if (!is_date_in_fiscalyear($reversal_date))
	{
		$result->abort = true;
		$result->abort_message = _('The reversal date is not in an open fiscal year.');
		return $result;
	}

	foreach ($selected_trans_nos as $trans_no)
	{
		$trans_no = (int)$trans_no;
		$item = new GRR_ItemResult();
		$item->source_id = $trans_no;

		$header = get_journal(ST_JOURNAL, $trans_no);
		if (!$header)
		{
			$item->status = 'skip';
			$item->rule_id = 'VR-5';
			$item->reason = _('Unsupported document type');
			$result->items[] = $item;
			continue;
		}

		$item->source_date = sql2date($header['tran_date']);
		$item->amount = $header['amount'];

		if (get_voided_entry(ST_JOURNAL, $trans_no))
		{
			$item->status = 'skip';
			$item->rule_id = 'VR-3';
			$item->reason = _('Voided journal cannot be reversed');
			$result->items[] = $item;
			continue;
		}

		$link = get_reversal_link_for_original(ST_JOURNAL, $trans_no);
		if ($link)
		{
			$item->status = 'skip';
			$item->rule_id = 'VR-4';
			$item->reason = sprintf(_('Already reversed (JE #%s)'), $link['reversal_trans_no']);
			$result->items[] = $item;
			continue;
		}

		// Balance check from gl_trans (home amounts should sum to 0)
		$gl = get_gl_trans(ST_JOURNAL, $trans_no);
		$lines = array();
		while ($row = db_fetch($gl))
			$lines[] = $row;
		if (!count($lines) || !grr_journal_balances($lines))
		{
			$item->status = 'skip';
			$item->rule_id = 'VR-9';
			$item->reason = _('Journal does not balance');
			$result->items[] = $item;
			continue;
		}

		$item->status = 'ok';
		$result->items[] = $item;
	}

	return $result;
}
