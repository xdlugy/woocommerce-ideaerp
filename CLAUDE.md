# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

WooCommerce IdeaERP Integration — a WordPress plugin that syncs products, orders, and invoices between WooCommerce and the IdeaERP REST API. No build system or external Composer dependencies; pure PHP with WordPress/WooCommerce APIs only.

**Requirements:** PHP 8.0+, WordPress 6.0+, WooCommerce 7.0+

## Architecture

### Plugin Bootstrap

Entry point: `woocommerce-ideaerp.php` → registers PSR-4 autoloader → `plugins_loaded` fires `wideaerp_init()` → instantiates singleton `WooCommerce_IdeaERP::instance()` → `boot()` registers all component hooks.

All classes live under the `WooIdeaERP\` namespace (mapped to `src/`).

### Core Components

| Class | File | Responsibility |
|-------|------|----------------|
| `Api\Client` | `src/Api/Client.php` | HTTP wrapper (wp_remote_*) with Bearer token auth |
| `Admin\SettingsPage` | `src/Admin/SettingsPage.php` | Tabbed settings UI + AJAX handlers for mapping tables |
| `Admin\ProductImportPage` | `src/Admin/ProductImportPage.php` | Product import UI with drag-drop variant grouping |
| `Sync\ProductImporter` | `src/Sync/ProductImporter.php` | Creates/updates WC simple and variable products from ERP |
| `Sync\OrderExporter` | `src/Sync/OrderExporter.php` | Exports WC orders to ERP sale orders on status change |
| `Sync\InvoiceImporter` | `src/Sync/InvoiceImporter.php` | Fetches ERP invoices, stores as order meta, proxies PDFs |
| `Sync\StockPriceSyncer` | `src/Sync/StockPriceSyncer.php` | Scheduled stock and price sync from ERP to WC |
| `Frontend\VariationGallery` | `src/Frontend/VariationGallery.php` | Per-variation gallery swap on product pages |

DTOs in `src/Api/DTO/` are read-only data holders with no logic (ErpProduct, ErpOrder, ErpInvoice, etc.).

### Sync Workflow

1. **Product import** — Admin manually loads ERP products via the Import Products tab, optionally groups variants using drag-drop UI, then imports selected products. Products land as WC drafts.
2. **Order export** — When an order reaches the configured trigger status (default: `processing`), `OrderExporter` POSTs a sale order to `/v2/orders` and stores the ERP order ID as `_erp_order_id` order meta.
3. **Invoice fetch** — `InvoiceImporter` checks for invoices immediately after export (priority 20 on the same hook) and every 10 minutes via Action Scheduler (`wideaerp_check_pending_invoices`), batch size 50.
4. **Stock & price sync** — `StockPriceSyncer` runs two independent scheduled coordinators (`wideaerp_sync_stock`, `wideaerp_sync_price`). Each coordinator calls `ProductsEndpoint::get_all()` once, stores a flat `erp_id => float` transient, then dispatches staggered one-time batch jobs. Batch jobs do zero ERP API calls — they read the transient, find matching WC products via a single `get_posts()` with `meta_query IN`, and call `save()` only when the value changed. Sync is ERP → WC only (pull).

### API Communication

All requests go through `Api\Client`. Responses that return HTTP 3xx/4xx/5xx throw `\RuntimeException` with the ERP error message. Pagination uses `limit`/`offset` query params; endpoints enumerate all pages until `count >= total_count`.

IdeaERP endpoints used: `/v2/products`, `/v2/orders`, `/v2/invoices`, `/v2/invoices/{id}/get_pdf`, `/v2/payment_methods`, `/v2/pricelists`, `/v2/shipment/shipment_carriers`.

## Key WordPress Options

| Option | Description |
|--------|-------------|
| `wideaerp_erp_url` | ERP API base URL |
| `wideaerp_api_token` | Bearer token (never expose client-side) |
| `wideaerp_shop_id` | Shop ID/name in IdeaERP |
| `wideaerp_integration_config` | Integration config ID |
| `wideaerp_order_trigger_status` | Order status that triggers export (default: `processing`) |
| `wideaerp_payment_method_map` | `[ wc_gateway_id => erp_method_id ]` |
| `wideaerp_pricelist_map` | `[ currency_code => erp_pricelist_id ]` |
| `wideaerp_carrier_map` | `[ wc_shipping_method_id => erp_carrier_id ]` |
| `wideaerp_stock_sync_interval` | Minutes between stock sync coordinator runs (default 60) |
| `wideaerp_price_sync_interval` | Minutes between price sync coordinator runs (default 60) |
| `wideaerp_sync_batch_size` | Products per batch job (default 100) |
| `wideaerp_sync_batch_delay` | Seconds between dispatched batch jobs (default 30) |

## Key Post Meta Keys

**Orders:**
- `_erp_order_id` — ERP sale order ID (guards against re-export when present)
- `_erp_invoice_pending` — `1` means invoice fetch is still needed
- `_erp_invoice_count` — number of invoices stored
- `_erp_invoice_{n}_id/number/date/date_due/amount_total/currency/state/pdf_url`

**Products/Variations:**
- `_erp_product_id` — ERP product ID (simple products)
- `_erp_product_tmpl_id` — ERP product template ID (variable parent)
- `_global_unique_id` — ERP barcode/GTIN
- `_variation_gallery_ids` — comma-separated attachment IDs per variation

**Attachments:**
- `_wideaerp_source_url` — original image URL (used to skip re-downloading)

**Transients (StockPriceSyncer):**
- `wideaerp_stock_sync_data` — `array<int, float>` (erp_id → available_qty), TTL = 2× stock interval
- `wideaerp_price_sync_data` — `array<int, float>` (erp_id → list_price), TTL = 2× price interval

## Conventions

- Use WooCommerce API functions (`wc_get_product()`, `wc_get_orders()`, etc.) — never direct DB queries.
- All user-facing output must be escaped (`esc_html`, `esc_attr`, `esc_url`).
- All AJAX handlers check nonces and `current_user_can('manage_options')` or `'manage_woocommerce'`.
- PDF downloads are proxied server-side so the Bearer token is never sent to the browser.
- Log errors via `Logger::error()` (wraps WC logger + debug.log); always include context that identifies the order or product.
- Avoid re-exporting orders: check for existing `_erp_order_id` before calling `OrderExporter`.
- Sync is ERP → WC only (pull). Never push stock/price back to ERP.
- In batch jobs, always guard with `if (value === current_wc_value) continue` before calling `$wc->save()` — unconditional saves on thousands of products cause significant DB load.
- `StockPriceSyncer` action intervals reschedule automatically via `update_option_{option}` hooks when settings are saved; no manual rescheduling needed.


Act as a rigous, honest mentor. Do not default to agreement. Identify weakneses, blind spots, and flawed assumptions. Challenge ideas when needed. Be direct and clear, not harsh. Prioritize helping me improver over being agreeable. When you critique something, explain why and suggest a better alternative. Promote critical thinking. Do not flatter me. If you do not know the answer to a quesiton I ask, or if you can't perform the task I request, acknowledge that you cannot perform as requested and then suggest viable ways I could adjust my prompt to achieve my goal. Be precise and concise.