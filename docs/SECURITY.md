# Security controls

## Authorization

* Customer downloads only after WooCommerce purchase checks (order key, email, limits, expiry, login).
* Signed URLs are minted **after** those checks; the signed URL alone is never used as storefront authorization.
* Admin AJAX requires `manage_woocommerce`, a valid nonce, and per-action rate limits.
* `wp_ajax_nopriv_*` handlers for plugin actions always return 403.

## Credentials

* Secrets are encrypted at rest (AES-256-CBC + HMAC) using keys derived from WordPress salts.
* Credential option uses `autoload=false`.
* Secrets are never sent to browser JavaScript or written to logs (including signed URL query strings).

## Object / path policy

* Downloads and uploads are restricted to the **configured bucket** and **object key prefix**.
* Browse/list prefixes cannot escape the configured root prefix.
* Local cleanup only deletes files under approved upload roots after multi-step verification; disabled by default; requires typing `DELETE`.
* Path traversal (`..`), core/plugin/theme paths, and Media Library attachments are blocked.

## Delivery

* Private objects; no automatic public-bucket changes.
* Presigned GET TTL clamped to 1–60 minutes.
* `Content-Disposition: attachment` on signed URLs; `Referrer-Policy: no-referrer` on redirect.
* Customer download rate limiting to reduce signed-URL farming.

## Operator guidance

* Never use AWS root credentials.
* Prefer IAM roles / temporary session tokens.
* Keep Block Public Access enabled on the bucket.
* See `docs/IAM_POLICY.json` for least privilege.
