# Installation Guide

## Requirements

| Component | Minimum |
|-----------|---------|
| PHP | 8.2 |
| WordPress | 6.4 |
| WooCommerce | 8.0 |
| Extensions | curl, simplexml, openssl, json, mbstring (recommended) |

## Install from ZIP

1. Build or download `wc-s3-download-manager.zip` (plugin root folder inside the archive).
2. WordPress → **Plugins → Add New Plugin → Upload Plugin**.
3. Activate **WooCommerce S3 Download Manager**.
4. Ensure WooCommerce is active.
5. Go to **WooCommerce → S3 Download Manager → S3 Settings**.

## Install from source (Composer)

```bash
cd wp-content/plugins
# copy or clone the plugin folder
cd wc-s3-download-manager
composer install --no-dev --optimize-autoloader
```

Then activate in WordPress.

## Configuration

1. Create an IAM user or role (never root). Attach `docs/IAM_POLICY.json` (replace bucket name).
2. Create a **private** S3 bucket. Keep Block Public Access **on**.
3. Enter region, bucket, and credentials (or enable IAM role mode).
4. Click **Test Connection**, then **Run Diagnostics**.
5. Optionally set signed URL expiry (1–60 minutes), encryption, and multipart sizes.

## Security note on credentials

Credentials are stored in the WordPress `wp_options` table. This is **not** a dedicated secrets manager. Prefer IAM instance profiles / short-lived session tokens when the host supports them.
