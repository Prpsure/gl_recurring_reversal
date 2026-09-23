<?php
/**********************************************************************
 * Run results, CSV export, audit persist — gl_recurring_reversal
 ***********************************************************************/

class GRR_RunLog
{
	public $run_type; // 'GENERATE'|'REVERSE'|'COPY'
	public $run_id = 0;
	public $selected = 0;
	public $posted = 0;
	public $skipped = 0;
	public $rows = array(); // orig_je, date, amount, new_je, status, reason, rule_id, source_def_id, source_type
}

function grr_run_begin($run_type, $user_id, $memo = null)
{
	$sql = "INSERT INTO ".TB_PREF."gl_rr_run
		(run_type, user_id, run_at, selected_count, posted_count, skipped_count, memo_)
		VALUES ("
		.db_escape($run_type).","
		.db_escape($user_id).","
		."NOW(), 0, 0, 0,"
		.db_escape($memo).")";
	db_query($sql, "cannot begin gl_rr_run");
	return db_insert_id();
}

/**
 * $row keys: source_type, source_trans_no, source_def_id, source_date, amount,
 *            new_trans_no, status ('Posted'|'Skipped'), reason, rule_id
 */
function grr_run_add_item($run_id, $row)
{
	$sql = "INSERT INTO ".TB_PREF."gl_rr_run_item
		(run_id, source_type, source_trans_no, source_def_id, source_date, amount,
		 new_trans_no, status, reason, rule_id)
		VALUES ("
		.db_escape($run_id).","
		.(isset($row['source_type']) && $row['source_type'] !== '' && $row['source_type'] !== null
			? db_escape($row['source_type']) : "NULL").","
		.(isset($row['source_trans_no']) && $row['source_trans_no'] !== '' && $row['source_trans_no'] !== null
			? db_escape($row['source_trans_no']) : "NULL").","
		.(isset($row['source_def_id']) && $row['source_def_id'] !== '' && $row['source_def_id'] !== null
			? db_escape($row['source_def_id']) : "NULL").","
		.(isset($row['source_date']) && $row['source_date'] !== '' && $row['source_date'] !== null
			? "'".date2sql($row['source_date'])."'" : "NULL").","
		.(isset($row['amount']) && $row['amount'] !== '' && $row['amount'] !== null
			? db_escape($row['amount']) : "NULL").","
		.(isset($row['new_trans_no']) && $row['new_trans_no'] !== '' && $row['new_trans_no'] !== null
			? db_escape($row['new_trans_no']) : "NULL").","
		.db_escape($row['status']).","
		.db_escape(isset($row['reason']) ? $row['reason'] : '').","
		.db_escape(isset($row['rule_id']) ? $row['rule_id'] : '').")";
	db_query($sql, "cannot add gl_rr_run_item");
}

function grr_run_finish($run_id, $selected, $posted, $skipped)
{
	$sql = "UPDATE ".TB_PREF."gl_rr_run SET "
		."selected_count=".db_escape($selected).","
		."posted_count=".db_escape($posted).","
		."skipped_count=".db_escape($skipped)
		." WHERE id=".db_escape($run_id);
	db_query($sql, "cannot finish gl_rr_run");
}

function grr_export_csv($run_log)
{
	$filename = 'gl_rr_'.$run_log->run_type.'_'.date('Ymd_His').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header('Pragma: no-cache');
	header('Expires: 0');

	$out = fopen('php://output', 'w');
	fputcsv($out, array('Original JE#', 'Date', 'Amount', 'New JE#', 'Status', 'Reason'));
	foreach ($run_log->rows as $r)
	{
		fputcsv($out, array(
			isset($r['orig_je']) ? $r['orig_je'] : '',
			isset($r['date']) ? $r['date'] : '',
			isset($r['amount']) ? $r['amount'] : '',
			isset($r['new_je']) ? $r['new_je'] : '',
			isset($r['status']) ? $r['status'] : '',
			isset($r['reason']) ? $r['reason'] : '',
		));
	}
	fclose($out);
	exit;
}

function grr_display_results_panel($run_log)
{
	display_notification(sprintf(
		_('Run complete: selected %d, posted %d, skipped %d.'),
		$run_log->selected, $run_log->posted, $run_log->skipped
	));

	start_table(TABLESTYLE, "width='90%'");
	$th = array(_('Original JE#'), _('Date'), _('Amount'), _('New JE#'), _('Status'), _('Reason'));
	table_header($th);
	$k = 0;
	foreach ($run_log->rows as $r)
	{
		$skipped = isset($r['status']) && $r['status'] === 'Skipped';
		if ($skipped)
			echo "<tr bgcolor='#fff3cd'>\n";
		else
			alt_table_row_color($k);
		label_cell(isset($r['orig_je']) ? $r['orig_je'] : '');
		label_cell(isset($r['date']) ? $r['date'] : '');
		amount_cell(isset($r['amount']) ? $r['amount'] : 0);
		if (!empty($r['new_je']))
			label_cell(get_gl_view_str(ST_JOURNAL, $r['new_je'], '#'.$r['new_je']));
		else
			label_cell('');
		label_cell(isset($r['status']) ? $r['status'] : '');
		label_cell(isset($r['reason']) ? $r['reason'] : '');
		end_row();
	}
	end_table(1);

	if (!empty($_SESSION['grr_last_run']))
	{
		br();
		start_form();
		// 'download' forces non-Ajax submit so Content-Disposition headers work (DEF-018)
		submit_center('ExportCSV', _('Export results to CSV'), true, _('Download CSV'), 'download');
		end_form();
	}
}

/**
 * Build in-memory run log and stash in session for CSV.
 */
function grr_session_store_log($run_log)
{
	$_SESSION['grr_last_run'] = $run_log;
}
