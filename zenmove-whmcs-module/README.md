# ZenMove CRM — WHMCS Provisioning Module

Sell ZenMove CRM instances directly from your own WHMCS billing panel.  
Each order provisions a `*.zenmove.ca` CRM instance for your client, billed as a flat monthly fee.

---

## Plans

| WHMCS Product | Plan Value | Price |
|---|---|---|
| Starter CRM | `crm_starter` | $99/mo |
| Growth CRM | `crm_growth` | $249/mo |
| Pro CRM | `crm_pro` | $500/mo |

---

## Requirements

- WHMCS 8.x
- PHP 7.4+ (PHP 8.x recommended)
- cURL extension enabled
- An approved ZenMove Reseller account with API key + secret  
  → Apply at: https://zenmove.ca/dashboard/reseller.php

---

## Installation

1. Copy the `modules/servers/zenmovecrm/` folder into your WHMCS root:
   ```
   <whmcs_root>/modules/servers/zenmovecrm/
   ```

2. Verify the structure looks like this:
   ```
   modules/servers/zenmovecrm/
   ├── zenmovecrm.php
   ├── lib/
   │   └── ZenMoveClient.php
   └── templates/
       └── clientarea.tpl
   ```

---

## Setup in WHMCS Admin

### Step 1 — Add a Server

Go to **Setup → Products/Services → Servers → Add New Server**

| Field | Value |
|---|---|
| Name | e.g. `ZenMove Starter CRM` |
| Hostname | `zenmove.ca` |
| Module | `ZenMove CRM` |
| API URL | `https://zenmove.ca` |
| API Key | Your `zm_...` reseller API key |
| API Secret | Your reseller API secret |
| CRM Plan | `crm_starter` (or appropriate tier) |

> **One server per plan tier.** Create three servers total — one each for  
> `crm_starter`, `crm_growth`, and `crm_pro`. They use the same API key.

### Step 2 — Create Server Groups

Go to **Setup → Products/Services → Servers → Server Groups**

Create three groups (e.g. `ZenMove Starter`, `ZenMove Growth`, `ZenMove Pro`)  
and assign the matching server to each group.

### Step 3 — Create Products

Go to **Setup → Products/Services → Products/Services → Create a New Product**

For each plan:

| Setting | Value |
|---|---|
| Product Type | `Hosting Account` |
| Product Name | e.g. `ZenMove Starter CRM` |
| Module | `ZenMove CRM` |
| Server Group | The matching group from Step 2 |
| Pricing | Set your monthly price (can be your own margin over ZenMove's wholesale price) |

**Domain Tab:**  
Set "Require Domain" to **Yes** and use **"Use Existing Domain"** or a custom domain option.  
Tell clients to enter their desired subdomain (e.g. `maven`) — this becomes `maven.zenmove.ca`.

---

## How It Works

### Order lifecycle

```
Client orders → WHMCS calls CreateAccount
    → Module sends POST /api/reseller/provision.php to ZenMove
    → ZenMove queues the provisioning job
    → ZenMove admin provisions the CRM
    → Client receives confirmation email from ZenMove

Client fails to pay → WHMCS calls SuspendAccount
    → Module sends POST /api/reseller/suspend.php
    → CRM access is suspended

Client pays → WHMCS calls UnsuspendAccount
    → Module sends POST /api/reseller/unsuspend.php
    → CRM access restored

Client cancels → WHMCS calls TerminateAccount
    → Module sends POST /api/reseller/terminate.php
    → CRM instance permanently terminated

Client upgrades → WHMCS calls ChangePackage
    → Module sends POST /api/reseller/change_plan.php with new plan
    → ZenMove updates the instance tier
```

### Identifiers

Every action is keyed by **WHMCS Service ID** (`$params['serviceid']`).  
This is stored on the ZenMove `provisioning_jobs` row as `whmcs_service_id` at provision time, so all subsequent lifecycle calls find the right instance without storing anything extra in WHMCS.

### Client area

The client's service page shows:
- Instance status (Active / Suspended / Terminated)
- CRM plan and URL
- Provisioning date
- "Provisioning in progress" card while the job is still being set up

### Admin services tab

In WHMCS Admin → Client → Services → the service detail page shows a **ZenMove** tab with:
- Job ID (for ZenMove admin cross-reference)
- Instance status and job status
- Direct link to the ZenMove admin provisioning page

---

## Per-Product Plan Override (Advanced)

If you want **one server** to serve multiple plans (instead of three separate servers),  
add a product **Custom Field** named `crm_plan` to each product:

- Starter product → custom field `crm_plan` = `crm_starter`
- Growth product  → custom field `crm_plan` = `crm_growth`
- Pro product     → custom field `crm_plan` = `crm_pro`

The module checks this custom field first and uses it over the server config.

---

## Subdomain Convention

Clients enter their desired subdomain in the **Domain** field at checkout.  
The module normalises it automatically:

| Client enters | Domain provisioned |
|---|---|
| `maven` | `maven.zenmove.ca` |
| `maven.zenmove.ca` | `maven.zenmove.ca` |
| `Maven Moving` | `maven-moving` → error if invalid |

Instruct clients to keep it short, lowercase, letters/numbers/hyphens only.

---

## Troubleshooting

**"API Key is not configured"**  
→ Go to Setup → Servers → edit your ZenMove server → add your `zm_...` API key.

**"ZenMove API error: reseller_not_active"**  
→ Your reseller application is still pending. Log in to zenmove.ca/dashboard/reseller.php and check status.

**CreateAccount succeeds but client never gets CRM**  
→ Provisioning is manual on ZenMove's side. The job is queued; a ZenMove admin must action it.  
→ Check: https://zenmove.ca/admin/provisioning.php

**Module call logs**  
→ WHMCS Admin → Utilities → Logs → Module Log → filter by `zenmovecrm`

---

## File Reference

| File | Purpose |
|---|---|
| `zenmovecrm.php` | WHMCS module — all hook functions |
| `lib/ZenMoveClient.php` | HTTP client for ZenMove Reseller API |
| `templates/clientarea.tpl` | Smarty template for client-facing status card |
