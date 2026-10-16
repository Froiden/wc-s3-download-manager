# S3 bucket configuration

1. Create a bucket in your preferred region.
2. Enable **Block Public Access** (all four settings).
3. Do **not** add a public bucket policy for paid downloads.
4. Optional: enable default bucket encryption (SSE-S3 or SSE-KMS).
5. Optional: configure a lifecycle rule for incomplete multipart uploads (e.g. abort after 7 days).
6. Optional: CloudFront distribution with Origin Access Control for private content; configure domain and key pair in plugin settings if using signed CloudFront URLs (advanced).
7. CORS is usually **not** required for server-side PHP uploads and customer redirects to signed URLs.

## S3-compatible providers

Set **Custom endpoint**, enable **Path-style addressing** if required, and verify with **Test Connection**. Behavior of ETags and some APIs may differ; the plugin verifies size and SHA-256 rather than trusting ETag-as-MD5.
