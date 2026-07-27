# ScienceData billing, quota & payment model

Status: **design / for sign-off** (2026-07-27). This document is the authoritative
description of how storage is quota'd, charged, invoiced, and paid on ScienceData.
It supersedes the scattered notes in `README.md` once implemented.

## 1. Actors

| Actor | Who | How they authenticate |
|-------|-----|------------------------|
| **User** | An end user with a home on a silo | SAML/WAYF/eduGAIN (eppn → uid) or local |
| **Institution** | A university, identified by its **email/UID domain** | Represented on the system by a **group** (one group per domain) |
| **Institution owner** | A UID the institution nominates (person or service account) | Their own SAML identity; made **group owner** of their domain group by us, manually |
| **Platform admin** | Us (ScienceData operators) | Nextcloud admin |

The institution's identity and credentials live with the institution via
SAML/WAYF/eduGAIN — we never hold their user accounts. Our only link is: their
nominated UID owns their domain group.

## 2. Quota model — two layers, one number

Every user has an **effective free quota** that is the sum of:

```
effective_free_quota(user) =
      B                      (platform baseline — we provide, free to all)
    + T(domain of user)      (institutional top-up — the user's university provides)
    + individual_adjustment  (optional per-user override by admin)
    + active_gift_credit     (time-limited gift codes, while valid)
```

- **`B` — platform baseline.** The existing default free quota (config: default
  `freequota`). Free to everyone; we absorb the cost.
- **`T` — institutional top-up, per user, per domain.** Extra free quota a
  university grants *each* of its users. Stored per group. Universities may want
  predictability, but nobody can predict usage — so the only levers we offer them
  are (a) adjust `T`, and (b) optionally stop new signups on their domain
  (§8). `T` is **per user**, not a shared domain pool.

**The user sees a single figure** — their effective free quota. They are never
shown, and need not care, that it is a sum of what we provide and what their
institution pays for.

## 3. Enforcement — hard stop, never deletion

The bundled Sabre `QuotaPlugin` reports the effective free quota as the WebDAV
`quota-available-bytes` / `quota-used-bytes`, so all clients (incl. the desktop
sync client) see it as the ceiling. Writes that would exceed `B + T` are
**refused** (hard stop).

**We never automatically delete user data.** A user over quota simply cannot add
more until usage drops or `T` is raised. No purge job, ever.

Because usage is capped at `B + T`, a user's billable-above-baseline usage is by
construction `≤ T` — the institutional cap is enforced implicitly, no separate
cap logic needed in the billing math.

## 4. Charging — metered, above baseline

Charging is **metered on actual usage above the platform baseline**, at
`charge_per_gb` per GB-month (existing config). Usage below `B` is never charged.

For each user in a billing period:

```
billable(user) = max(0, avg_usage(user) - B)        # in GB
charge(user)   = billable(user) * charge_per_gb      # capped at T by §3 enforcement
```

**Who pays** depends on whether the user's domain has a billing arrangement:

- **Domain-covered user** (their domain group has an owner / billing arrangement):
  `charge(user)` rolls up into the **institution's** bill. The user's own bill is
  0 / absorbed. This is the normal case for university users.
- **Uncovered user** (no institution behind them): billed **personally** (the
  existing per-user path). This covers standalone users. In practice almost always
  0 DKK because they stay under `B`.

## 5. Invoices & statuses

Two statuses only: **`paid`** / **`pending`**.

**Per-user bill** (`files_accounting` table, unchanged): generated monthly on the
master. `amount_due == 0` → born **`paid`** (the ~99% case). `amount_due > 0` and
uncovered → **`pending`** + PDF invoice.

**Domain bill** (new, per group per month): sum of `charge(user)` over the domain's
users. One **bulk invoice** issued to the institution, `pending` until settled.
Individual member bills for that domain are 0 / absorbed.

A monthly row is written even when 0 so history is complete.

## 6. Notifications — UI first, mail minimal

- **Uncovered user with a pending personal bill:** a **persistent UI notification**
  in the bell dropdown (`OCP\Notification`), non-dismissable, that **stays until the
  bill is paid** (matches the old system). Removed when the bill flips to `paid`.
- **Email is the fallback, not the default:** active users (logged in within the
  billing period) get the UI notification *only*; inactive users also get one
  email with the invoice. (In practice inactive users rarely exceed quota anyway.)
- **Institution with a pending bulk invoice:** the persistent UI notification goes
  to the **group owner** (the institution's nominated UID), who can pay/settle via
  the UI or API (§7).
- **Admin escalation:** a background job emails the **platform admin** (`fromemail`)
  a summary of any bill (personal or domain) that has been `pending` for more than
  **3 months** (configurable). De-duped so the same bill isn't re-reported. No
  repeated user email.

## 7. Payment & marking paid

- **PayPal is postponed** (no users are expected to pay for themselves). The seam
  is kept: the installer already stages PayPal creds; when enabled, each pending
  bill/invoice shows a **"Pay now"** button → PayPal → IPN marks it `paid`
  (`reference_id` = txn id) and clears the notification. Until enabled, the button
  is hidden — turning it on is a config flip, not a rebuild. Rationale: external
  collaborators of research groups don't pay today but wouldn't mind, and some
  prefer to — making self-pay easy is worth having.
- **Admin "Mark paid"** (available now): flips a `pending` bill to `paid` and stamps
  `reference_id` (e.g. a bank reference). Supports **bulk** — mark all of a domain's
  period bills (or a bulk invoice) paid in one action, since payment in practice
  arrives per university, per domain.

## 8. Delegation — institutions via `user_group_admin`

The **group owner** of a domain group is the institution's presence on the system.
Making a nominated UID the owner is a **manual onboarding step**: we communicate
with the university, they pick a UID (person or service account — their choice,
identity stays with them via SAML), and we set it as owner of the group
corresponding to their domain.

Through `user_group_admin`, the owner can then:

- **Adjust `T`** for their domain (per-user institutional free quota) — UI + API.
- **Add / remove members**, including **externals** — this is how a research group
  brings external collaborators under the institution's quota and billing.
- **Stop new signups** on their domain (optional gate; see user_saml below).
- **View and pay** their bulk invoices — UI + API.

Both **we** (admin UI) and **they** (delegated UI + API) can set/adjust `T`.

## 9. Cross-app dependencies

This model spans three apps:

- **files_accounting** (this app): quota math, metered charging, per-user + domain
  invoices, statuses, notifications, escalation, bulk mark-paid, PayPal seam.
- **user_group_admin**: group = domain; group owner = institution's UID; owner
  capabilities above (set `T`, membership incl. externals, signup gate, view/pay
  invoices).
- **user_saml**: **must create a group per email/UID domain and add the user to it
  on creation** (the old user_saml did this — the NC port must replicate it, or the
  domain rollup has nothing to group on). Must also honor a per-domain
  **"signups stopped"** flag set by the group owner.

## 10. Config keys (existing + new)

Existing (see README): `charge_per_gb`, `billingcurrency`, `billingdayofmonth`,
`billingnetdays`, `billingvat`, `fromemail`, default `freequota` (= `B`), etc.

New:
- `billing_admin_alert_months` (default `3`) — pending age before admin escalation.
- Per-group institutional top-up `T` — stored per group (new small table or group
  metadata; implementation detail).
- Per-group `signups_stopped` flag.

## 11. Deferred / open

- PayPal activation (seam only for now).
- University-facing self-service UI polish (API first is acceptable).
- `getStorageGrantUsage` still returns 0 until `user_group_admin` implements the
  shared-folder file structure (pre-existing note).
