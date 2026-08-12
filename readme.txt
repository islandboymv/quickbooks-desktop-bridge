=== QuickBooks Desktop Bridge for WooCommerce ===
Contributors: islandboymv
Tags: woocommerce, quickbooks, quickbooks desktop, web connector, accounting
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Feeds paid WooCommerce orders into QuickBooks Desktop as sales receipts via the QuickBooks Web Connector.

== Description ==

QuickBooks Desktop has no cloud API. This plugin bridges the gap the way Intuit intended: it queues each order as it is paid and exposes a Web Connector (QBWC) SOAP endpoint that the QuickBooks PC polls on a schedule. Customers and items are auto-created; each order lands as a sales receipt whose total matches the order exactly.

* One sales receipt per paid order, RefNumber = order number
* Auto-creates customers (billing name) and non-inventory items (per SKU), or generic single-customer / single-item modes
* Queue screen under WooCommerce → QuickBooks Bridge with per-order QuickBooks errors and requeue
* One-click .qwc file download for the Web Connector
* HPOS compatible; no third-party services or subscriptions

== Installation ==

1. Activate the plugin, review WooCommerce → QuickBooks Bridge, save.
2. Download the .qwc file and add it to the QuickBooks Web Connector on the QuickBooks PC (File → Add an Application), entering the password shown in the settings.
3. Allow access "even when QuickBooks is not running" in the QuickBooks authorisation dialog.

== Changelog ==

= 0.1.0 =
* Initial release: QBWC SOAP endpoint, export queue, customer/item auto-creation, sales receipt export, .qwc generator, admin queue screen.
