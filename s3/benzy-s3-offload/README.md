# Benzy S3 Media Offload

Uploads the WordPress Media Library to Amazon S3 (or any S3-compatible storage), serves media from the bucket or a CDN, and deletes the local copies.

- **New uploads**: the original, the scaled original and every generated size go to S3 as soon as WordPress finishes creating the sizes.
- **Existing media**: *Settings → S3 Offload → Offload existing media* migrates the library in resumable batches, or use WP-CLI.
- **URLs**: attachment URLs, `srcset` and hard-coded upload URLs in post content point at S3/CDN.
- **Deletes**: deleting an attachment deletes its objects from the bucket.
- **Editing**: when the image editor or a thumbnail regenerator needs the original, it is downloaded back, then removed again once the new sizes are uploaded.

Requires WordPress 6.0+ and PHP 8.1+ (tested on PHP 8.1–8.4). Uses the official AWS SDK for PHP v3.

## Install

Upload `benzy-s3-offload.zip` under *Plugins → Add New → Upload*. The zip already contains `vendor/`.

To build from source:

```bash
composer install --no-dev -o
```

`composer.json` strips every AWS service except S3/STS/SSO, keeping `vendor/` around 8 MB.

## Configure

Put the credentials in `wp-config.php` (recommended) instead of saving them in the database:

```php
define( 'BS3O_ACCESS_KEY_ID', 'AKIA...' );
define( 'BS3O_SECRET_ACCESS_KEY', '...' );
```

If neither these constants nor the settings fields have keys, the SDK's default credential chain is used (environment variables, `~/.aws/credentials`, or the EC2/ECS IAM role).

Then in *Settings → S3 Offload* set the bucket, the region, an optional path prefix (default `wp-content/uploads/`) and an optional CDN URL, then click **Test connection**.

Minimum IAM policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject", "s3:PutObjectAcl"],
    "Resource": "arn:aws:s3:::YOUR-BUCKET/*"
  }]
}
```

The bucket must be publicly readable (a bucket policy allowing `s3:GetObject`), or sit behind CloudFront with Origin Access Control and the CloudFront domain set as the CDN URL. Only turn on the *public-read ACL* option if the bucket has ACLs enabled.

For DigitalOcean Spaces, Wasabi, Cloudflare R2 or MinIO, fill in **Custom endpoint**, and tick **Path-style URLs** if the provider requires it.

## WP-CLI

```bash
wp bs3o test                     # check the bucket is writable
wp bs3o status                   # counts: total / in S3 / remaining / failed
wp bs3o offload                  # migrate the existing library
wp bs3o offload --retry-failed   # retry items that failed before
```

## Notes

- Offload state is kept per attachment in the `_bs3o_info` post meta, and errors are kept in `_bs3o_error`.
- Uninstalling removes the settings only. Objects in S3 and the per-attachment meta are kept so that media doesn't silently break. Copy the files back to `wp-content/uploads` before you remove the plugin if you want to stop using S3.
- Plugins that rewrite image files *after* upload (optimisers, WebP converters) must run before this plugin (filter priority < 99 on `wp_generate_attachment_metadata`). Otherwise the local files they expect are already gone.
- If another plugin already loads the AWS SDK, that copy is used instead of the bundled one.
- Filters: `bs3o_client_args`, `bs3o_put_object_args`, `bs3o_bulk_batch_size`. Action: `bs3o_offloaded`.
