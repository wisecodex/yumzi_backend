# Store Bootstrap API

## Endpoint

`GET /api/v1/stores/{store}/bootstrap`

`{store}` accepts a store id or slug.

## Purpose

Return the data needed to render Yumzi's store detail screen in one request:

- Store header/detail fields already parsed by the Yumzi `StoreModel`
- Store categories used by the menu tabs
- Compact product rows used by the store menu/search UI
- Recommended item ids mapped back to the shared `items` list
- Request-specific distance/opening metadata

Cart and checkout should continue to use `GET /api/v1/stores/details/{id}` when
they need full checkout business rules. This bootstrap endpoint is scoped to the
customer store-detail browsing screen.

## Request Headers

| Header | Required | Notes |
| --- | --- | --- |
| `zoneId` | Recommended | JSON array, e.g. `[6]`. Falls back to the first active zone if absent. |
| `latitude` | Optional | Used only to calculate request-specific store distance. |
| `longitude` | Optional | Used only to calculate request-specific store distance. |

## Response Shape

```json
{
  "store": {},
  "categories": [],
  "items": [],
  "recommended_item_ids": [],
  "meta": {
    "total_items": 860,
    "recommended_count": 0,
    "cache_ttl_seconds": 60,
    "payload_version": 1,
    "server_time": "2026-04-28T07:02:30.022906Z"
  }
}
```

## Compact Item Fields

The endpoint intentionally keeps product rows narrow. Product detail still loads
the full item by id after the user taps a row.

| Field | Used for |
| --- | --- |
| `id`, `store_id`, `module_id` | Routing and cart/product-detail scope |
| `name`, `description`, `image_full_url`, `slug` | Store menu row/search surfaces |
| `price`, `discount`, `discount_type` | Product row price/discount display |
| `avg_rating`, `rating_count` | Existing product model rating getters |
| `stock` | Existing product model stock getter |
| `category_id`, `category_ids` | Local category indexing |

`category_ids` keeps the existing 6ammart object shape so Yumzi's
`ProductModel.parentCategoryIds` getter continues to work.

## Caching

The base payload is cached for 60 seconds by store id and locale. Distance,
open status, current opening time, and `server_time` are added per request after
the cached base payload is read.

## Local Smoke Check

```bash
curl -H 'zoneId: [6]' \
  -H 'latitude: 22.2102724' \
  -H 'longitude: 59.2041625' \
  http://127.0.0.1:8008/api/v1/stores/29/bootstrap
```

With the SDRD zone 6 dataset, store `29` currently returns 860 compact items and
23 categories. The sample response was about 586 KB raw and 69 KB gzipped.
