Architecture decision: WooCommerce internal functions + IdeaERP REST API
The plugin will use WooCommerce internal PHP functions (wc_get_product(), wc_create_order(), wc_update_product_stock(), etc.) for all WooCommerce operations. All external HTTP communication goes exclusively to the IdeaERP REST API (Bearer token auth). This is the correct, performant approach for a WordPress plugin.

File structure
woocommerce-ideaerp/
├── woocommerce-ideaerp.php              ← main plugin file (already exists)
│
├── src/
│   ├── Api/
│   │   ├── Client.php                   ← HTTP client (Bearer auth, error handling)
│   │   ├── Endpoints/
│   │   │   ├── ProductsEndpoint.php     ← GET /v2/products
│   │   │   ├── OrdersEndpoint.php       ← POST/PATCH /v2/orders
│   │   │   └── InvoicesEndpoint.php     ← GET /v2/invoices, GET /v2/invoices/{id}/get_pdf
│   │   └── DTO/
│   │       ├── ErpProduct.php
│   │       ├── ErpOrder.php
│   │       └── ErpInvoice.php
│   │
│   ├── Sync/
│   │   ├── Contracts/
│   │   │   └── SyncInterface.php        ← interface: sync()
│   │   ├── ProductImporter.php          ← Step 1: ERP → WooCommerce products
│   │   ├── OrderExporter.php            ← Step 2: WooCommerce order → ERP
│   │   ├── InvoiceImporter.php          ← Step 3: ERP invoice → WooCommerce order meta
│   │   ├── StockPriceSyncer.php         ← Step 4: scheduled stock + price update
│   │   └── OrderCancelHandler.php       ← Step 5: WC cancel → ERP cancel + stock restore
│   │
│   ├── Scheduler/
│   │   └── SyncScheduler.php            ← Action Scheduler registration & dispatch
│   │
│   ├── Admin/
│   │   ├── SettingsPage.php             ← existing settings + new options (trigger, interval)
│   │   └── ProductImportPage.php        ← Step 1 admin panel: list ERP products, import button
│   │
│   └── Helpers/
│       └── Logger.php                   ← WC_Logger wrapper
│
└── languages/
    └── woocommerce-ideaerp.pot
Data flow overview
WordPress / WooCommerce
Plugin Layer
IdeaERP REST API
"Select + Import"
"ErpProduct DTO"
"create/update"
"status change hook"
"erp_order_id"
"erp_order_id"
"invoice meta + PDF URL"
"dispatch"
"stock qty"
"woocommerce_order_status_cancelled"
"restore stock"
"Admin: Product Import Panel"
"WC Product\n(simple / variable)"
"WC Order"
"Order Meta\n(erp_order_id, invoice_*)"
"WC Stock"
"Action Scheduler\n(configurable interval)"
"Api/Client.php\n(Bearer token)"
"ProductImporter"
"OrderExporter"
"InvoiceImporter"
"StockPriceSyncer"
"OrderCancelHandler"
"GET /v2/products"
"POST /v2/orders"
"PATCH /v2/orders/{id}"
"GET /v2/invoices"
"POST /v2/products/updateIntegrationPrice"
Step-by-step implementation plan
Step 1 — Download products from ERP (Admin Import Panel)
API used: GET /v2/products (paginated: limit=100, offset)

What it does:

ProductsEndpoint::getAll() fetches all pages and returns ErpProduct[] DTOs
ErpProduct maps: id, default_code (SKU), name, list_price, tax_rate, weight, description, attributes, stock, images, is_bundle
ProductImporter::import(ErpProduct $product):
Looks up WC product by SKU (wc_get_product_id_by_sku($product->default_code))
If not found → creates new WC_Product_Simple or WC_Product_Variable based on attributes
If found → updates existing product
Stores _erp_product_id post meta for future reference
Handles variations: for each attribute combination creates a WC_Product_Variation
Downloads images via media_sideload_image()
ProductImportPage renders a WP admin table (AJAX-powered) listing all ERP products with checkboxes and an "Import selected" button
Import runs via admin-ajax.php action in batches to avoid timeouts
New settings fields: none for this step

Step 2 — Upload WooCommerce orders to ERP
API used: POST /v2/orders

What it does:

Hook: add_action('woocommerce_order_status_changed', [$this, 'handle'], 10, 4)
Trigger status is configurable in settings (default: processing)
Guard: skip if _erp_order_id meta already set (prevents double-push)
OrderExporter::export(WC_Order $order) builds CreateSaleOrder payload:
name = "WC-{$order->get_order_number()}"
partner / partner_invoice / partner_shipping from WC billing/shipping address → SaleOrderPartner
order_lines[] → each WC item maps to CreateSaleOrderLine using _erp_product_id meta on the product; falls back to default_code match
is_paid = true if WC order is paid
amount_paid = $order->get_total()
integration_id = WC order ID (string)
integration_type = "woocommerce"
delivery_price = WC shipping total
On success: saves _erp_order_id as order meta
On failure: logs via Logger, adds order note
New settings fields:

wideaerp_order_trigger_status — dropdown of WC order statuses
Step 3 — Download invoices from ERP into WooCommerce order
API used: GET /v2/invoices?order_id={erp_order_id}

What it does:

Hook: add_action('woocommerce_order_status_changed', [$this, 'handle'], 20, 4) — fires after Step 2 on the same status change, or separately on completed
InvoiceImporter::importForOrder(WC_Order $order):
Reads _erp_order_id from order meta; skips if missing
Calls InvoicesEndpoint::getByOrderId(int $erpOrderId) → ErpInvoice[]
For each invoice, stores as order meta:
_erp_invoice_{n}_id
_erp_invoice_{n}_number (e.g. FV/0001/05/2021)
_erp_invoice_{n}_date
_erp_invoice_{n}_amount_total
_erp_invoice_{n}_pdf_url = {ERP_URL}/v2/invoices/{id}/get_pdf (direct link, token-authenticated)
Adds an order note: "Invoice FV/0001/05/2021 received from IdeaERP"
Invoice data is displayed on the WC order admin screen via add_action('woocommerce_admin_order_data_after_order_details', ...)
Step 4 — Scheduled stock and price sync (Action Scheduler)
API used: GET /v2/products (stock from product.stock[]), POST /v2/products/updateIntegrationPrice

What it does:

SyncScheduler::register() on init:
Registers recurring Action Scheduler action wideaerp_sync_stock_prices
Interval read from wideaerp_sync_interval option (minutes, default 60)
On settings save, reschedules if interval changed
StockPriceSyncer::run() (called by the scheduled action):
Fetches all ERP products page by page
For each product: finds WC product by SKU
Stock: calls wc_update_product_stock($wc_product, $qty) where $qty = sum of stock[].quantity - stock[].reserved_quantity
Price: calls wp_update_post / $wc_product->set_regular_price() from list_price
Batches price updates back to ERP via POST /v2/products/updateIntegrationPrice (max 50 per request per API schema) using ProductIntegrationPrice with integration_type = "woocommerce" and default_code as identifier
Logs summary (X products updated, Y errors) via Logger
New settings fields:

wideaerp_sync_interval — number input (minutes)
Step 5 — Stock restore + ERP cancel on WooCommerce order cancellation
API used: PATCH /v2/orders/{order_id}

What it does:

Hook: add_action('woocommerce_order_status_cancelled', [$this, 'handle'], 10, 2)
OrderCancelHandler::handle(int $orderId, WC_Order $order):
WooCommerce stock restore: iterates $order->get_items(), calls wc_update_product_stock($product, $qty, 'increase') for each line item — WooCommerce normally handles this automatically via woocommerce_restock_refunded_items, but the handler ensures it explicitly
ERP notification: reads _erp_order_id meta; if present, calls OrdersEndpoint::cancel(int $erpOrderId) which sends PATCH /v2/orders/{order_id} with a cancellation status payload
Adds order note on success/failure
Settings page additions
The existing SettingsPage will gain two new fields in register_settings():

Option key	Label	Type
wideaerp_order_trigger_status	Order status that triggers ERP push	<select> of WC statuses
wideaerp_sync_interval	Stock/price sync interval (minutes)	<input type="number">
Key SOLID principles applied
SRP — each class does one thing: Client handles HTTP, ProductImporter handles product mapping, StockPriceSyncer handles scheduled sync
OCP — SyncInterface contract allows adding new sync types without modifying existing ones
LSP — all sync classes implement SyncInterface
ISP — ProductsEndpoint, OrdersEndpoint, InvoicesEndpoint are separate classes, not one fat API class
DIP — ProductImporter depends on ProductsEndpoint interface, not the concrete Client