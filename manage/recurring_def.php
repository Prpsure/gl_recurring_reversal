<?php
/**********************************************************************
 * Recurring GL Entries — template CRUD (Module A)
 ***********************************************************************/
$page_security = 'SA_GLRECURRINGDEF';
$path_to_root = '../../..';

include_once($path_to_root.'/includes/session.inc');
// DEF-014: remap extension SA_* before page() (FrontHrm pattern)
add_access_extensions();
include_once($path_to_root.'/modules/gl_recurring_reversal/includes/grr_ui.inc');

$js = '';
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(900, 600);
if (user_use_date_picker())
	$js .= get_js_date_picker();

page(_($help_context = 'Recurring GL Entries'), false, false, '', $js);

//--------------------------------------------------------------------------------
// Helpers
//--------------------------------------------------------------------------------

function grr_def_clear_line_posts()
{
	$max = isset($_POST['line_count']) ? (int)$_POST['line_count'] : 0;
	for ($i = 0; $i < $max; $i++)
	{
		unset($_POST['line_account'.$i], $_POST['line_memo'.$i],
			$_POST['line_debit'.$i], $_POST['line_credit'.$i],
			$_POST['line_dim1'.$i], $_POST['line_dim2'.$i]);
	}
	unset($_POST['line_count']);
}

function grr_def_collect_lines_from_post()
{
	$lines = array();
	$count = isset($_POST['line_count']) ? (int)$_POST['line_count'] : 0;
	for ($i = 0; $i < $count; $i++)
	{
		$account = get_post('line_account'.$i);
		if ($account === '' || $account === null)
			continue;
		$debit = input_num('line_debit'.$i);
		$credit = input_num('line_credit'.$i);
		$amount = $debit - $credit;
		if (floatcmp($amount, 0) == 0)
			continue;
		$lines[] = array(
			'account' => $account,
			'dimension_id' => get_post('line_dim1'.$i, 0),
			'dimension2_id' => get_post('line_dim2'.$i, 0),
			'memo_' => get_post('line_memo'.$i, ''),
			'amount' => $amount,
		);
	}
	return $lines;
}

function grr_def_load_to_post($id)
{
	$def = get_recurring_def($id);
	if (!$def)
		return false;
	$_POST['def_id'] = $id;
	$_POST['name'] = $def['name'];
	$_POST['next_due_date'] = sql2date($def['next_due_date']);
	$_POST['memo_'] = $def['memo_'];
	$_POST['currency'] = $def['currency'];
	$_POST['rate'] = $def['rate'];
	$_POST['inactive'] = $def['inactive'];

	$result = get_recurring_def_lines($id);
	$i = 0;
	while ($row = db_fetch($result))
	{
		$_POST['line_account'.$i] = $row['account'];
		$_POST['line_memo'.$i] = $row['memo_'];
		$_POST['line_dim1'.$i] = $row['dimension_id'];
		$_POST['line_dim2'.$i] = $row['dimension2_id'];
		if ($row['amount'] >= 0)
		{
			$_POST['line_debit'.$i] = price_format($row['amount']);
			$_POST['line_credit'.$i] = price_format(0);
		}
		else
		{
			$_POST['line_debit'.$i] = price_format(0);
			$_POST['line_credit'.$i] = price_format(-$row['amount']);
		}
		$i++;
	}
	// ensure at least 2 empty slots for editing
	while ($i < 2)
	{
		$_POST['line_account'.$i] = '';
		$_POST['line_memo'.$i] = '';
		$_POST['line_debit'.$i] = '';
		$_POST['line_credit'.$i] = '';
		$_POST['line_dim1'.$i] = 0;
		$_POST['line_dim2'.$i] = 0;
		$i++;
	}
	$_POST['line_count'] = $i;
	return true;
}

//--------------------------------------------------------------------------------
// Actions
//--------------------------------------------------------------------------------

simple_page_mode(true);

if ($Mode == 'RESET' || isset($_POST['Cancel']))
{
	$selected_id = -1;
	$Mode = 'RESET';
	grr_def_clear_line_posts();
	unset($_POST['def_id'], $_POST['name'], $_POST['memo_'], $_POST['inactive']);
}

if ($Mode == 'Edit')
	grr_def_load_to_post($selected_id);

// Clone is not handled by simple_page_mode (Edit/Delete only) — DEF-010
foreach ($_POST as $p => $pvar)
{
	if (strpos($p, 'Clone') === 0)
	{
		$cid = quoted_printable_decode(substr($p, strlen('Clone')));
		if (grr_def_load_to_post($cid))
		{
			$selected_id = -1;
			$_POST['def_id'] = '';
			$_POST['name'] = $_POST['name'].' '._('(copy)');
			$Mode = 'Clone';
		}
		break;
	}
}

if (isset($_POST['AddLine']))
{
	$count = isset($_POST['line_count']) ? (int)$_POST['line_count'] : 0;
	$_POST['line_account'.$count] = '';
	$_POST['line_memo'.$count] = '';
	$_POST['line_debit'.$count] = '';
	$_POST['line_credit'.$count] = '';
	$_POST['line_dim1'.$count] = 0;
	$_POST['line_dim2'.$count] = 0;
	$_POST['line_count'] = $count + 1;
	$Mode = ($selected_id != -1) ? 'Edit' : 'ADD_ITEM';
}

if ($Mode == 'Delete' && $selected_id != -1)
{
	set_recurring_def_inactive($selected_id, 1);
	display_notification(_('Template deactivated.'));
	$Mode = 'RESET';
	$selected_id = -1;
}

foreach ($_POST as $p => $pvar)
{
	if (strpos($p, 'Activate') === 0)
	{
		$aid = substr($p, strlen('Activate'));
		set_recurring_def_inactive($aid, 0);
		display_notification(_('Template activated.'));
		$selected_id = -1;
		$Mode = 'RESET';
		break;
	}
}

if (isset($_POST['ADD_ITEM']) || isset($_POST['UPDATE_ITEM']))
{
	$input_error = 0;
	if (get_post('name') === '')
	{
		display_error(_('Template name cannot be empty.'));
		set_focus('name');
		$input_error = 1;
	}
	elseif (!is_date(get_post('next_due_date')))
	{
		display_error(_('Invalid next-due date.'));
		set_focus('next_due_date');
		$input_error = 1;
	}

	$lines = grr_def_collect_lines_from_post();
	if ($input_error == 0 && count($lines) < 2)
	{
		display_error(_('Enter at least two balanced journal lines.'));
		$input_error = 1;
	}
	if ($input_error == 0 && !grr_journal_balances($lines))
	{
		display_error(_('Journal does not balance'));
		$input_error = 1;
	}

	if ($input_error == 0)
	{
		$currency = get_post('currency', get_company_currency());
		$rate = input_num('rate', 1);
		if ($rate <= 0)
			$rate = 1;
		$inactive = check_value('inactive') ? 1 : 0;
		$freq = GL_RECUR_FREQ_MONTHLY;

		begin_transaction();
		if (isset($_POST['ADD_ITEM']) || $selected_id == -1)
		{
			$id = add_recurring_def(
				get_post('name'), $freq, get_post('next_due_date'),
				get_post('memo_', ''), $currency, $rate, $inactive
			);
			display_notification(_('New recurring template has been added.'));
		}
		else
		{
			$id = $selected_id;
			update_recurring_def(
				$id, get_post('name'), $freq, get_post('next_due_date'),
				get_post('memo_', ''), $currency, $rate, $inactive
			);
			delete_recurring_def_lines($id);
			display_notification(_('Recurring template has been updated.'));
		}
		$n = 0;
		foreach ($lines as $line)
		{
			add_recurring_def_line($id, $line['account'], $line['dimension_id'],
				$line['dimension2_id'], $line['memo_'], $line['amount'], null, null, $n++);
		}
		commit_transaction();

		$Mode = 'RESET';
		$selected_id = -1;
		grr_def_clear_line_posts();
	}
}

//--------------------------------------------------------------------------------
// List
//--------------------------------------------------------------------------------

start_form();

start_table(TABLESTYLE_NOBORDER);
start_row();
check_cells(_('Show inactive:'), 'show_inactive', null, true);
end_row();
end_table();

$result = get_all_recurring_defs(check_value('show_inactive'));

start_table(TABLESTYLE, "width='80%'");
$th = array(_('Name'), _('Lines'), _('Frequency'), _('Next Due'), _('Currency'), _('Active'), '', '', '');
table_header($th);
$k = 0;
while ($myrow = db_fetch($result))
{
	alt_table_row_color($k);
	label_cell($myrow['name']);
	label_cell($myrow['line_count']);
	label_cell(_('Monthly'));
	label_cell(sql2date($myrow['next_due_date']));
	label_cell($myrow['currency']);
	label_cell($myrow['inactive'] ? _('No') : _('Yes'));
	edit_button_cell('Edit'.$myrow['id'], _('Edit'));
	button_cell('Clone'.$myrow['id'], _('Clone'), _('Clone this template'), ICON_ADD);
	if ($myrow['inactive'])
		button_cell('Activate'.$myrow['id'], _('Activate'), _('Activate'), ICON_ADD);
	else
		delete_button_cell('Delete'.$myrow['id'], _('Deactivate'));
	end_row();
}
end_table(1);

//--------------------------------------------------------------------------------
// Edit form
//--------------------------------------------------------------------------------

br();
display_heading(($selected_id == -1 || $Mode == 'Clone') ? _('New Recurring GL Template') : _('Edit Recurring GL Template'));

hidden('selected_id', $selected_id);
if ($selected_id != -1)
	hidden('def_id', $selected_id);

start_table(TABLESTYLE2);
text_row(_('Name').':', 'name', null, 40, 100);
label_row(_('Frequency').':', _('Monthly'));
date_row(_('Next Due').':', 'next_due_date', '', true);
textarea_row(_('Memo').':', 'memo_', null, 40, 3);
currencies_list_row(_('Currency').':', 'currency', null);
amount_row(_('Exchange Rate').':', 'rate', null, null, null, user_exrate_dec());
check_row(_('Inactive').':', 'inactive');
end_table(1);

$dim = get_company_pref('use_dimension');
$line_count = isset($_POST['line_count']) ? (int)$_POST['line_count'] : 2;
if ($line_count < 2)
	$line_count = 2;
hidden('line_count', $line_count);

start_table(TABLESTYLE, "width='90%'");
$th = array(_('Account'), _('Memo'));
if ($dim >= 1)
	$th[] = _('Dimension').' 1';
if ($dim > 1)
	$th[] = _('Dimension').' 2';
$th[] = _('Debit');
$th[] = _('Credit');
table_header($th);

for ($i = 0; $i < $line_count; $i++)
{
	start_row();
	gl_all_accounts_list_cells(null, 'line_account'.$i, null, false, false, _('Select account'));
	text_cells(null, 'line_memo'.$i, null, 20, 50);
	if ($dim >= 1)
		dimensions_list_cells(null, 'line_dim1'.$i, null, true, ' ', false, 1);
	if ($dim > 1)
		dimensions_list_cells(null, 'line_dim2'.$i, null, true, ' ', false, 2);
	amount_cells(null, 'line_debit'.$i);
	amount_cells(null, 'line_credit'.$i);
	end_row();
}
end_table(1);

submit_center_first('AddLine', _('Add Line'), _('Add another journal line'), true);
submit_add_or_update_center($selected_id == -1, '', 'both');
submit_center_last('Cancel', _('Cancel'), _('Cancel edits'), true);

end_form();
end_page();
