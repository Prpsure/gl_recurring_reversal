<?php
/**********************************************************************
 * Generate Recurring Journals — user-triggered monthly generate
 ***********************************************************************/
$page_security = 'SA_GLRECURRINGPOST';
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

page(_($help_context = 'Generate Recurring Journals'), false, false, '', $js);

//--------------------------------------------------------------------------------

function grr_period_bounds($month, $year)
{
	$from_sql = sprintf('%04d-%02d-01', (int)$year, (int)$month);
	$to_sql = date('Y-m-t', strtotime($from_sql));
	return array(sql2date($from_sql), sql2date($to_sql));
}

function grr_date_in_period($date, $period_from, $period_to)
{
	if (!is_date($date) || !is_date($period_from) || !is_date($period_to))
		return false;
	// inclusive: not (date < from OR date > to)
	if (date1_greater_date2($period_from, $date))
		return false;
	if (date1_greater_date2($date, $period_to))
		return false;
	return true;
}

function grr_template_preview_amount($def_id)
{
	$total = 0;
	$result = get_recurring_def_lines($def_id);
	while ($row = db_fetch($result))
	{
		if ($row['amount'] > 0)
			$total += $row['amount'];
	}
	return $total;
}

if (!isset($_POST['gen_month']))
	$_POST['gen_month'] = (int)date('n');
if (!isset($_POST['gen_year']))
	$_POST['gen_year'] = (int)date('Y');

// PRG success view (DEF-006)
if (isset($_GET['Generated']) && !empty($_SESSION['grr_last_run'])
	&& $_SESSION['grr_last_run']->run_type == 'GENERATE')
{
	grr_display_results_panel($_SESSION['grr_last_run']);
}

//--------------------------------------------------------------------------------
// Confirm & Post
//--------------------------------------------------------------------------------

if (isset($_POST['ConfirmPost']))
{
	list($period_from, $period_to) = grr_period_bounds(get_post('gen_month'), get_post('gen_year'));

	$selected = array();
	if (isset($_POST['sel']) && is_array($_POST['sel']))
		$selected = array_map('intval', array_keys($_POST['sel']));

	$run_log = new GRR_RunLog();
	$run_log->run_type = 'GENERATE';

	if (count($selected) == 0)
	{
		display_error(_('Select at least one template to generate.'));
	}
	else
	{
		// Phase 2 — validate each (must still be due in selected period — DEF-006)
		$eligible = array();
		$skipped_items = array();
		foreach ($selected as $def_id)
		{
			$def = get_recurring_def($def_id);
			if (!$def)
			{
				$ir = new GRR_ItemResult();
				$ir->source_id = $def_id;
				$ir->status = 'skip';
				$ir->rule_id = 'VR-5';
				$ir->reason = _('Template not found');
				$skipped_items[] = $ir;
				continue;
			}
			$ir = grr_validate_generate_item($def);
			if ($ir->status !== 'ok')
			{
				$skipped_items[] = $ir;
				continue;
			}

			$post_date = sql2date($def['next_due_date']);
			if (!grr_date_in_period($post_date, $period_from, $period_to))
			{
				$ir->status = 'skip';
				$ir->rule_id = 'VR-7';
				$ir->source_date = $post_date;
				$ir->reason = _('Template next-due is not in the selected period (already generated or out of range).');
				$skipped_items[] = $ir;
				continue;
			}
			if (!is_date_in_fiscalyear($post_date))
			{
				$ir->status = 'skip';
				$ir->rule_id = 'VR-2';
				$ir->source_date = $post_date;
				$ir->reason = _('The posting date is not in an open fiscal year.');
				$skipped_items[] = $ir;
				continue;
			}
			$eligible[] = $def;
		}

		$run_log->selected = count($selected);
		$run_log->skipped = count($skipped_items);

		foreach ($skipped_items as $ir)
		{
			$run_log->rows[] = array(
				'orig_je' => 'T#'.$ir->source_id,
				'date' => $ir->source_date,
				'amount' => $ir->amount,
				'new_je' => '',
				'status' => 'Skipped',
				'reason' => $ir->reason,
				'rule_id' => $ir->rule_id,
				'source_def_id' => $ir->source_id,
			);
		}

		// Always persist audit for the run, including all-skipped (DEF-003)
		$user = grr_current_user_login();
		$ok = true;
		begin_transaction();
		$run_id = grr_run_begin('GENERATE', $user,
			sprintf('%s — %s', $period_from, $period_to));
		$run_log->run_id = $run_id;
		$posted = 0;

		foreach ($skipped_items as $ir)
		{
			grr_run_add_item($run_id, array(
				'source_type' => null,
				'source_trans_no' => null,
				'source_def_id' => $ir->source_id,
				'source_date' => $ir->source_date,
				'amount' => $ir->amount,
				'new_trans_no' => null,
				'status' => 'Skipped',
				'reason' => $ir->reason,
				'rule_id' => $ir->rule_id,
			));
		}

		foreach ($eligible as $def)
		{
			$post_date = sql2date($def['next_due_date']);
			$cart = grr_build_cart_from_def($def['id'], $post_date);
			if (!$cart || !grr_journal_balances($cart))
			{
				$ok = false;
				break;
			}
			$new_no = grr_post_journal_cart($cart);
			if ($new_no === false)
			{
				$ok = false;
				break;
			}
			advance_recurring_next_due($def['id']);
			$posted++;
			$row = array(
				'orig_je' => 'T#'.$def['id'],
				'date' => $post_date,
				'amount' => $cart->gl_items_total_debit(),
				'new_je' => $new_no,
				'status' => 'Posted',
				'reason' => '',
				'rule_id' => '',
				'source_def_id' => $def['id'],
			);
			$run_log->rows[] = $row;
			grr_run_add_item($run_id, array(
				'source_type' => null,
				'source_trans_no' => null,
				'source_def_id' => $def['id'],
				'source_date' => $post_date,
				'amount' => $cart->gl_items_total_debit(),
				'new_trans_no' => $new_no,
				'status' => 'Posted',
				'reason' => '',
				'rule_id' => '',
			));
		}

		if ($ok)
		{
			$run_log->posted = $posted;
			grr_run_finish($run_id, $run_log->selected, $run_log->posted, $run_log->skipped);
			commit_transaction();
			grr_session_store_log($run_log);
			if ($posted == 0)
				display_notification(_('No eligible templates to post.'));
			// PRG — prevent refresh re-post (DEF-006)
			meta_forward($_SERVER['PHP_SELF'], 'Generated=1');
		}
		else
		{
			cancel_transaction();
			display_error(_('Unexpected error; no journals were posted.'));
		}
	}
}

//--------------------------------------------------------------------------------
// Filters + Preview
//--------------------------------------------------------------------------------

start_form();

start_table(TABLESTYLE_NOBORDER);
start_row();
echo "<td>"._('Period Month').":</td><td>";
$months = array();
for ($m = 1; $m <= 12; $m++)
	$months[$m] = date('F', mktime(0, 0, 0, $m, 1));
echo array_selector('gen_month', get_post('gen_month'), $months);
echo "</td><td>"._('Year').":</td><td>";
$years = array();
$cy = (int)date('Y');
for ($y = $cy - 2; $y <= $cy + 2; $y++)
	$years[$y] = $y;
echo array_selector('gen_year', get_post('gen_year'), $years);
echo "</td>";
submit_cells('Preview', _('Preview'), '', _('List due templates'), 'default');
end_row();
end_table(1);

if (isset($_POST['Preview']))
{
	list($period_from, $period_to) = grr_period_bounds(get_post('gen_month'), get_post('gen_year'));
	display_note(sprintf(_('Due templates with next-due between %s and %s.'), $period_from, $period_to));

	$result = get_due_recurring_defs($period_from, $period_to);
	$defs = array();
	while ($row = db_fetch($result))
		$defs[] = $row;

	if (!count($defs))
		display_notification(_('No active templates are due in this period.'));
	else
	{
		start_table(TABLESTYLE, "width='85%'");
		$th = array('', _('ID'), _('Name'), _('Next Due'), _('Amount'), _('Memo'), _('Currency'), _('Rate'));
		table_header($th);
		$k = 0;
		foreach ($defs as $row)
		{
			alt_table_row_color($k);
			check_cells(null, 'sel['.$row['id'].']',
				!isset($_POST['sel']) || isset($_POST['sel'][$row['id']]));
			label_cell($row['id']);
			label_cell($row['name']);
			label_cell(sql2date($row['next_due_date']));
			amount_cell(grr_template_preview_amount($row['id']));
			label_cell($row['memo_']);
			label_cell($row['currency']);
			label_cell($row['rate']);
			end_row();
		}
		end_table(1);

		submit_center('ConfirmPost', _('Confirm & Post Selected'), true, _('Post journals and advance next-due'), 'default');
	}
}

end_form();

// DEF-015: Preview/Confirm are Ajax default submits; refresh body or list never appears
if (isset($_POST['Preview']) || isset($_POST['ConfirmPost']))
	$Ajax->activate('_page_body');

end_page();
