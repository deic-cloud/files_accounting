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

## 2. Sponsorship model — baseline + two institutional options

We (the platform) sponsor a **baseline** free quota `B` on every user's own home
directory (config: default `freequota`; free to everyone, we absorb it). A
university — represented by the **owner** of its domain group (e.g. `dtu.dk`) —
can sponsor *additional* storage for its users via **either or both** of two
independent mechanisms. This is deliberate flexibility: each university has its
own agreement with its users.

### Option A — grant folder (owner-controlled storage)

A separate, group-**owner-controlled** folder that appears inside each member's
files tree at `.uga_grants/{gid}/` (implemented in `user_group_admin`). The owner
retains control of the data even after a member leaves — for universities (or
research-group leaders) that want to *own* the storage they provide.

- **Deliberately NOT a sync target** — kept out of the desktop sync client on
  purpose, to keep the "owner-controlled storage" narrative consistent. Not a
  fragility workaround; a design choice.
- **Billed to the group owner.** The billing job already charges grant-folder
  usage to the owner (`getOwnedStorageGrants` + `getStorageGrantUsage`). Size is
  `uga_groups.storage_grant` (per member) / `storage_grant_total` (committed pool).
- A research-group owner's grant may in turn be covered by *his* university's
  sponsorship; overflow is handled case-by-case.

### Option B — home-directory quota top-up

The university buys extra free quota on its users' **standard home directories** —
for universities that just want to offer a OneDrive alternative and don't care to
control the data. The user keeps using their ordinary, sync-safe home; only the
free-tier number grows.

```
effective_home_free(user) =
      B                    (platform baseline — we sponsor)
    + individual override  (optional per-user admin override of B)
    + Σ topup(user's groups)   (institutional top-up — the university sponsors)
```

- Stored per group in **files_accounting** (`files_accounting_topup`), separate
  from `storage_grant` (Option A) so a university can offer either or both.
- **The user sees one figure** — their effective home free quota — and needn't
  know the split between what we and their university sponsor.

## 3. Enforcement — billing threshold now; hard stop later

For now the effective free quota is a **billing threshold** (the no-charge line),
not a hard storage cap. A Sabre quota-enforcement plugin (refusing writes past the
ceiling, reporting it over WebDAV) is a **separate, later** piece — kept out of
this pass for stability.

**We never automatically delete user data** under any design.

## 4. Charging — metered, above baseline

Charging is **metered on actual usage above the platform baseline `B`**, at
`charge_per_gb` per GB-month. Usage below `B` is never charged.

- **Option B (home top-up):** the **university** (group owner) is billed for its
  members' home usage in the sponsored band — above `B`, capped at the per-member
  top-up. Because each member's *effective* free already includes the top-up, the
  member's **own** bill covers only usage beyond `B + topup` (0 while within it),
  so there is no double-counting. Folded into the owner's monthly bill as line
  items, alongside Option A grant charges.
- **Uncovered user** (no institutional sponsorship): billed **personally** on
  usage above their baseline. Almost always 0 (they stay under `B`).

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

Payment is by **invoice → bank transfer**. The payers are **university
administrations paying for their users** — they settle invoices from their finance
department by bank transfer, not with a consumer payment button.

- **No PayPal / card gateway** (decision 2026-07-28). Not merely postponed —
  deliberately not built: (a) university finance departments pay by bank transfer,
  not PayPal; (b) a US big-tech payment button would alienate the very users who
  come here to get *away* from US big tech. Focus instead on making invoicing to
  administrations easy and transparent.
- **Invoices carry our bank/payment details** (IBAN/account + a payment reference)
  so an administration can pay directly — the `billing_bank_details` config value,
  rendered as a "Payment details" block on the invoice PDF (built).
- **Admin "Mark paid"** (built): flips a `pending` bill to `paid`, records
  `time_paid`, and stores an optional **bank/payment reference** in a dedicated
  `payment_ref` column — kept **separate** from `reference_id` (the invoice number /
  PDF filename), which is never overwritten. Supports **bulk** — settle all of a
  domain's period bills at once, since payment arrives per university.
- **One chronological Bills list** on the admin Billing page (newest first):
  user / issued / amount / status / due / paid-on / invoice # / payment ref, with a
  running **outstanding total**. The invoice # links to the PDF; the payment ref is
  click-to-edit. Because a bill row is written monthly for every user even when
  nothing is due, the default view is the **actionable worklist** only: the **last
  3 months** of **chargeable, unpaid** bills. Three controls widen it —
  **"Hide non-chargeable bills"** (zero-amount, checked), **"Hide paid bills"**
  (checked), and **"Show older bills"** — so paid/zero/older rows stay as history
  without cluttering the default.

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
  invoices, statuses, notifications, escalation, bulk mark-paid.
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
- Per-group home top-up (Option B) — stored in `files_accounting_topup` (built).
- `billing_bank_details` — IBAN/account/bank rendered as a "Payment details" block
  on the invoice PDF (built). `', '` or newlines separate lines.
- Per-group `signups_stopped` flag (not yet built).

## 11. Deferred / open

- University-facing self-service (group owner sets their own top-up) — admin + API
  only for now; owner UI in `user_group_admin` later.
- Sabre hard-stop quota enforcement (billing threshold only for now).
- No PayPal / card gateway (decided against — §7).
