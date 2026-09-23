# gl_recurring_reversal

Altpillar / FrontAccounting 2.4.1 extension for **Recurring GL Journals**, **Copy Journal**, and **Bulk Journal Reversal**.

**Install folder (required):** `modules/gl_recurring_reversal/`  
**Package name:** `gl_recurring_reversal` (hooks class `hooks_gl_recurring_reversal`)

## Deploy

1. Copy/clone this folder to `{FA_ROOT}/modules/gl_recurring_reversal/` on the server  
   (folder name must be exactly `gl_recurring_reversal`).
2. **Install** (registers the package — required once):
   - Setup → Install/Activate Extensions
   - Extensions dropdown → **Available and/or installed** (not “Activated for …”)
   - Find **GL Recurring & Bulk Reversal** / `gl_recurring_reversal` → **Install**
   - If the row is missing, the folder is not on the server yet (step 1).
3. **Activate per company** (runs `sql/install.sql`):
   - Same screen → Extensions → **Activated for 'Your Company'**
   - Check **Active** for GL Recurring & Bulk Reversal → **Update**
4. Setup → Access Setup: map roles to:
   - `SA_GLRECURRINGDEF` — Recurring GL template maintenance  
   - `SA_GLRECURRINGPOST` — Generate recurring journals  
   - `SA_GLBULKREVERSE` — Bulk journal reversal  
   - Copy Journal uses existing `SA_JOURNALENTRY`
5. Log out/in so menus refresh.

Manual register alternative: add to root + company `installed_extensions.php` with  
`package`/`path` = `gl_recurring_reversal` / `modules/gl_recurring_reversal`, then Activate (step 3).

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
