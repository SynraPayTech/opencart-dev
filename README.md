# 💳 SynraPay for OpenCart 4

Accept **Visa, Mastercard and American Express** on your OpenCart store with
SynraPay. The card fields are SynraPay's own, shown **inside the checkout confirm
step** (no redirect), with 3-D Secure in an overlay. Card data never touches your
server.

| | |
|---|---|
| 📦 Extension | `synra_pay` v1.0.2 — folder [`synra_pay/`](./synra_pay), zip `synra_pay.ocmod.zip` |
| 🛒 OpenCart | 4.0.2.0 or greater (written against 4.0.2.x) |
| 🐘 PHP | 8.2 or greater |
| 🔐 Integration | SynraPay Direct API — hosted session, signature v2 |

---

## ✨ What the shopper sees

1. Picks **Credit / Debit card** at checkout.
2. SynraPay's card fields load in the payment block of the confirm step.
3. Clicks **Pay** → the card goes from SynraPay's frame to SynraPay, 3-D Secure opens in an overlay.
4. Lands on the normal OpenCart **success page**. The order moves to your *Paid* status.

---

## ✅ Before you start

You need these from the **SynraPay Merchant Portal** (sign in → pick the store → **Developers**):

| Item | Where in the portal |
|---|---|
| **Merchant ID** (`x-merchant-id`) | Developers → **API Keys** → Request Signing |
| **API Signature Key** | Developers → **API Keys** → Request Signing (Direct API grant required) |
| **Webhook signing secret** | Developers → **Webhooks** → Configuration → *Generate new secret* (shown once) |
| **Allowed origin** | Developers → **Integrations** → add your store's origin, e.g. `https://shop.example.com` |

Sandbox and Live each have their **own portal and own keys**. Start with Sandbox.

---

## 📦 Installation

### Option A — upload the ready zip

1. Download [`dist/synra_pay.ocmod.zip`](./dist/synra_pay.ocmod.zip).
2. Admin → **Extensions → Installer** → *Upload* → choose the zip → **Install** (green button).

### Option B — zip the folder yourself

1. Clone this repository.
2. Zip the **contents** of `synra_pay/` (so `install.json`, `admin/`, `catalog/`, `system/` are at the zip root) and name it `synra_pay.ocmod.zip`. Or run `sh build-zip.sh`.
3. Upload it via **Extensions → Installer** as above.

### Then enable the payment method

1. Admin → **Extensions → Extensions** → filter **Payments** → *SynraPay — Credit / Debit card* → **Install** (green +) → **Edit**.
2. Fill in the settings below → **Status** = Enabled → **Save**.

---

## ⚙️ Configuration

| Setting | What to enter |
|---|---|
| 🌍 Environment | **Sandbox** while testing, **Live** when you go live (*Develop* is for SynraPay internal testing) |
| 🆔 Merchant ID | From API Keys (for the chosen environment) |
| 🔑 API Key | From API Keys. Stays on your server, never sent to the browser |
| 📞 Fallback customer phone | SynraPay requires a phone on every order; used when the shopper's phone is missing or invalid |
| 🔔 Webhook signing secret | From Webhooks → Configuration |
| Webhook API-Key header name / value | Optional extra check on webhooks (API Keys → Webhook Verification) |
| Paid / Awaiting / Failed order status | Which OpenCart statuses the extension sets |
| Geo zone, Sort order, Status | Standard OpenCart payment options |

Save, then **copy two values printed on the same page**:

- 🔗 **Your Webhook URL** → Merchant Portal → Developers → Webhooks → *Update Webhook* → Endpoint URL, with **All events** subscribed.
- 🌐 **Your checkout origin** → Merchant Portal → Developers → Integrations → allowed origins.
  Without it the card form answers `ORIGIN_NOT_REGISTERED`. Live requires `https://`; Sandbox also accepts `http://localhost`.

🕒 **Cron**: make sure OpenCart's cron runs (`index.php?route=cron/cron`). The install adds an hourly `synra_pay` job that re-checks orders left awaiting SynraPay's final confirmation. The webhook remains the primary path.

---

## 🧪 Testing

1. Set Environment = **Sandbox** with Sandbox keys, register the Sandbox webhook URL and origin.
2. Place an order with a SynraPay sandbox test card.
3. Check: the order has your *Paid* status with a *SynraPay: payment captured* history line, and the webhook arrived (OpenCart → System → Maintenance → Error Logs shows order ids and statuses only).
4. Switch Environment to **Live**, paste the Live keys, register the Live webhook URL and origin.

---

## 🔄 How a payment flows

```
confirm step    POST extension/synra_pay/payment/synra_pay.session
   store        POST /v1/direct/create-order                merchant_order_id OC-<site>-<order_id>-<time>-<rand>
   store        POST /v1/direct/hosted-session              origin = your store → client_token
   page         SynraPayFields.create({ clientToken })      card fields mount in the payment block

"Pay"        →  fields.submit()                             3-D Secure in an overlay
   page         POST …synra_pay.confirm (polls)
   store        GET /v1/direct/payment/{order_id}/status
                   CAPTURED / AUTHORIZED  → Paid status → checkout/success
                   FAILED / DECLINED …    → order stays unconfirmed, new card session for a retry
                   approved, not final    → Awaiting status → checkout/success; webhook / hourly cron finish it
   webhook      POST …synra_pay.webhook&token=<per-store token>   signed (v2), source of truth
```

---

## 🔒 Security

- The browser only ever holds a short-lived **client token**. Merchant ID and API key stay on your server and sign every call (`HMAC-SHA512`, signature v2).
- The store **never trusts the browser** about the outcome: only SynraPay's order status moves an OpenCart order.
- The **amount paid must equal the order total** (same currency) or the order is held with a note.
- Webhooks are accepted only with a valid **signature + timestamp (5 min window)**, a per-store secret token in the URL and, optionally, your API-key header. Webhooks about superseded (declined) tries are acknowledged and ignored.
- Endpoints accept only requests from your own store's origin, scoped to the checkout session's order, with rate limits.
- Client-token environment must match the Environment setting.
- Nothing logs card data or keys.

---

## 🛠️ Troubleshooting

| Message | Meaning / fix |
|---|---|
| `ORIGIN_NOT_REGISTERED` | Register the exact origin printed on the settings page (scheme + host + port) under Developers → Integrations. |
| `MERCHANT_NOT_IDENTIFIED` | Merchant ID is wrong or belongs to another environment. |
| `ENVIRONMENT_MISMATCH` / 409 | The keys pasted are for a different environment than the one selected. |
| Card form keeps loading | Check System → Maintenance → Error Logs; usually a missing phone (set a fallback phone) or an unregistered origin. |
| Webhook answers 401 | Signing secret differs from the portal, or the API-key header name/value does not match. |
| Order stays *Awaiting* | Cron not running or webhook URL not registered. |

---

## 📁 Repository layout

```
synra_pay/
├── install.json
├── admin/controller/payment/synra_pay.php     settings form, install (table, webhook token, cron), uninstall
├── admin/model/payment/synra_pay.php          creates oc_synra_pay_order
├── catalog/controller/payment/synra_pay.php   index (block), session, confirm, webhook, recheck (cron)
├── catalog/model/payment/synra_pay.php        getMethods + order link table
├── catalog/view/template/payment/synra_pay.twig   card block + page script
└── system/library/synra_pay.php               SynraPayDirectClient: signed Direct API client, webhook verify, phone split
build-zip.sh                                   builds dist/synra_pay.ocmod.zip
dist/                                          ready-to-install zip
```

### 🧑‍💻 Notes for developers

- Rebuild the zip with `sh build-zip.sh` (uses `zip` or Windows `tar`; forward-slash entries).
- Settings keys: `payment_synra_pay_*`. Link table `oc_synra_pay_order`: one row per order with the SynraPay (Direct) order id used for API calls, the dashboard Transaction ID, auth code, amount, currency, status and the history of superseded tries.
- OpenCart 4.1 renamed some checkout internals (`payment_method` session shape); verify `currentOrder()` there.
- Refunds from the admin are not wired (Direct `/refund` is still a placeholder route).
- Not included on purpose: hosted-checkout redirect mode.

---

## 📝 Changelog

- **1.0.2** (2026-11-11) One transaction id. The Direct API order id is the Transaction ID the merchant dashboard shows, and every Direct API answer carries `transaction_id` equal to it. The OpenCart transaction id is `transaction_id` (falling back to the Direct order id); `synrapay_transaction_id` is no longer read from the API (the link table column of that name is kept as storage). Webhook settlement takes the Transaction ID from the webhook body's `transactionId` and no longer makes a status read for it. The paid history line lists Merchant order ID, Transaction ID and the auth code when present; the "SynraPay order ID" line is gone because it would repeat the Transaction ID.
- **1.0.1** — The OpenCart transaction id now matches the SynraPay merchant dashboard's **Transaction ID** (`synrapay_transaction_id` from the status read, falling back to the Direct order id). Webhook settlement makes one best-effort status read for it and never blocks on it. The paid history line lists Merchant order ID, Transaction ID, SynraPay order ID and the auth code when present. No gateway reference is stored or shown any more (it is internal to SynraPay; the 1.0.0 `gateway_ref` column is kept but no longer written). Link table gains `synrapay_transaction_id` + `auth_code` (added automatically when missing).
- **1.0.0** — First release: hosted-session card fields on the confirm step, webhook, hourly re-check, admin settings.

## 🙋 Support

Open an issue in this repository or contact your SynraPay account manager through the Merchant Portal.
