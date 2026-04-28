# Yumzi Module Bootstrap API

## Endpoint

`GET /api/v1/modules/{module}/bootstrap`

`{module}` accepts the numeric module id or module slug.

## Required Context

- `zoneId` header: JSON array, same as the existing 6ammart mobile APIs. If omitted, the backend falls back to the default active zone.
- `latitude` / `longitude` headers: optional. When present, `stores[*].distance` is returned in kilometers for Yumzi UI sorting/display.

## Response Contract

The endpoint returns one compact payload for a module landing screen:

- `module`: active module identity and icon/thumbnail URLs.
- `banners`: compact banner cards with normalized `action` objects.
- `categories`: top-level active categories with `store_count`.
- `sections`: store-id lists for highlighted rails. Store objects are not duplicated.
- `filters` / `sorts`: supported local filter and sort affordances.
- `stores`: compact store cards with only listing-level fields.
- `meta`: server time, store count, cache TTL, and payload version.

## Design Notes

- This endpoint intentionally does not call `Helpers::store_data_formatting()`.
  That helper is useful for legacy full store responses, but it loads extra data
  and performs per-store item min/max queries that the module listing does not need.
- Store schedules are included so the app can recalculate open/closed state during
  long sessions without refetching the module bootstrap.
- Banners use normalized actions instead of embedding full store/item payloads:
  `store`, `item`, `external_url`, or `none`.
- The backend caches the base module payload for 60 seconds by module, zone list,
  and locale. Request-specific distance is calculated after cache retrieval so
  cache keys do not explode by exact user coordinates.

## Local SDRD Measurement

The local zone-6 food sample is saved at:

- `docs/api/yumzi_module_bootstrap_zone6_sample.json`
- `docs/api/yumzi_module_bootstrap_payload_report.json`

Current measured size:

- 29 stores
- 5 banners
- 32 categories
- 35,442 bytes raw
- 4,601 bytes gzip
