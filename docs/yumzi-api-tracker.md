# Yumzi API Tracker

This document tracks Yumzi-specific backend additions made on top of the
6ammart admin/API codebase. Keep this file updated alongside each backend
commit.

## Backend Root

`/Users/mshafique/Desktop/codecanyon-36772112-6ammart-multivendor-food-grocery-ecommerce-parcel-pharmacy-delivery-app-with-admin-website/Admin panel new install V3.7`

## Current Yumzi Endpoints

| Endpoint | Controller | Service | Purpose |
| --- | --- | --- | --- |
| `GET /api/v1/home/bootstrap` | `app/Http/Controllers/Api/V1/HomeBootstrapController.php` | `app/Services/HomeBootstrapService.php` | One compact home payload: modules, banners, recommended stores, meta. |
| `GET /api/v1/modules/{module}/bootstrap` | `app/Http/Controllers/Api/V1/YumziModuleBootstrapController.php` | `app/Services/YumziModuleBootstrapService.php` | One compact module payload: module info, banners, categories, sections, filters, sorts, stores, meta. |
| `GET /api/v1/stores/{store}/bootstrap` | `app/Http/Controllers/Api/V1/StoreBootstrapController.php` | `app/Services/StoreBootstrapService.php` | One compact store-detail payload: store header, categories, menu items, recommended ids, meta. |

## Files Created

| File | Why |
| --- | --- |
| `app/Services/HomeBootstrapService.php` | Builds the compact home payload without using full 6ammart store formatting. |
| `app/Http/Controllers/Api/V1/HomeBootstrapController.php` | Thin controller for `GET /api/v1/home/bootstrap`. |
| `app/Services/YumziModuleBootstrapService.php` | Builds the compact module landing payload for local filtering/sorting in Yumzi. |
| `app/Http/Controllers/Api/V1/YumziModuleBootstrapController.php` | Thin controller for `GET /api/v1/modules/{module}/bootstrap`. |
| `app/Services/StoreBootstrapService.php` | Builds the compact store-detail payload for Yumzi store browsing without full product detail rows. |
| `app/Http/Controllers/Api/V1/StoreBootstrapController.php` | Thin controller for `GET /api/v1/stores/{store}/bootstrap`. |
| `docs/home-bootstrap.md` | Home bootstrap endpoint contract. |
| `docs/yumzi-module-bootstrap.md` | Module bootstrap endpoint contract and payload notes. |
| `docs/store-bootstrap.md` | Store bootstrap endpoint contract and payload notes. |
| `docs/yumzi-api-tracker.md` | Change tracker for Yumzi-specific backend work. |

## Files Changed

| File | Change |
| --- | --- |
| `routes/api/v1/api.php` | Added `home/bootstrap`, `modules/{module}/bootstrap`, and `stores/{store}/bootstrap` API routes. |
| `app/Http/Controllers/Api/V1/OrderController.php` | Order list endpoints now merge compact page-level item summaries (`details_count`, `item_count`, `item_names`) from one aggregate query instead of using relation count data only. |
| `docs/yumzi-module-bootstrap.md` | Updated module bootstrap path from `/api/v1/yumzi/modules/{module}/bootstrap` to `/api/v1/modules/{module}/bootstrap`. |

## Design Notes

- These APIs are Yumzi-native compact payloads, not direct copies of 6ammart's
  full listing endpoints.
- Listing cards only receive fields used by the app. Full store/item details
  should still be loaded from detail endpoints after the user taps something.
- Home bootstrap is cross-module and should not require the `moduleId` header.
- Module bootstrap is module-specific and accepts `{module}` as id or slug.
- Store schedules are included where open/closed state must remain calculable
  during a long app session.
- Store bootstrap keeps full checkout/business-rule store details on the
  existing `stores/details/{id}` endpoint. It only replaces the store browsing
  screen's previous multiple-request workaround.
- Order list rows should stay compact. Do not call order details per row and
  do not return full `details` arrays for the list screen. Use the aggregate
  summary fields for list labels and keep full detail rows for order details.
- Local image fallback currently points missing local files to
  `https://admin.sdrd.store/storage/app/public/...` for testing with the SDRD
  dataset.

## Verification Commands

```bash
php -l app/Services/HomeBootstrapService.php
php -l app/Http/Controllers/Api/V1/HomeBootstrapController.php
php -l app/Services/YumziModuleBootstrapService.php
php -l app/Http/Controllers/Api/V1/YumziModuleBootstrapController.php
php -l app/Services/StoreBootstrapService.php
php -l app/Http/Controllers/Api/V1/StoreBootstrapController.php
php -l app/Http/Controllers/Api/V1/OrderController.php
php -l routes/api/v1/api.php

curl -H 'zoneId: [6]' \
  -H 'latitude: 22.2102724' \
  -H 'longitude: 59.2041625' \
  http://127.0.0.1:8008/api/v1/home/bootstrap

curl -H 'zoneId: [6]' \
  -H 'latitude: 22.2102724' \
  -H 'longitude: 59.2041625' \
  http://127.0.0.1:8008/api/v1/modules/2/bootstrap

curl -H 'zoneId: [6]' \
  -H 'latitude: 22.2102724' \
  -H 'longitude: 59.2041625' \
  http://127.0.0.1:8008/api/v1/stores/29/bootstrap
```

## App Integration References

| App file | Role |
| --- | --- |
| `lib/core/utils/endpoints.dart` | Defines `homeBootstrap` and `moduleBootstrap`. |
| `lib/features/home/data/model/home_bootstrap_data.dart` | Parses home bootstrap response. |
| `lib/features/home/presentation/controller/home_controller.dart` | Loads home bootstrap and owns modules, banners, recommended stores. |
| `lib/features/module/data/model/module_data.dart` | Parses module bootstrap response. |
| `lib/features/module/presentation/controller/module_controller.dart` | Loads module bootstrap and applies local filtering/sorting. |
| `lib/features/store/data/model/store_detail.dart` | Parses store bootstrap response into the existing store detail model. |
| `lib/features/store/presentation/controller/store_detail_controller.dart` | Loads store bootstrap and indexes compact products by category locally. |

## Git Tracking

Backend changes are tracked in `https://github.com/shafiquecbl/yumzi_backend`.
Commit the API code and docs together so backend changes can be reviewed,
rolled back, and deployed intentionally.
