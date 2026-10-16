# WooCommerce S3 Download Manager — Architecture & Security Model

## Overview

Production WordPress plugin that stores WooCommerce downloadable product files on Amazon S3 (or S3-compatible storage) and delivers them via short-lived presigned URLs after WooCommerce purchase authorization.

| Item | Value |
|------|-------|
| Plugin slug | `wc-s3-download-manager` |
| Text domain | `wc-s3-download-manager` |
| PHP namespace | `WCS3DM\` |
| Min PHP | 8.2 |
| Min WordPress | 6.4 |
| Min WooCommerce | 8.0 |
| AWS SDK | `aws/aws-sdk-php` ^3 |

## Download reference format

Plugin-owned URI scheme (not a public URL):

```
wcs3dm://{bucket}/{object-key}
```

Example: `wcs3dm://my-bucket/woocommerce-downloads/product-123/theme.zip`

- Stored via `WC_Product::set_downloads()` / `WC_Product_Download::set_file()`.
- Never rendered as a permanent public S3 URL in storefront HTML.
- Protected from `esc_url()` / `clean_url()` stripping during product save.
- Delivery: WooCommerce auth → force redirect method → generate presigned URL → 302.

## Extension points (WooCommerce)

| Hook | Role |
|------|------|
| `woocommerce_download_product_filepath` | Pass-through / normalize our scheme |
| `woocommerce_file_download_method` | Force `redirect` for `wcs3dm://` files |
| `woocommerce_download_file_redirect` | Generate presigned URL after WC auth |
| `woocommerce_admin_process_product_object` | Preserve scheme on save |
| `clean_url` (temporary) | Prevent WordPress from mangling custom scheme |
| Product editor meta box / JS | Upload to S3 / pick existing object |

Authorization always remains with `WC_Download_Handler` (order key, email, limits, expiry, login).

## Component map

```
Plugin
├── Admin          — menus, tabs, assets, AJAX
├── Settings       — encrypted-at-rest options (WP options API)
├── S3_Client      — AWS SDK wrapper (auth, upload, list, delete, sign)
├── Connection_Tester — 9-step live permission tests
├── Product_Integration — product editor + CRUD helpers
├── File_Uploader  — multipart upload, progress, cancel
├── Bulk_Migrator  — discovery, dry-run, batch migrate
├── Download_Handler — post-auth signed redirect
├── File_Verifier  — size + SHA-256 verification
├── Local_Cleanup  — gated local deletion + audit
├── Background_Jobs — Action Scheduler wrappers
├── Logger         — redacted operational logs
└── CLI            — WP-CLI commands
```

## Database / state design

### Options (`wp_options`)

| Key | Purpose |
|-----|---------|
| `wcs3dm_settings` | Non-secret settings (region, bucket, prefix, TTL, encryption, etc.) |
| `wcs3dm_credentials` | Access key / secret / session token (capability-restricted; never to JS) |
| `wcs3dm_job_{id}` | Transient-like job progress blobs (also mirrored in custom table) |

WordPress option storage is **not** a secrets manager. Credentials are encrypted at rest with AES-256-CBC + HMAC using keys derived from WordPress salts (`autoload=false`). Prefer IAM roles / temporary credentials when available. Changing WP salts will invalidate stored secrets (re-enter keys).

### Custom tables

**`{prefix}wcs3dm_migration_log`**

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| product_id | BIGINT | |
| download_id | VARCHAR(64) | WC download UUID |
| source_path | TEXT | Original local path/URL |
| object_key | TEXT | Destination S3 key |
| file_size | BIGINT | |
| checksum_sha256 | CHAR(64) | |
| status | VARCHAR(32) | pending/success/failed/skipped |
| message | TEXT | Safe error text |
| dry_run | TINYINT | |
| created_at / updated_at | DATETIME | |

**`{prefix}wcs3dm_cleanup_log`**

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| product_ids | TEXT | JSON array |
| local_path | TEXT | |
| object_key | TEXT | |
| checksum_sha256 | CHAR(64) | |
| outcome | VARCHAR(32) | deleted/skipped/failed/dry_run |
| reason | TEXT | |
| created_at | DATETIME | |

**`{prefix}wcs3dm_jobs`**

| Column | Type | Notes |
|--------|------|-------|
| id | VARCHAR(64) PK | UUID |
| type | VARCHAR(32) | migrate/cleanup/verify |
| status | VARCHAR(32) | queued/running/paused/cancelled/completed/failed |
| cursor | TEXT | JSON pagination cursor |
| stats | LONGTEXT | JSON progress/throughput |
| options | LONGTEXT | JSON job options |
| lock_token | VARCHAR(64) | Concurrency guard |
| created_at / updated_at | DATETIME | |

### Logs option / file

Operational events go to `{prefix}wcs3dm_logs` (rotating, capped) with secrets and full signed URLs stripped.

## Security model / threat model

### Assets

- AWS long-lived credentials
- Private S3 objects (paid digital goods)
- Customer download tokens / order keys
- Local filesystem paths under uploads/downloads

### Threats & controls

| Threat | Control |
|--------|---------|
| Credential theft via XSS | Never expose secrets to browser JS; AJAX returns only safe status |
| Unauthorized download | WC permission checks first; signed URL TTL 1–60 min; private bucket |
| Path traversal on cleanup | Realpath + allowlist under approved download dirs; no recursive dir delete |
| SSRF via migration URL | Resolve only local/site URLs; reject remote arbitrary hosts for migration source |
| Privilege escalation | `manage_woocommerce` capability + nonces on all mutating endpoints |
| Log leakage | Redact keys, tokens, query strings of signed URLs |
| Accidental public bucket | Never call PutPublicAccessBlock to open; never set public-read ACL |
| Premature local delete | Cleanup disabled by default; multi-check gate + dry-run + grace period |
| Concurrent migrate races | Job lock token + idempotent object keys (deterministic from product+download+checksum) |

### IAM least privilege (example in docs)

`s3:ListBucket`, `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject` (optional), `s3:AbortMultipartUpload`, `s3:ListBucketMultipartUploads` — scoped to one bucket/prefix. No root credentials.

## Background processing

- Prefer WooCommerce Action Scheduler (`as_enqueue_async_action` / `as_schedule_single_action`).
- Batch size configurable (default 5 files).
- Exponential backoff retries (1m, 5m, 15m).
- Pause / resume / cancel via job status flag checked each batch.
- WP-CLI for large stores: `wp wcs3dm migrate ...`

## Implementation phases

1. Skeleton, settings, connection tester, diagnostics  
2. S3 browser, uploader, secure download handler  
3. Product editor integration  
4. Bulk migration + reference updates  
5. Verification + local cleanup  
6. Jobs, WP-CLI, tests, documentation, installable ZIP  

## Known limitations (planned)

- CloudFront signed cookies/URLs are optional and require key-pair setup.
- Servers without Action Scheduler fall back to WP-Cron (less reliable).
- Checksum verification of very large objects may sample or stream; full re-download is opt-in for huge files.
- S3-compatible providers may differ on ETag semantics — we never treat ETag as MD5 alone.
