# gl_recurring_reversal

Altpillar / FrontAccounting 2.4.1 extension for **Recurring GL Journals**, **Copy Journal**, and **Bulk Journal Reversal**.

**Install folder (required):** `modules/gl_recurring_reversal/`  
**Package name:** `gl_recurring_reversal` (hooks class `hooks_gl_recurring_reversal`)

## Deploy

1. Copy this folder to `{FA_ROOT}/modules/gl_recurring_reversal/` on the server.
2. Setup → Install/Activate Extensions → add/activate package `gl_recurring_reversal`  
   (or register in `installed_extensions.php` with `path` => `modules/gl_recurring_reversal`).
3. Activate per company (runs `sql/install.sql`).
4. Setup → Access Setup: map roles to:
   - `SA_GLRECURRINGDEF` — Recurring GL template maintenance  
   - `SA_GLRECURRINGPOST` — Generate recurring journals  
   - `SA_GLBULKREVERSE` — Bulk journal reversal  
   - Copy Journal uses existing `SA_JOURNALENTRY`
5. Log out/in so menus refresh.

## Menu (Banking and General Ledger)

| Section | Entry |
|---------|--------|
| Transactions | Generate Recurring Journals |
| Transactions | Bulk Journal Reversal |
| Transactions | Copy Journal |
| Maintenance | Recurring GL Entries |

## Hard rules (v1)

- Reverse creates opposite `ST_JOURNAL` via `items_cart` + `write_journal_entries` — **never** void.
- Dimensions copied unchanged; amounts negated only.
- Monthly frequency only; generate is user-triggered (no cron).
- Deactivate drops module tables (`remove.sql`) — backup first.
