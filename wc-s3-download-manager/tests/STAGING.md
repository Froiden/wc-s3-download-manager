# Staging test procedure (real S3 bucket)

Use a **non-production** AWS account and a dedicated private bucket.

## Prerequisites

1. PHP 8.2+, WordPress 6.4+, WooCommerce 8.0+.
2. Plugin installed with `composer install --no-dev`.
3. IAM user/role with least-privilege policy from `docs/IAM_POLICY.json`.
4. Bucket with Block Public Access enabled.

## Checklist

1. **Activate** the plugin; confirm no fatal errors.
2. **S3 Settings** — enter region, bucket, access key (or IAM role). Save.
3. **Test Connection** — all nine checks should Pass (delete may Fail if policy omits `s3:DeleteObject`; leftover key is shown).
4. **Run Diagnostics** — PHP extensions, SDK, WooCommerce, Action Scheduler.
5. **Uploads** — upload a small ZIP; confirm object appears under the configured prefix.
6. **Product** — create a downloadable product; use Upload to S3 / Select from S3; save; confirm download file is `wcs3dm://…`.
7. **Purchase** — place a test order as a customer; download from My Account; confirm 302 to a signed URL and successful file fetch.
8. **Unauthorized** — open the product page logged out; confirm no permanent S3 URL in HTML; direct `wcs3dm://` must not work without WC auth.
9. **Migration dry-run** — attach a local file to another product; run dry-run; confirm log rows without reference changes.
10. **Migration** — run real migration; confirm reference updates and S3 objects; interrupt mid-job and resume.
11. **Cleanup dry-run** — with cleanup disabled, confirm dry-run only; enable cleanup, wait grace period (or set 0), dry-run then DELETE confirm.
12. **Deactivate / reactivate** — settings and tables persist; no fatal on reactivation.
13. **WP-CLI** — `wp wcs3dm test-connection`, `wp wcs3dm discover`, `wp wcs3dm migrate --dry-run --sync`.

## Rollback

1. Pause/cancel migration jobs.
2. Restore product download URLs from the migration CSV (source_path column) via product editor or WP-CLI.
3. Local files remain unless cleanup was executed.
4. S3 objects can be retained or deleted manually under the prefix.
