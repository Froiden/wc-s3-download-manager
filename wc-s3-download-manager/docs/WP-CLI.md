# WP-CLI

Commands register as `wp wcs3dm`.

| Command | Description |
|---------|-------------|
| `wp wcs3dm test-connection` | Live nine-step S3 permission test |
| `wp wcs3dm discover [--page=1]` | List downloadable file discovery page |
| `wp wcs3dm migrate [--dry-run] [--product_id=N] [--sync]` | Queue or synchronously run migration |
| `wp wcs3dm job <uuid>` | Print job JSON |
| `wp wcs3dm cleanup [--dry-run] [--execute]` | Verified local cleanup |

`--sync` runs batches in the CLI process until complete (recommended for large stores during maintenance windows).
