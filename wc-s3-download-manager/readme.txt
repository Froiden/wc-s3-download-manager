=== WooCommerce S3 Download Manager ===
Contributors: froiden
Tags: woocommerce, s3, amazon, downloads, digital products
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Store WooCommerce downloadable products on private Amazon S3 and deliver them with temporary signed URLs.

== Description ==

WooCommerce S3 Download Manager connects your store to Amazon S3 (or S3-compatible storage) so digital product files stay private.

* Test AWS credentials, bucket access, and permissions
* Upload or select files from S3 in the product editor
* Bulk-migrate existing local downloads with dry-run, resume, and CSV reports
* Deliver authorized downloads via short-lived signed URLs
* Optional verified local cleanup with grace period

Requires WooCommerce 8.0+.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin
2. Activate the plugin
3. Configure WooCommerce → S3 Download Manager → S3 Settings
4. Run Test Connection

Composer-based installs: run `composer install --no-dev` inside the plugin directory.

== Frequently Asked Questions ==

= Are files public? =

No. Objects should remain private. Customers receive temporary signed URLs only after WooCommerce purchase authorization.

= Where are credentials stored? =

In the WordPress options table. Prefer IAM roles or temporary credentials when possible.

== Changelog ==

= 1.0.0 =
* Initial release
