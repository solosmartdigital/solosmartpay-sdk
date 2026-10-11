#!/usr/bin/env bash
# Builds dist/solosmartpay-woocommerce.zip (no tests, ready to upload to WordPress).
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/solosmartpay-woocommerce.zip
cd ..
zip -qr "solosmartpay-woocommerce/dist/solosmartpay-woocommerce.zip" solosmartpay-woocommerce -x "solosmartpay-woocommerce/tests/*" -x "solosmartpay-woocommerce/dist/*" -x "solosmartpay-woocommerce/bin/*" -x "*/.DS_Store"
echo "dist/solosmartpay-woocommerce.zip"
