# Troubleshooting

| Symptom / code | Likely cause | Action |
|----------------|--------------|--------|
| `InvalidAccessKeyId` | Bad/disabled key | Create a new IAM access key |
| `SignatureDoesNotMatch` | Wrong secret or clock skew | Re-enter secret; sync NTP |
| `AccessDenied` | Missing IAM actions/prefix | Update policy; verify prefix |
| `PermanentRedirect` / region errors | Wrong region | Set region to bucket location |
| `NoSuchBucket` | Typo / wrong account | Verify bucket name |
| Missing AWS SDK | Composer deps absent | `composer install --no-dev` |
| Upload size / timeout | PHP limits | Raise `upload_max_filesize`, `post_max_size`, `max_execution_time`; rely on multipart |
| Multipart failure | Network / Abort rights | Grant AbortMultipartUpload; retry |
| Missing local file | Path/URL not on this host | Re-upload or fix path before migrate |
| Invalid WC reference | Custom scheme stripped | Re-save via plugin UI; check approved-directory filters |
| Expired signed URL | TTL too short / slow client | Increase expiry (max 60 min) |
| Background job stuck | AS/cron not running | Trigger Action Scheduler or WP-Cron; use `wp wcs3dm migrate --sync` |
| Download 404/503 | Object deleted / bad creds | Head object in Browse S3; re-test connection |
| Leftover health-check object | No DeleteObject | Delete manually or grant delete |

Logs are under **S3 Download Manager → Logs** and never include secret keys or full signed URLs.
