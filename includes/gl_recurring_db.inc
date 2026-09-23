<?php
/**********************************************************************
 * Recurring GL template CRUD — gl_recurring_reversal
 ***********************************************************************/

define('GL_RECUR_FREQ_MONTHLY', 1);

function get_recurring_def($id)
{
	$sql = "SELECT * FROM ".TB_PREF."gl_recurring_def WHERE id=".db_escape($id);
	$result = db_query($sql, "cannot get recurring def");
	return db_fetch($result);
}

function get_all_recurring_defs($show_inactive = false)
{
	$sql = "SELECT d.*,
			(SELECT COUNT(*) FROM ".TB_PREF."gl_recurring_def_line l WHERE l.def_id=d.id) AS line_count
		FROM ".TB_PREF."gl_recurring_def d";
	if (!$show_inactive)
		$sql .= " WHERE d.inactive=0";
	$sql .= " ORDER BY d.name";
	return db_query($sql, "cannot list recurring defs");
}

function add_recurring_def($name, $frequency, $next_due, $memo, $currency, $rate, $inactive = 0)
{
	$user = $_SESSION['wa_current_user']->loginname;
	$sql = "INSERT INTO ".TB_PREF."gl_recurring_def
		(name, frequency, next_due_date, memo_, currency, rate, inactive, created_by, created_at, updated_at)
		VALUES ("
		.db_escape($name).","
		.db_escape($frequency).","
		."'".date2sql($next_due)."',"
		.db_escape($memo).","
		.db_escape($currency).","
		.db_escape($rate).","
		.db_escape($inactive).","
		.db_escape($user).","
		."NOW(), NOW())";
	db_query($sql, "cannot add recurring def");
	return db_insert_id();
}

function update_recurring_def($id, $name, $frequency, $next_due, $memo, $currency, $rate, $inactive)
{
	$sql = "UPDATE ".TB_PREF."gl_recurring_def SET "
		."name=".db_escape($name).","
		."frequency=".db_escape($frequency).","
		."next_due_date='".date2sql($next_due)."',"
		."memo_=".db_escape($memo).","
		."currency=".db_escape($currency).","
		."rate=".db_escape($rate).","
		."inactive=".db_escape($inactive).","
		."updated_at=NOW()
		WHERE id=".db_escape($id);
	db_query($sql, "cannot update recurring def");
}

function set_recurring_def_inactive($id, $inactive)
{
	$sql = "UPDATE ".TB_PREF."gl_recurring_def SET inactive=".db_escape($inactive)
		.", updated_at=NOW() WHERE id=".db_escape($id);
	db_query($sql, "cannot set recurring def inactive");
}

function delete_recurring_def_lines($def_id)
{
	$sql = "DELETE FROM ".TB_PREF."gl_recurring_def_line WHERE def_id=".db_escape($def_id);
	db_query($sql, "cannot delete recurring def lines");
}

function add_recurring_def_line($def_id, $account, $dimension_id, $dimension2_id, $memo_, $amount,
	$person_type_id = null, $person_id = null, $line_no = 0)
{
	$sql = "INSERT INTO ".TB_PREF."gl_recurring_def_line
		(def_id, account, dimension_id, dimension2_id, memo_, amount, person_type_id, person_id, line_no)
		VALUES ("
		.db_escape($def_id).","
		.db_escape($account).","
		.db_escape($dimension_id).","
		.db_escape($dimension2_id).","
		.db_escape($memo_).","
		.db_escape($amount).","
		.($person_type_id === null || $person_type_id === '' ? "NULL" : db_escape($person_type_id)).","
		.($person_id === null || $person_id === '' ? "NULL" : db_escape($person_id)).","
		.db_escape($line_no).")";
	db_query($sql, "cannot add recurring def line");
}

function get_recurring_def_lines($def_id)
{
	$sql = "SELECT * FROM ".TB_PREF."gl_recurring_def_line
		WHERE def_id=".db_escape($def_id)." ORDER BY line_no, id";
	return db_query($sql, "cannot get recurring def lines");
}

/**
 * Active templates whose next_due_date falls in [period_from, period_to] inclusive.
 */
function get_due_recurring_defs($period_from, $period_to)
{
	$sql = "SELECT * FROM ".TB_PREF."gl_recurring_def
		WHERE inactive=0
		AND next_due_date >= '".date2sql($period_from)."'
		AND next_due_date <= '".date2sql($period_to)."'
		ORDER BY next_due_date, name";
	return db_query($sql, "cannot get due recurring defs");
}

function advance_recurring_next_due($id)
{
	$def = get_recurring_def($id);
	if (!$def)
		return false;
	$next = add_months(sql2date($def['next_due_date']), 1);
	$sql = "UPDATE ".TB_PREF."gl_recurring_def SET next_due_date='".date2sql($next)
		."', updated_at=NOW() WHERE id=".db_escape($id);
	db_query($sql, "cannot advance next_due");
	return $next;
}
