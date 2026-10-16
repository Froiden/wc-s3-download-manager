# Test results

## Automated unit tests

Run from the plugin directory:

```bash
cd wc-s3-download-manager
composer install
./vendor/bin/phpunit
```

**Latest local run (2026-10-16):** `OK (12 tests, 30 assertions)` on PHP 8.3.30.

Covered areas (mocked / isolated):

* Reference build/parse and key validation (path traversal blocked)
* Logger redaction of access keys and signed URL query params
* AWS error diagnostics mapping
* Path validator rejection of S3 refs and protected WP paths
* SHA-256 hashing of local files
* Connection tester auth failure short-circuit
* Connection tester full suite with mocked S3 + HTTP
* Connection tester upload failure → remaining steps Not Tested
* Deterministic migration object keys

## Integration / staging

Full WordPress + WooCommerce + real S3 coverage is documented in `wc-s3-download-manager/tests/STAGING.md` and must be executed in a staging environment (credentials not committed).

## Deactivation / reactivation

Activation creates tables and default options. Deactivation unschedules jobs and leaves data intact. Reactivation reuses existing schema/options.
