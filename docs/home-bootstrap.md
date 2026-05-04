# Home Bootstrap API

## Endpoint

`GET /api/v1/home/bootstrap`

## Required Context

- `zoneId` header: JSON array, same as the existing mobile APIs. If omitted,
  the backend falls back to the default active zone.
- `latitude` / `longitude` headers: optional. When present,
  `recommended_stores[*].distance` is returned in kilometers.

## Response Contract

The endpoint returns one compact payload for the Yumzi home tab:

- `modules`: active non-parcel/non-rental modules available in the user's zone.
- `banners`: compact home banners with normalized `action` objects.
- `recommended_stores`: compact cross-module store cards for the home carousel.
- `meta`: server time, store count, cache TTL, and payload version.

The response intentionally avoids full store/item payloads. Store detail and
item detail screens should continue to call their detail endpoints after a tap.
Store offer badges use compact fields only: `has_discount` and `discount_label`.
For item-wise discounts, `discount_label` is derived from one grouped summary
query by `store_id` instead of loading item rows per store.
