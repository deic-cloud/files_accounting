# files_accounting - Accounting of storage usage, monthly invoices, gift codes

## Overview

`files_accounting` tracks per-user storage usage, generates monthly PDF invoices, and exposes a REST API that institutional customers (universities, research organisations) can use to retrieve billing data and statistics for their users. It is designed for the ScienceData master/silo architecture provided by `files_sharding`, where accounting runs centrally on the master node while storage lives on silo nodes.

Key features:
- Daily usage logging (files + trash) to per-user flat files
- Monthly billing run on the master: fetches usage averages from silos, calculates charges, writes invoice PDFs
- Storage grant (group quota) billing: usage allocated by a group owner via `user_group_admin` is charged to the owner
- Gift codes: time-limited free-storage grants redeemable by users
- Pre-paid credit deducted from monthly charges
- OCS REST API for institutional access to bills, invoices, usage data, and free-quota management
- Inter-silo API secured with the `files_sharding` shared secret
- Sabre WebDAV quota plugin: enforces `freequota` limits on WebDAV PROPFIND
- PDF invoices generated with bundled FPDF (no Composer required)
- Email delivery via Nextcloud's built-in mailer

## Requirements

- Nextcloud 34+
- PHP 8.2+
- `files_sharding` (for multi-node setups; app degrades gracefully to single-node mode without it)
- `user_group_admin` (optional; enables storage-grant billing)

## Installation

```bash
# On every node (master and all silos):
sudo -u www occ app:enable files_accounting
```

DB migrations run automatically. The two tables created are:
- `oc_files_accounting` — monthly billing records
- `oc_files_accounting_gifts` — gift codes

## Configuration

All billing parameters live in `config/config.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `charge_per_gb` | `0.0` | Price per GB per month (in `billingcurrency`) |
| `billingcurrency` | `EUR` | Currency code shown on invoices |
| `billingdayofmonth` | `1` | Day of month the billing job runs |
| `billingnetdays` | `120` | Days until invoice is due |
| `billingvat` | `25` | VAT percentage shown on invoices |
| `fromaddress` | `''` | Issuer postal address (on invoice) |
| `fromemail` | `''` | Issuer email (on invoice; also used as From: address) |
| `billinglogo` | `''` | URL of logo image to embed in PDF invoices |
| `pod_charge_per_second` | `['.*' => 0.0]` | Map of image-name regex → price/second for pod usage |
| `pod_free_monthly_seconds` | `0` | Free tier pod-seconds per user per month |
| `billing_admin_alert_months` | `3` | Months a bill may stay pending before the admin (`fromemail`) is emailed a digest; each bill is reported once |
| `dryrunbillingusers` | `''` | Comma-separated user IDs for test runs (no DB writes, invoice prefix `test-`) |

### Setting a free quota (no-charge threshold) for a user

```bash
# Via OCS API (admin token or session):
curl -u admin:password -X POST \
  'https://master/ocs/v2.php/apps/files_accounting/api/v1/freequota?format=json' \
  -H 'OCS-APIRequest: true' \
  -d '{"user":"alice","quota":"50 GB"}'
```

Or via the admin settings panel in Nextcloud (Settings → Administration → Storage).

### Default free quota (applies to users with no individual override)

```bash
curl -u admin:password -X POST \
  'https://master/ocs/v2.php/apps/files_accounting/api/v1/freequota?format=json' \
  -H 'OCS-APIRequest: true' \
  -d '{"quota":"50 GB","default":true}'
```

## REST API

All OCS endpoints return JSON when `?format=json` is appended. Admin authentication required unless noted.

### Bills

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/bills` | List bills. Optional params: `user`, `year`, `status` |
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/invoice` | Get invoice PDF (base64). Params: `user`, `filename` |
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/usage` | Daily usage rows. Params: `user`, `year`, optional `month` |
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/freequota` | Get free quota. Param: `user` |
| `POST` | `/ocs/v2.php/apps/files_accounting/api/v1/freequota` | Set free quota. Body: `{user, quota}` or `{quota, default:true}` |

### Gift codes

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/gifts` | List all gift codes |
| `POST` | `/ocs/v2.php/apps/files_accounting/api/v1/gifts` | Create gift. Body: `{size, days, claim_expires_days, site}` |
| `DELETE` | `/ocs/v2.php/apps/files_accounting/api/v1/gifts/{code}` | Delete gift code |
| `POST` | `/ocs/v2.php/apps/files_accounting/api/v1/gifts/redeem` | Redeem gift. Body: `{code, user}` |

### Personal (logged-in user, no admin required)

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/my/bills` | Own bills |
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/my/usage` | Own usage data |
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/my/invoice` | Own invoice PDF |
| `GET` | `/ocs/v2.php/apps/files_accounting/api/v1/my/gifts` | Own redeemed gifts |
| `POST` | `/ocs/v2.php/apps/files_accounting/api/v1/my/gifts/redeem` | Redeem a gift code |

## Architecture

### Billing flow

1. Background job (`Stats`) runs every 6 hours on every node.
2. Each node logs the current storage usage for every user to a per-user flat file at `{datadirectory}/{user}/files_accounting/usage-{year}.txt`.
3. On the billing day, **master only** iterates all users:
   - Fetches the monthly usage average from the user's home silo via the inter-silo internal API.
   - Computes the billable amount (usage minus free quota, minus prepaid credit).
   - Writes a bill record to `oc_files_accounting`.
   - Generates a PDF invoice with bundled FPDF and stores it at `{datadirectory}/{user}/files_accounting/bills/{reference}.pdf`.
   - Sends a notification email to the user via Nextcloud's mailer.

### Inter-silo API

Internal endpoints at `/index.php/apps/files_accounting/internal/…` are called by the master to query or update silo-local state. All requests carry `Authorization: Bearer {files_sharding_shared_secret}`.

| Endpoint | Description |
|----------|-------------|
| `POST /internal/currentusageaverage` | Returns monthly usage average from local flat file |
| `POST /internal/personalstorage` | Returns local usage + free quota |
| `POST /internal/setfreequota` | Updates user's free quota preference locally |
| `GET /internal/prepaid` | Returns prepaid credit balance |
| `POST /internal/prepaid` | Updates prepaid credit balance |
| `POST /internal/expiregifts` | Expires stale gift codes for a user |
| `POST /internal/redeemgift` | Redeems a gift code locally |

### Storage grant billing

When a group owner sets a storage grant via `user_group_admin`, the granted storage usage is charged to the owner, not the members. The billing job calls `getStorageGrantUsage(gid)` for each owned group with a non-empty grant. This returns 0 until `user_group_admin` implements the shared folder file structure; the data model and billing logic are in place.

### WebDAV quota enforcement

The bundled `QuotaPlugin` (Sabre) overrides `quota-available-bytes` and `quota-used-bytes` PROPFIND responses to reflect the user's `freequota` setting when set, ensuring WebDAV clients (including the Nextcloud desktop sync client) respect the configured limit.

### Pod usage

Usage of compute pods (from `user_pods`) is read from per-user flat files at `{datadirectory}/{user}/files_accounting/pods/podsusage_{year}_{month}.txt`. The billing logic is in place; pod usage reporting requires the `user_pods` app.

## Development

No build step. Pure PHP + plain JS.

**Deploy to all nodes:**
```bash
for host in master silo1 silo2; do
  rsync -av --delete apps/files_accounting/ $host:/var/www/nextcloud/apps/files_accounting/
done
service php8.3-fpm reload   # on each pod to clear OPcache
```

**Bundled vendor libraries** (in `lib/Vendor/`):
- FPDF 1.8x — PDF generation (MIT licence)

**No Composer, no npm, no webpack.**
