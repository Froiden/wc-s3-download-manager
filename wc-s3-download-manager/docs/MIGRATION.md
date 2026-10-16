# Migration instructions

1. Backup the database and `wp-content/uploads` (especially `woocommerce_uploads`).
2. Confirm **Test Connection** passes upload, read, and presign checks.
3. Open **WooCommerce → S3 Download Manager → Bulk Migration**.
4. Click **Discover files** to review local / S3 / missing / unsupported counts and estimated bytes.
5. Run **Start dry-run migration** first. Review the migration log and CSV export.
6. Start a real migration (optionally limit product IDs). Processing runs in Action Scheduler batches.
7. Use Pause / Resume / Cancel / Retry as needed. Interrupted jobs resume from the product ID cursor.
8. After success, product download files use `wcs3dm://bucket/key` references.
9. Local files are **retained** until you explicitly run Local Cleanup.

## WP-CLI

```bash
wp wcs3dm discover
wp wcs3dm migrate --dry-run --sync
wp wcs3dm migrate --sync
wp wcs3dm migrate --product_id=123 --sync
wp wcs3dm job <job-uuid>
```

## Rollback

1. Cancel any running job.
2. Export CSV from the Migration tab (`source_path` column holds originals).
3. Restore each product’s download URL/path via the product editor or a scripted CRUD update to the recorded `source_path`.
4. S3 objects under the prefix can be left in place or deleted manually.
5. If cleanup already deleted local files, restore from your backup.
