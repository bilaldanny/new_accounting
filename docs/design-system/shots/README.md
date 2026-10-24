# Screenshots (optional part of the audit)

These are copies of Dusk screenshots taken earlier in this project by `tests/Browser/MarketingScreenshotsTest.php` (a run-on-request test): Dusk + inline sqlite environment (`APP_URL=http://127.0.0.1:8099`, `database/dusk.sqlite`), signed in as the company admin of the **DEMO-HISTORY** company (twelve months of fictional trade). No MySQL and no live company was touched. Light theme, 1582 x 830 viewport.

| File | Page type | Page |
|---|---|---|
| `list.png` | List | Customers |
| `form.png` | Form | Add product |
| `voucher-entry.png` | Voucher / document entry | Add sale |
| `ledger-account.png` | Ledger | Account ledger |
| `statement-profit-loss.png` | Statement | Profit & Loss |
| `statement-balance-sheet.png` | Statement | Balance Sheet |
| `statement-trial-balance.png` | Statement | Trial Balance |
| `report.png` | Report | Sell report |
| `dashboard.png` | Dashboard | Dashboard |
| `pos.png` | POS | Point of sale (empty cart) |

**Not captured** (would need a new Dusk run, which the brief of this audit did not allow because it writes outside `docs/design-system/`): Detail / print document, Settings, Approval inbox, Workspace (HR, manufacturing), Wizard, Party ledger, Matrix (aging), error pages (they do not exist), the public website (screenshots of it are in `public/assets/landing/`).
