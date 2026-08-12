# QuickBooks Desktop Bridge for WooCommerce

Feeds paid WooCommerce orders into **QuickBooks Desktop** as sales receipts via the
**QuickBooks Web Connector** — no third-party subscription, nothing installed on the
web server beyond this plugin.

QuickBooks Desktop has no cloud API, so nothing on the internet can push into a
company file directly. Instead, Intuit's Web Connector (bundled free with QB Desktop)
polls a web endpoint from the QuickBooks PC and relays qbXML into QuickBooks. This
plugin provides that endpoint and a queue in front of it:

```
order paid → queued in WordPress → Web Connector polls /wc-api/qbdb/ →
CustomerAdd + ItemAdds + SalesReceiptAdd → QuickBooks Desktop
```

## What gets exported

- One **sales receipt** per order (orders are paid at checkout, so receipts — not
  invoices — are the correct QB transaction), `RefNumber` = order number.
- The **customer** (billing name, address, phone, email) is auto-created on first
  sight; existing customers are reused. Or book everything under one generic
  customer (setting).
- Each product line references a QB **non-inventory item named after its SKU**,
  auto-created against a configurable income account on first sight. Or lump all
  product lines under one generic item (setting).
- Line amounts are net of coupon discounts; fees, shipping and tax (if any) are
  added as their own lines — the receipt total always equals the order total.
- Orders queue **once**, the first time they reach a configured status
  (default: Processing / Ready for pickup / Completed). The result (including the
  QB TxnID) is recorded on the order as a note + meta.

## Setup

### On the website (WordPress)

1. Install and activate the plugin.
2. Go to **WooCommerce → QuickBooks Bridge**. A username, password and app IDs are
   generated for you. Adjust the income account name and item/customer modes if
   needed, and save.
3. Click **Download .qwc file** and get that file (and the password) to the
   QuickBooks PC.

### On the QuickBooks PC (Windows)

1. Open the company file in QuickBooks Desktop **as Admin, single-user mode** (first
   run only).
2. Install the **QuickBooks Web Connector** if not present (Help → Update... it
   ships with QB Desktop 2015+; also downloadable from Intuit).
3. In the Web Connector: **File → Add an Application**, pick the `.qwc` file.
4. QuickBooks pops a certificate/access dialog — choose **"Yes, always allow access,
   even when QuickBooks is not running"** so scheduled runs work unattended.
5. Enter the password from the settings page, tick the app, and click
   **Update Selected** for a first run.

### In QuickBooks itself

- Make sure the **income account** named in the settings exists (default `Sales`).
- The shop sells in **MVR**. Simplest correct setup is a company file whose home
  currency is MVR. If the file's home currency is something else, the amounts still
  import — as home-currency numbers — so either keep a dedicated MVR file or accept
  a manual revaluation step. Multicurrency-per-transaction is intentionally out of
  scope.

## Troubleshooting

- **WooCommerce → QuickBooks Bridge** shows the queue: pending / in flight /
  exported / errors, with per-row error messages straight from QuickBooks and a
  requeue button.
- "Last Web Connector contact" on the same screen confirms polling is happening.
- Common QB errors: `3140` — the income account name doesn't exist in QB;
  `3100` — name already exists (harmless, the batch continues by design).
- A run that dies mid-way (PC off, QB busy) leaves rows "in flight"; they release
  back to pending automatically after 15 minutes.

## Notes

- Refunds are not synced back; handle refunds in both systems manually.
- Customer/product names are transliterated to ASCII — QuickBooks Desktop is not
  Unicode-safe (Thaana will not survive the trip).
- Self-updates from GitHub Releases (bump `Version:`, tag `vX.Y.Z`, publish).
- Anonymous install telemetry (SHA-256-hashed site URL only); opt out with
  `add_filter( 'qbdb_telemetry_enabled', '__return_false' );`
