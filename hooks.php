<?php
/**
 * gl_recurring_reversal — extension hooks
 * Menu under Banking and General Ledger; security SA_GLRECURRING*
 */
define('SS_GLRECURRING', 253 << 8);

class hooks_gl_recurring_reversal extends hooks
{
	var $module_name = 'gl_recurring_reversal';

	function install_options($app)
	{
		global $path_to_root;

		switch ($app->id) {
			case 'GL':
				$app->add_rapp_function(0, _('Generate Recurring Journals'),
					$path_to_root.'/modules/gl_recurring_reversal/transactions/generate_recurring.php',
					'SA_GLRECURRINGPOST', MENU_TRANSACTION);
				$app->add_rapp_function(0, _('Bulk Journal Reversal'),
					$path_to_root.'/modules/gl_recurring_reversal/transactions/bulk_reversal.php',
					'SA_GLBULKREVERSE', MENU_TRANSACTION);
				$app->add_rapp_function(0, _('Copy Journal'),
					$path_to_root.'/modules/gl_recurring_reversal/transactions/copy_journal.php',
					'SA_JOURNALENTRY', MENU_TRANSACTION);
				$app->add_lapp_function(2, _('Recurring GL Entries'),
					$path_to_root.'/modules/gl_recurring_reversal/manage/recurring_def.php',
					'SA_GLRECURRINGDEF', MENU_MAINTENANCE);
				break;
		}
	}

	function install_access()
	{
		$security_sections[SS_GLRECURRING] = _('GL Recurring & Reversal');

		$security_areas['SA_GLRECURRINGDEF'] = array(SS_GLRECURRING|1, _('Recurring GL template maintenance'));
		$security_areas['SA_GLRECURRINGPOST'] = array(SS_GLRECURRING|2, _('Generate recurring journals'));
		$security_areas['SA_GLBULKREVERSE'] = array(SS_GLRECURRING|3, _('Bulk journal reversal'));

		return array($security_areas, $security_sections);
	}

	function activate_extension($company, $check_only = true)
	{
		global $db_connections, $path_to_root;

		// FA update_databases skips install.sql when the first check table exists.
		// Require all five module tables; re-import if any are missing (DEF-005).
		$required = array(
			'gl_recurring_def',
			'gl_recurring_def_line',
			'gl_reversal_link',
			'gl_rr_run',
			'gl_rr_run_item',
		);

		if ($company == -1)
			$conn = $db_connections;
		else
			$conn = array($company => $db_connections[$company]);

		$result = true;
		foreach ($conn as $comp => $con) {
			set_global_connection($comp);
			$missing = false;
			foreach ($required as $table) {
				if (check_table($con['tbpref'], $table) != 0) {
					$missing = true;
					break;
				}
			}
			if ($missing) {
				if ($check_only)
					$result = false;
				else {
					$ok = db_import($path_to_root.'/modules/'.$this->module_name.'/sql/install.sql', $con);
					$result &= $ok;
				}
			}
			db_close();
			if (!$result)
				break;
		}
		set_global_connection(0);
		return $result;
	}

	function deactivate_extension($company, $check_only = true)
	{
		global $db_connections, $path_to_root;

		// FA update_databases() only imports when the check table is missing,
		// so remove.sql would never run. Force DROP IF EXISTS on real deactivate.
		if ($check_only)
			return true;

		if ($company == -1)
			$conn = $db_connections;
		else
			$conn = array($company => $db_connections[$company]);

		$result = true;
		foreach ($conn as $comp => $con) {
			set_global_connection($comp);
			$ok = db_import($path_to_root.'/modules/'.$this->module_name.'/sql/remove.sql', $con);
			$result &= $ok;
			db_close();
			if (!$result)
				break;
		}
		set_global_connection(0);
		return $result;
	}
}
