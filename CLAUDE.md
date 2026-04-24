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
| `Frontend\VariationGallery` | `src/Frontend/VariationGallery.php` | Per-variation gallery swap on product pages |

DTOs in `src/Api/DTO/` are read-only data holders with no logic (ErpProduct, ErpOrder, ErpInvoice, etc.).

### 3-Step Sync Workflow

1. **Product import** — Admin manually loads ERP products via the Import Products tab, optionally groups variants using drag-drop UI, then imports selected products. Products land as WC drafts.
2. **Order export** — When an order reaches the configured trigger status (default: `processing`), `OrderExporter` POSTs a sale order to `/v2/orders` and stores the ERP order ID as `_erp_order_id` order meta.
3. **Invoice fetch** — `InvoiceImporter` checks for invoices immediately after export (priority 20 on the same hook) and every 10 minutes via Action Scheduler (`wideaerp_check_pending_invoices`), batch size 50.

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

## Conventions

- Use WooCommerce API functions (`wc_get_product()`, `wc_get_orders()`, etc.) — never direct DB queries.
- All user-facing output must be escaped (`esc_html`, `esc_attr`, `esc_url`).
- All AJAX handlers check nonces and `current_user_can('manage_options')` or `'manage_woocommerce'`.
- PDF downloads are proxied server-side so the Bearer token is never sent to the browser.
- Log errors via `Logger::error()` (wraps WC logger + debug.log); always include context that identifies the order or product.
- Avoid re-exporting orders: check for existing `_erp_order_id` before calling `OrderExporter`.


Act as a rigous, honest mentor. Do not default to agreement. Identify weakneses, blind spots, and flawed assumptions. Challenge ideas when needed. Be direct and clear, not harsh. Prioritize helping me improver over being agreeable. When you critique something, explain why and suggest a better alternative. Promote critical thinking. Do not flatter me. If you do not know the answer to a quesiton I ask, or if you can't perform the task I request, acknowledge that you cannot perform as requested and then suggest viable ways I could adjust my prompt to achieve my goal. Be precise and concise.