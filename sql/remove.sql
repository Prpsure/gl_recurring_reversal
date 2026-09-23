-- ============================================================
-- gl_recurring_reversal — remove.sql
-- Called on extension deactivate. Drops module tables only.
-- Does NOT touch journal / gl_trans / voided.
-- ============================================================

DROP TABLE IF EXISTS `0_gl_rr_run_item`;
DROP TABLE IF EXISTS `0_gl_rr_run`;
DROP TABLE IF EXISTS `0_gl_reversal_link`;
DROP TABLE IF EXISTS `0_gl_recurring_def_line`;
DROP TABLE IF EXISTS `0_gl_recurring_def`;
