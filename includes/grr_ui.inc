<?php
/**********************************************************************
 * Shared includes for gl_recurring_reversal screens
 ***********************************************************************/

include_once($path_to_root.'/includes/ui.inc');
// DEF-014: remap extension SA_* codes (Access Setup IDs) before page() ACL check
if (function_exists('add_access_extensions'))
	add_access_extensions();
include_once($path_to_root.'/includes/date_functions.inc');
include_once($path_to_root.'/includes/data_checks.inc');
include_once($path_to_root.'/includes/banking.inc');
include_once($path_to_root.'/gl/includes/gl_db.inc');
include_once($path_to_root.'/admin/db/voiding_db.inc');

include_once(dirname(__FILE__).'/gl_recurring_db.inc');
include_once(dirname(__FILE__).'/gl_reversal_db.inc');
include_once(dirname(__FILE__).'/journal_posting.inc');
include_once(dirname(__FILE__).'/validation.inc');
include_once(dirname(__FILE__).'/results_log.inc');

function grr_current_user_login()
{
	return $_SESSION['wa_current_user']->loginname;
}

/**
 * Must run before page() so CSV headers are not blocked by HTML output.
 * Export button uses aspect 'download' (non-Ajax); see DEF-018.
 */
function grr_handle_csv_export()
{
	if (!isset($_POST['ExportCSV']))
		return;
	if (!empty($_SESSION['grr_last_run']))
		grr_export_csv($_SESSION['grr_last_run']);
	display_error(_('No run results available to export. Complete Generate or Bulk Reverse again, then retry Export.'));
}
