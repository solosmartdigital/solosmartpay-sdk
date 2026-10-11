# End-to-end tests

Runs the real gateway inside WordPress + WooCommerce against a mock Solosmartpay API.

1. Set up a scratch WordPress (SQLite is enough: WordPress + the `sqlite-database-integration` plugin + WooCommerce + WP-CLI), with the plugin symlinked into `wp-content/plugins/`, `woocommerce_currency` set to `IDR`, and the site served on `http://127.0.0.1:8890`.
2. Start the mock API: `php -S 127.0.0.1:8891 mock-api.php`
3. From the WordPress directory: `wp eval-file /path/to/e2e.php`

Output is one `ok` / `FAIL` line per check and `ALL OK` at the end. The suite covers checkout, invoice reuse, signature / replay / duplicate handling, amount mismatch, expiry, cancel sync, changed totals, failure notices, and the debug log never containing credentials. Run it once with the default order storage and once with HPOS enabled (`wp wc hpos enable --with-sync`).
