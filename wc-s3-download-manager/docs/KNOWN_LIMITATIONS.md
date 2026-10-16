# Known limitations

1. **CloudFront signed URLs** — Domain and key-pair fields are stored; full CloudFront cookie/URL signing is optional and requires a correctly configured distribution and private key. Default delivery uses S3 presigned URLs.
2. **Action Scheduler** — If unavailable, jobs fall back to WP-Cron, which is less reliable on low-traffic sites. Prefer `wp wcs3dm migrate --sync` for large migrations.
3. **Full checksum of huge objects** — Migration verifies SHA-256 fully for objects ≤ 50 MB by default; larger objects verify existence, size, and presigned access (full checksum can be forced in code/filters later).
4. **ETag ≠ MD5** — Multipart and encrypted objects make ETag unreliable as a checksum; the plugin does not treat ETag as MD5.
5. **External remote URLs** — Migration only resolves files hosted on the same WordPress site / approved local roots. Arbitrary remote HTTP sources are marked unsupported.
6. **WordPress options are not a vault** — Credentials in `wp_options` can be read by anyone with database or `manage_options`-level access.
7. **Approved download directories** — Custom `wcs3dm://` references are allowed via filters; unusual WC hardening plugins may still need an exception.
8. **Uninstall** — Removes options and custom tables only; does not delete S3 objects or rewrite product downloads.
