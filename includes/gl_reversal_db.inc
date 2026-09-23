<?php
/**********************************************************************
 * Reversal link + eligibility queries — gl_recurring_reversal
 ***********************************************************************/

function is_journal_reversed($type, $trans_no)
{
	$row = get_reversal_link_for_original($type, $trans_no);
	return $row !== false && $row !== null;
}

function get_reversal_link_for_original($type, $trans_no)
{
	$sql = "SELECT * FROM ".TB_PREF."gl_reversal_link
		WHERE original_type=".db_escape($type)
		." AND original_trans_no=".db_escape($trans_no);
	$result = db_query($sql, "cannot get reversal link");
	$row = db_fetch($result);
	return $row ? $row : false;
}

/**
 * Insert reversal link. Returns insert id, or false on UNIQUE conflict / DB error
 * (caller must cancel outer transaction — DEF-011 soft-handle).
 */
function add_reversal_link($orig_type, $orig_no, $rev_type, $rev_no, $rev_date, $user_id)
{
	$sql = "INSERT INTO ".TB_PREF."gl_reversal_link
		(original_type, original_trans_no, reversal_type, reversal_trans_no, reversal_date, created_by, created_at)
		VALUES ("
		.db_escape($orig_type).","
		.db_escape($orig_no).","
		.db_escape($rev_type).","
		.db_escape($rev_no).","
		."'".date2sql($rev_date)."',"
		.db_escape($user_id).","
		."NOW())";
	// No err_msg — avoid FA hard exit so batch can cancel_transaction cleanly
	$result = db_query($sql);
	if ($result === false)
		return false;
	return db_insert_id();
}

function grr_is_duplicate_reversal_error()
{
	return db_error_no() == DB_DUPLICATE_ERROR;
}

/**
 * Eligible ST_JOURNAL rows for bulk reverse.
 * $filters: from_date, to_date, account, memo, amount_min, amount_max, user_id
 */
function list_eligible_journals($filters)
{
	$from = isset($filters['from_date']) ? $filters['from_date'] : '';
	$to = isset($filters['to_date']) ? $filters['to_date'] : '';
	$account = isset($filters['account']) ? $filters['account'] : '';
	$memo = isset($filters['memo']) ? $filters['memo'] : '';
	$amount_min = isset($filters['amount_min']) ? $filters['amount_min'] : '';
	$amount_max = isset($filters['amount_max']) ? $filters['amount_max'] : '';
	$user_id = isset($filters['user_id']) ? $filters['user_id'] : '';

	$sql = "SELECT j.type, j.trans_no, j.tran_date, j.amount, j.currency, j.rate, j.reference,
			IFNULL(c.memo_, '') AS memo_,
			IFNULL(u.user_id, '') AS user_login
		FROM ".TB_PREF."journal j
		LEFT JOIN ".TB_PREF."comments c
			ON c.type=j.type AND c.id=j.trans_no
		LEFT JOIN ".TB_PREF."voided v
			ON v.type=j.type AND v.id=j.trans_no
		LEFT JOIN ".TB_PREF."gl_reversal_link rl
			ON rl.original_type=j.type AND rl.original_trans_no=j.trans_no
		LEFT JOIN (
			SELECT type, trans_no, MIN(user) AS user
			FROM ".TB_PREF."audit_trail
			WHERE gl_seq IS NOT NULL
			GROUP BY type, trans_no
		) at ON at.type=j.type AND at.trans_no=j.trans_no
		LEFT JOIN ".TB_PREF."users u ON u.id=at.user
		WHERE j.type=".ST_JOURNAL."
		AND v.id IS NULL
		AND rl.id IS NULL
		AND j.amount <> 0";

	if ($from !== '' && is_date($from))
		$sql .= " AND j.tran_date >= '".date2sql($from)."'";
	if ($to !== '' && is_date($to))
		$sql .= " AND j.tran_date <= '".date2sql($to)."'";

	if ($account !== '' && $account !== null)
	{
		$sql .= " AND EXISTS (
			SELECT 1 FROM ".TB_PREF."gl_trans g
			WHERE g.type=j.type AND g.type_no=j.trans_no
			AND g.account=".db_escape($account)." AND g.amount <> 0)";
	}

	if ($memo !== '')
	{
		$like = db_escape('%'.$memo.'%');
		$sql .= " AND (IFNULL(c.memo_,'') LIKE $like OR j.reference LIKE $like OR IFNULL(j.source_ref,'') LIKE $like)";
	}

	if ($amount_min !== '' && $amount_min !== null && is_numeric($amount_min))
		$sql .= " AND ABS(j.amount) >= ".db_escape($amount_min);
	if ($amount_max !== '' && $amount_max !== null && is_numeric($amount_max))
		$sql .= " AND ABS(j.amount) <= ".db_escape($amount_max);

	if ($user_id !== '' && $user_id !== null)
		$sql .= " AND u.user_id=".db_escape($user_id);

	$sql .= " ORDER BY j.tran_date, j.trans_no";

	$result = db_query($sql, "cannot list eligible journals");
	$rows = array();
	while ($row = db_fetch($result))
		$rows[] = $row;
	return $rows;
}
