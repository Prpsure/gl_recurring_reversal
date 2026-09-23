<?php
/**********************************************************************
 * Bulk Journal Reversal — opposite ST_JOURNAL via write_journal_entries
 ***********************************************************************/
$page_security = 'SA_GLBULKREVERSE';
$path_to_root = '../../..';

// DEF-016: load GRR_RunLog before session so $_SESSION['grr_last_run'] unserializes
include_once($path_to_root.'/modules/gl_recurring_reversal/includes/results_log.inc');
include_once($path_to_root.'/includes/session.inc');
// DEF-014: remap extension SA_* before page() (FrontHrm pattern)
add_access_extensions();
include_once($path_to_root.'/modules/gl_recurring_reversal/includes/grr_ui.inc');

$js = '';
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(900, 600);
if (user_use_date_picker())
	$js .= get_js_date_picker();

// Before page() — CSV needs raw headers (DEF-018); backups.php pattern
grr_handle_csv_export();

page(_($help_context = 'Bulk Journal Reversal'), false, false, '', $js);

//--------------------------------------------------------------------------------
// Defaults
//--------------------------------------------------------------------------------

if (!isset($_POST['from_date']))
	$_POST['from_date'] = begin_month(Today());
if (!isset($_POST['to_date']))
	$_POST['to_date'] = end_month(Today());
if (!isset($_POST['reversal_date']))
	$_POST['reversal_date'] = Today();

function grr_default_reversal_memo($trans_no, $tran_date, $prefix)
{
	$base = sprintf(_('Reversal of JE #%s dated %s'), $trans_no, $tran_date);
	$prefix = trim($prefix);
	return $prefix !== '' ? $prefix.' '.$base : $base;
}

//--------------------------------------------------------------------------------
// Confirm & Post
//--------------------------------------------------------------------------------

if (isset($_POST['ConfirmReverse']))
{
	$selected = array();
	if (isset($_POST['rev']) && is_array($_POST['rev']))
		$selected = array_map('intval', array_keys($_POST['rev']));

	$rev_date = get_post('reversal_date');
	$memo_prefix = get_post('memo_prefix', '');

	$v = grr_validate_bulk_reverse($selected, $rev_date);
	if ($v->abort)
	{
		display_error($v->abort_message);
	}
	else
	{
		$run_log = new GRR_RunLog();
		$run_log->run_type = 'REVERSE';
		$run_log->selected = count($selected);

		$eligible = array();
		foreach ($v->items as $ir)
		{
			if ($ir->status === 'ok')
				$eligible[] = $ir;
			else
			{
				$run_log->skipped++;
				$run_log->rows[] = array(
					'orig_je' => $ir->source_id,
					'date' => $ir->source_date,
					'amount' => $ir->amount,
					'new_je' => '',
					'status' => 'Skipped',
					'reason' => $ir->reason,
					'rule_id' => $ir->rule_id,
				);
			}
		}

		$user = grr_current_user_login();
		$ok = true;
		$fail_reason = '';
		begin_transaction();
		$run_id = grr_run_begin('REVERSE', $user, $rev_date);
		$run_log->run_id = $run_id;

		foreach ($v->items as $ir)
		{
			if ($ir->status !== 'ok')
			{
				grr_run_add_item($run_id, array(
					'source_type' => ST_JOURNAL,
					'source_trans_no' => $ir->source_id,
					'source_def_id' => null,
					'source_date' => $ir->source_date,
					'amount' => $ir->amount,
					'new_trans_no' => null,
					'status' => 'Skipped',
					'reason' => $ir->reason,
					'rule_id' => $ir->rule_id,
				));
			}
		}

		foreach ($eligible as $ir)
		{
			$memo = grr_default_reversal_memo($ir->source_id, $ir->source_date, $memo_prefix);
			$cart = grr_build_reversal_cart($ir->source_id, $rev_date, $memo);
			if (!$cart || !grr_journal_balances($cart))
			{
				$ok = false;
				$fail_reason = _('Unexpected error; no reversals were posted.');
				break;
			}
			$new_no = grr_post_journal_cart($cart);
			if ($new_no === false)
			{
				$ok = false;
				$fail_reason = _('Unexpected error; no reversals were posted.');
				break;
			}
			$link_id = add_reversal_link(ST_JOURNAL, $ir->source_id, ST_JOURNAL, $new_no, $rev_date, $user);
			if ($link_id === false)
			{
				$ok = false;
				// DEF-011 — UNIQUE race / already reversed: soft cancel whole batch
				if (grr_is_duplicate_reversal_error())
					$fail_reason = sprintf(
						_('Journal #%s is already reversed (or was reversed concurrently). No reversals were posted.'),
						$ir->source_id
					);
				else
					$fail_reason = _('Unexpected error; no reversals were posted.');
				break;
			}
			$run_log->posted++;
			$run_log->rows[] = array(
				'orig_je' => $ir->source_id,
				'date' => $ir->source_date,
				'amount' => $ir->amount,
				'new_je' => $new_no,
				'status' => 'Posted',
				'reason' => '',
				'rule_id' => '',
			);
			grr_run_add_item($run_id, array(
				'source_type' => ST_JOURNAL,
				'source_trans_no' => $ir->source_id,
				'source_def_id' => null,
				'source_date' => $ir->source_date,
				'amount' => $ir->amount,
				'new_trans_no' => $new_no,
				'status' => 'Posted',
				'reason' => '',
				'rule_id' => '',
			));
		}

		if ($ok)
		{
			grr_run_finish($run_id, $run_log->selected, $run_log->posted, $run_log->skipped);
			commit_transaction();
			grr_session_store_log($run_log);
			grr_display_results_panel($run_log);
		}
		else
		{
			cancel_transaction();
			display_error($fail_reason !== '' ? $fail_reason
				: _('Unexpected error; no reversals were posted.'));
		}
	}
}

//--------------------------------------------------------------------------------
// Filters
//--------------------------------------------------------------------------------

start_form();

start_table(TABLESTYLE_NOBORDER);
start_row();
date_cells(_('From').':', 'from_date');
date_cells(_('To').':', 'to_date');
end_row();
start_row();
gl_all_accounts_list_cells(_('Account').':', 'account', null, false, false, _('All Accounts'));
text_cells(_('Memo contains').':', 'memo', null, 20, 60);
end_row();
start_row();
amount_cells(_('Amount min').':', 'amount_min');
amount_cells(_('Amount max').':', 'amount_max');
users_list_cells(_('User').':', 'user_id', null, false, _('All Users'));
end_row();
end_table(1);

submit_center('ApplyFilter', _('Apply Filters'), true, _('Refresh eligible journals'), 'default');

//--------------------------------------------------------------------------------
// Eligible list + preview
//--------------------------------------------------------------------------------

if (isset($_POST['ApplyFilter']) || isset($_POST['PreviewReverse']) || isset($_POST['ConfirmReverse']))
{
	$amount_min = get_post('amount_min');
	$amount_max = get_post('amount_max');
	$filters = array(
		'from_date' => get_post('from_date'),
		'to_date' => get_post('to_date'),
		'account' => get_post('account'),
		'memo' => get_post('memo'),
		'amount_min' => ($amount_min !== '' ? input_num('amount_min') : ''),
		'amount_max' => ($amount_max !== '' ? input_num('amount_max') : ''),
		'user_id' => get_post('user_id'),
	);

	$rows = list_eligible_journals($filters);

	br();
	display_heading(_('Eligible Journals'));

	if (!count($rows))
		display_notification(_('No eligible journals match the filters.'));
	else
	{
		start_table(TABLESTYLE, "width='95%'");
		$th = array('', _('JE #'), _('Date'), _('Reference'), _('Amount'), _('Currency'), _('Memo'), _('User'));
		table_header($th);
		$k = 0;
		$total = 0;
		foreach ($rows as $row)
		{
			alt_table_row_color($k);
			$checked = isset($_POST['rev'][$row['trans_no']]);
			check_cells(null, 'rev['.$row['trans_no'].']', $checked);
			label_cell(get_gl_view_str(ST_JOURNAL, $row['trans_no'], '#'.$row['trans_no']));
			label_cell(sql2date($row['tran_date']));
			label_cell($row['reference']);
			amount_cell($row['amount']);
			label_cell($row['currency']);
			label_cell($row['memo_']);
			label_cell($row['user_login']);
			end_row();
			$total += abs($row['amount']);
		}
		end_table(1);

		br();
		start_table(TABLESTYLE2);
		date_row(_('Reversal Date').':', 'reversal_date', '', true);
		text_row(_('Memo prefix (optional)').':', 'memo_prefix', null, 30, 60);
		end_table(1);

		if (isset($_POST['PreviewReverse']))
		{
			$sel = isset($_POST['rev']) && is_array($_POST['rev']) ? array_keys($_POST['rev']) : array();
			$cnt = count($sel);
			$sum = 0;
			foreach ($rows as $row)
			{
				if (isset($_POST['rev'][$row['trans_no']]))
					$sum += abs($row['amount']);
			}
			display_notification(sprintf(
				_('Preview: %d journal(s) selected; total absolute amount %s. Confirm to post opposite journals on %s.'),
				$cnt, price_format($sum), get_post('reversal_date')
			));
			submit_center('ConfirmReverse', _('Confirm & Post Reversals'), true,
				_('Post opposite journals'), 'default');
		}
		else
		{
			submit_center('PreviewReverse', _('Preview Selected'), true,
				_('Show counts and totals before posting'), 'default');
		}
	}
}

end_form();

// DEF-015: Apply/Preview/Confirm are Ajax default submits; refresh body so lists/results appear
if (isset($_POST['ApplyFilter']) || isset($_POST['PreviewReverse']) || isset($_POST['ConfirmReverse']))
	$Ajax->activate('_page_body');

end_page();
