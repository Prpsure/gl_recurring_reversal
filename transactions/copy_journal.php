<?php
/**********************************************************************
 * Copy Journal — load ST_JOURNAL into editable draft then post
 ***********************************************************************/
$page_security = 'SA_JOURNALENTRY';
$path_to_root = '../../..';

// Must load items_cart before session.inc so $_SESSION['grr_copy_cart'] unserializes
// (same pattern as gl/gl_journal.php). Otherwise Process/UpdateLines hit incomplete object.
include_once($path_to_root.'/includes/ui/items_cart.inc');
include_once($path_to_root.'/includes/session.inc');
include_once($path_to_root.'/modules/gl_recurring_reversal/includes/grr_ui.inc');

$js = '';
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(900, 600);
if (user_use_date_picker())
	$js .= get_js_date_picker();

page(_($help_context = 'Copy Journal'), false, false, '', $js);

//--------------------------------------------------------------------------------

if (isset($_GET['AddedID']))
{
	$trans_no = $_GET['AddedID'];
	display_notification_centered(_('Journal entry has been entered').' #'.$trans_no);
	display_note(get_gl_view_str(ST_JOURNAL, $trans_no, _('&View this Journal Entry')));
	hyperlink_params($_SERVER['PHP_SELF'], _('Copy Another Journal'), '');
	display_footer_exit();
}

if (isset($_GET['trans_no']))
	$_POST['trans_no'] = (int)$_GET['trans_no'];
if (isset($_GET['type']) && (int)$_GET['type'] != ST_JOURNAL)
{
	display_error(_('Unsupported document type'));
	display_footer_exit();
}

//--------------------------------------------------------------------------------
// Load source
//--------------------------------------------------------------------------------

if (isset($_POST['LoadSource']) || (isset($_POST['trans_no']) && isset($_POST['Reload'])))
{
	$trans_no = (int)get_post('trans_no');
	$v = grr_validate_copy_source($trans_no);
	if ($v->abort)
	{
		$msg = ($v->abort_message !== '' && $v->abort_message !== null)
			? $v->abort_message : _('Unable to load journal.');
		display_error($msg);
		unset($_SESSION['grr_copy_cart']);
	}
	else
	{
		$new_date = is_date(get_post('new_date')) ? get_post('new_date') : new_doc_date();
		if (!is_date_in_fiscalyear($new_date))
			$new_date = end_fiscalyear();
		$memo = get_post('new_memo_', null);
		if ($memo === null || $memo === '')
			$memo = get_comments_string(ST_JOURNAL, $trans_no);
		$cart = grr_build_cart_from_journal($trans_no, $new_date, $memo, get_post('new_ref', null));
		if ($cart === false)
			display_error(_('Unable to load journal.'));
		else
		{
			$_SESSION['grr_copy_cart'] = $cart;
			$_POST['new_date'] = $cart->tran_date;
			$_POST['new_memo_'] = $cart->memo_;
			$_POST['new_ref'] = $cart->reference;
			$_POST['source_trans_no'] = $trans_no;
			display_notification(sprintf(_('Loaded journal #%d for copy.'), $trans_no));
		}
	}
	// DEF-012: Load Journal is Ajax (aspect=default); refresh body so draft/Process UI appears
	$Ajax->activate('_page_body');
}

//--------------------------------------------------------------------------------
// Line edits from POST into session cart
//--------------------------------------------------------------------------------

function grr_copy_apply_line_edits(&$cart)
{
	if (!isset($_POST['line_count']))
		return;
	$count = (int)$_POST['line_count'];
	$cart->clear_items();
	for ($i = 0; $i < $count; $i++)
	{
		$account = get_post('c_account'.$i);
		if ($account === '' || $account === null)
			continue;
		$debit = input_num('c_debit'.$i);
		$credit = input_num('c_credit'.$i);
		$amount = $debit - $credit;
		if (floatcmp($amount, 0) == 0)
			continue;
		// Preserve subledger person across line rebuild (DEF-004)
		$person_id = get_post('c_person'.$i, null);
		if ($person_id === '' || $person_id === false)
			$person_id = null;
		$cart->add_gl_item($account, get_post('c_dim1'.$i, 0), get_post('c_dim2'.$i, 0),
			$amount, get_post('c_memo'.$i, ''), '', $person_id);
	}
}

if (isset($_POST['UpdateLines']) && isset($_SESSION['grr_copy_cart']))
{
	$cart = &$_SESSION['grr_copy_cart'];
	grr_copy_apply_line_edits($cart);
	$cart->tran_date = $cart->doc_date = $cart->event_date = get_post('new_date');
	$cart->memo_ = get_post('new_memo_');
	if (get_post('new_ref') !== '')
		$cart->reference = get_post('new_ref');
	display_notification(_('Lines updated.'));
	$Ajax->activate('_page_body');
}

if (isset($_POST['AddCopyLine']) && isset($_SESSION['grr_copy_cart']))
{
	$cart = &$_SESSION['grr_copy_cart'];
	grr_copy_apply_line_edits($cart);
	$_POST['extra_lines'] = 1 + (int)get_post('extra_lines', 0);
	$Ajax->activate('_page_body');
}

//--------------------------------------------------------------------------------
// Process post
//--------------------------------------------------------------------------------

if (isset($_POST['ProcessCopy']) && isset($_SESSION['grr_copy_cart']))
{
	$cart = &$_SESSION['grr_copy_cart'];
	grr_copy_apply_line_edits($cart);

	$input_error = 0;
	$new_date = get_post('new_date');
	if (!is_date($new_date))
	{
		display_error(_('The entered date is invalid.'));
		$input_error = 1;
	}
	elseif (!is_date_in_fiscalyear($new_date))
	{
		display_error(_('The entered date is out of fiscal year or is closed for further data entry.'));
		$input_error = 1;
	}
	if ($input_error == 0 && !grr_journal_balances($cart))
	{
		display_error(_('Journal does not balance'));
		$input_error = 1;
	}
	if ($input_error == 0 && !count($cart->gl_items))
	{
		display_error(_('You must enter at least one journal line.'));
		$input_error = 1;
	}

	if ($input_error == 0)
	{
		$cart->tran_date = $cart->doc_date = $cart->event_date = $new_date;
		$cart->memo_ = get_post('new_memo_');
		if (get_post('new_ref') !== '')
			$cart->reference = get_post('new_ref');

		$user = grr_current_user_login();
		begin_transaction();
		$run_id = grr_run_begin('COPY', $user, 'JE#'.get_post('source_trans_no'));
		$new_no = grr_post_journal_cart($cart);
		if ($new_no === false)
		{
			cancel_transaction();
			display_error(_('Unexpected error; journal was not posted.'));
			$input_error = 1;
		}
		else
		{
			grr_run_add_item($run_id, array(
				'source_type' => ST_JOURNAL,
				'source_trans_no' => get_post('source_trans_no'),
				'source_def_id' => null,
				'source_date' => $new_date,
				'amount' => $cart->gl_items_total_debit(),
				'new_trans_no' => $new_no,
				'status' => 'Posted',
				'reason' => '',
				'rule_id' => '',
			));
			grr_run_finish($run_id, 1, 1, 0);
			commit_transaction();
			unset($_SESSION['grr_copy_cart']);
			meta_forward($_SERVER['PHP_SELF'], 'AddedID='.$new_no);
		}
	}
	if ($input_error)
		$Ajax->activate('_page_body');
}

//--------------------------------------------------------------------------------
// UI
//--------------------------------------------------------------------------------

start_form();

start_table(TABLESTYLE2);
text_row(_('Source Journal #').':', 'trans_no', get_post('trans_no'), 10, 10);
end_table(1);
submit_center('LoadSource', _('Load Journal'), true, _('Load source journal for copy'), 'default');

if (isset($_SESSION['grr_copy_cart']))
{
	$cart = $_SESSION['grr_copy_cart'];
	$src = (int)get_post('source_trans_no', get_post('trans_no'));
	hidden('source_trans_no', $src);

	br();
	display_heading(sprintf(_('Copy of Journal #%d'), $src));

	$header = get_journal(ST_JOURNAL, $src);
	start_table(TABLESTYLE2);
	label_row(_('Original Date').':', $header ? sql2date($header['tran_date']) : '');
	label_row(_('Original Reference').':', $header ? $header['reference'] : '');
	label_row(_('Currency / Rate').':', $cart->currency.' / '.$cart->rate);
	date_row(_('New Date').':', 'new_date', '', true);
	text_row(_('New Reference').':', 'new_ref', $cart->reference, 20, 40);
	textarea_row(_('Memo').':', 'new_memo_', $cart->memo_, 40, 3);
	end_table(1);

	$dim = get_company_pref('use_dimension');
	$items = $cart->gl_items;
	$extra = (int)get_post('extra_lines', 0);
	$line_count = count($items) + $extra;
	if ($line_count < 2)
		$line_count = 2;
	hidden('line_count', $line_count);
	hidden('extra_lines', $extra);

	start_table(TABLESTYLE, "width='90%'");
	$th = array(_('Account'), _('Memo'));
	if ($dim >= 1) $th[] = _('Dimension').' 1';
	if ($dim > 1) $th[] = _('Dimension').' 2';
	$th[] = _('Debit');
	$th[] = _('Credit');
	table_header($th);

	for ($i = 0; $i < $line_count; $i++)
	{
		$gl = isset($items[$i]) ? $items[$i] : null;
		if ($gl && !isset($_POST['c_account'.$i]))
		{
			$_POST['c_account'.$i] = $gl->code_id;
			$_POST['c_memo'.$i] = $gl->reference;
			$_POST['c_dim1'.$i] = $gl->dimension_id;
			$_POST['c_dim2'.$i] = $gl->dimension2_id;
			$_POST['c_person'.$i] = $gl->person_id;
			if ($gl->amount >= 0)
			{
				$_POST['c_debit'.$i] = price_format($gl->amount);
				$_POST['c_credit'.$i] = price_format(0);
			}
			else
			{
				$_POST['c_debit'.$i] = price_format(0);
				$_POST['c_credit'.$i] = price_format(-$gl->amount);
			}
		}
		start_row();
		gl_all_accounts_list_cells(null, 'c_account'.$i, null, false, false, true);
		text_cells(null, 'c_memo'.$i, null, 20, 50);
		if ($dim >= 1)
			dimensions_list_cells(null, 'c_dim1'.$i, null, true, ' ', false, 1);
		if ($dim > 1)
			dimensions_list_cells(null, 'c_dim2'.$i, null, true, ' ', false, 2);
		amount_cells(null, 'c_debit'.$i);
		amount_cells(null, 'c_credit'.$i);
		hidden('c_person'.$i, get_post('c_person'.$i, $gl ? $gl->person_id : ''));
		end_row();
	}
	end_table(1);

	submit_center_first('UpdateLines', _('Update Lines'), _('Recalculate from form'), true);
	submit('AddCopyLine', _('Add Line'), true, _('Add blank line'), true);
	submit_center_last('ProcessCopy', _('Process'), _('Post copied journal'), 'default');
}

end_form();
end_page();
