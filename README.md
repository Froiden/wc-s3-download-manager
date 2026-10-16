# WooCommerce S3 Download Manager

Production WordPress plugin that stores WooCommerce downloadable products on private Amazon S3 and delivers them with short-lived signed URLs after WooCommerce authorization.

## Quick start

1. Install `wc-s3-download-manager.zip` via **Plugins → Upload Plugin**, or copy the `wc-s3-download-manager/` folder and run `composer install --no-dev`.
2. Activate the plugin (WooCommerce required).
3. Configure **WooCommerce → S3 Download Manager**.
4. Run **Test Connection**.

## Documentation

| Doc | Path |
|-----|------|
| Architecture & threat model | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Installation | [docs/INSTALLATION.md](docs/INSTALLATION.md) |
| IAM policy | [docs/IAM_POLICY.json](docs/IAM_POLICY.json) |
| Bucket setup | [docs/BUCKET_SETUP.md](docs/BUCKET_SETUP.md) |
| Migration & rollback | [docs/MIGRATION.md](docs/MIGRATION.md) |
| Troubleshooting | [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) |
| WP-CLI | [docs/WP-CLI.md](docs/WP-CLI.md) |
| Staging tests | [wc-s3-download-manager/tests/STAGING.md](wc-s3-download-manager/tests/STAGING.md) |

## Development

```bash
cd wc-s3-download-manager
composer install
./vendor/bin/phpunit
```

## Compatibility

* PHP 8.2+
* WordPress 6.4+
* WooCommerce 8.0+
* AWS SDK for PHP v3 (bundled via Composer)

## License

GPL-2.0-or-later
