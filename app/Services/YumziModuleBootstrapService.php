<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Module;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class YumziModuleBootstrapService
{
    private const CACHE_TTL_SECONDS = 60;
    private const SECTION_STORE_LIMIT = 20;

    public function bootstrap(Request $request, string $moduleKey): array
    {
        Helpers::setZoneIds($request);

        $zoneIds = $this->zoneIdsFromRequest($request);
        $module = $this->module($moduleKey);

        if (! $module) {
            return [
                'status' => 403,
                'body' => $this->error('moduleId', translate('messages.not_found')),
            ];
        }

        if (! $this->moduleAvailableForZones((int) $module->id, $zoneIds)) {
            return [
                'status' => 403,
                'body' => $this->error('moduleId', translate('Currently this module is available')),
            ];
        }

        $latitude = $this->numberFromHeader($request, 'latitude');
        $longitude = $this->numberFromHeader($request, 'longitude');
        $cacheKey = $this->cacheKey((int) $module->id, $zoneIds);

        $base = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => $this->basePayload($module, $zoneIds)
        );

        $stores = $this->storesWithRequestData($base['stores'], $latitude, $longitude);
        $sections = $this->sections($stores);

        $popularIds = $sections['popular'] ?? [];
        $stores = array_map(function (array $store) use ($popularIds): array {
            $store['is_popular'] = in_array($store['id'], $popularIds, true);

            return $store;
        }, $stores);

        return [
            'status' => 200,
            'body' => [
                'module' => array_merge($base['module'], [
                    'stores_count' => count($stores),
                ]),
                'banners' => $base['banners'],
                'categories' => $base['categories'],
                'sections' => $this->formatSections($sections),
                'filters' => $this->filters(),
                'sorts' => $this->sorts(),
                'stores' => $stores,
                'meta' => [
                    'server_time' => now()->toJSON(),
                    'store_count' => count($stores),
                    'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
                    'payload_version' => 1,
                ],
            ],
        ];
    }

    private function basePayload(object $module, array $zoneIds): array
    {
        $stores = $this->baseStores($module, $zoneIds);
        $storeIds = array_column($stores, 'id');
        $itemData = $this->storeItemData($storeIds, (int) $module->id);
        $categories = $this->categories((int) $module->id, $itemData['category_store_counts']);
        $banners = $this->banners((int) $module->id, $zoneIds, $storeIds);

        return [
            'module' => $this->formatModule($module),
            'banners' => $banners,
            'categories' => $categories,
            'stores' => $this->attachStoreSupportData($stores, $itemData),
        ];
    }

    private function baseStores(object $module, array $zoneIds): array
    {
        $stores = DB::table('stores')
            ->select([
                'stores.id',
                'stores.module_id',
                'stores.zone_id',
                'stores.name',
                'stores.logo',
                'stores.latitude',
                'stores.longitude',
                'stores.delivery_time',
                'stores.free_delivery',
                'stores.rating',
                'stores.active',
                'stores.featured',
                'stores.total_order',
                'stores.order_count',
                'stores.slug',
                'stores.created_at',
            ])
            ->where('stores.module_id', $module->id)
            ->where('stores.status', 1)
            ->where(function ($query): void {
                $query->where('stores.store_business_model', 'commission')
                    ->orWhereExists(function ($subQuery): void {
                        $subQuery->select(DB::raw(1))
                            ->from('store_subscriptions')
                            ->whereColumn('store_subscriptions.store_id', 'stores.id')
                            ->where('store_subscriptions.status', 1)
                            ->where(function ($subscription): void {
                                $subscription->where('store_subscriptions.max_order', 'unlimited')
                                    ->orWhere('store_subscriptions.max_order', '>', 0);
                            });
                    });
            })
            ->whereExists(function ($query) use ($module): void {
                $query->select(DB::raw(1))
                    ->from('module_zone')
                    ->whereColumn('module_zone.zone_id', 'stores.zone_id')
                    ->where('module_zone.module_id', $module->id);
            })
            ->when(! (bool) $module->all_zone_service, function ($query) use ($zoneIds): void {
                $query->whereIn('stores.zone_id', $zoneIds);
            })
            ->orderByDesc('stores.featured')
            ->orderByDesc('stores.total_order')
            ->orderBy('stores.name')
            ->get();

        $storeIds = $stores->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $nameTranslations = $this->translations(Store::class, $storeIds, ['name']);
        $storage = $this->storage(Store::class, $storeIds, ['logo']);
        $schedules = $this->storeSchedules($storeIds);
        $discounts = $this->storeDiscounts($storeIds);
        $configs = $this->storeConfigs($storeIds);

        return $stores->map(function (object $store) use ($nameTranslations, $storage, $schedules, $discounts, $configs): array {
            $storeId = (int) $store->id;
            $rating = $this->rating($store->rating);
            $discount = $discounts[$storeId] ?? null;

            return [
                'id' => $storeId,
                'module_id' => (int) $store->module_id,
                'zone_id' => (int) $store->zone_id,
                'name' => $this->translatedValue($store->name, $nameTranslations, $storeId, 'name'),
                'logo' => $store->logo,
                'logo_full_url' => $this->imageUrl('store', $store->logo, $storage[$storeId]['logo'] ?? 'public'),
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'delivery_time' => $store->delivery_time,
                'min_delivery_time' => $this->minDeliveryMinutes($store->delivery_time),
                'free_delivery' => (bool) $store->free_delivery,
                'ratings' => $rating['breakdown'],
                'avg_rating' => $rating['average'],
                'rating_count' => $rating['count'],
                'active' => (bool) $store->active,
                'featured' => (int) $store->featured,
                'total_order' => max((int) $store->total_order, (int) $store->order_count),
                'slug' => $store->slug,
                'created_at' => $store->created_at,
                'schedules' => $schedules[$storeId] ?? [],
                'discount' => $this->formatDiscount($discount),
                'discount_label' => $this->discountLabel($discount),
                'is_recommended' => $this->isRecommended($configs[$storeId] ?? null),
            ];
        })->all();
    }

    private function attachStoreSupportData(array $stores, array $itemData): array
    {
        $categoryIdsByStore = $itemData['category_ids_by_store'];
        $itemDiscountSummaries = $itemData['item_discount_summaries'];

        return array_map(function (array $store) use ($categoryIdsByStore, $itemDiscountSummaries): array {
            $storeId = $store['id'];
            $itemDiscountSummary = $itemDiscountSummaries[$storeId] ?? null;

            $store['category_ids'] = $categoryIdsByStore[$storeId] ?? [];
            $store['cuisine_ids'] = [];

            if ($store['discount'] === null && $itemDiscountSummary) {
                $store['discount_label'] = $itemDiscountSummary['discount_label'];
            }

            $store['has_discount'] = $store['discount'] !== null || $itemDiscountSummary !== null;
            $store['is_new'] = $this->isNewStore($store['created_at']);

            unset($store['created_at'], $store['logo']);

            return $store;
        }, $stores);
    }

    private function storesWithRequestData(array $stores, ?float $latitude, ?float $longitude): array
    {
        return array_map(function (array $store) use ($latitude, $longitude): array {
            $store['distance'] = $this->distanceKm($latitude, $longitude, $store['latitude'], $store['longitude']);
            $store['open'] = $this->isOpenNow((bool) $store['active'], $store['schedules']) ? 1 : 0;
            $store['current_opening_time'] = $this->currentOpeningTime($store['schedules']);

            return $store;
        }, $stores);
    }

    private function categories(int $moduleId, array $categoryStoreCounts): array
    {
        $categories = DB::table('categories')
            ->select(['id', 'name', 'image', 'slug'])
            ->where('module_id', $moduleId)
            ->where('position', 0)
            ->where('status', 1)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $categoryIds = $categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $translations = $this->translations(Category::class, $categoryIds, ['name']);
        $storage = $this->storage(Category::class, $categoryIds, ['image']);

        return $categories->map(function (object $category) use ($translations, $storage, $categoryStoreCounts): array {
            $categoryId = (int) $category->id;

            return [
                'id' => $categoryId,
                'name' => $this->translatedValue($category->name, $translations, $categoryId, 'name'),
                'image_full_url' => $this->imageUrl('category', $category->image, $storage[$categoryId]['image'] ?? 'public'),
                'slug' => $category->slug,
                'store_count' => $categoryStoreCounts[$categoryId] ?? 0,
            ];
        })->all();
    }

    private function banners(int $moduleId, array $zoneIds, array $storeIds): array
    {
        $banners = DB::table('banners')
            ->select(['id', 'title', 'type', 'image', 'data', 'default_link', 'module_id'])
            ->where('status', 1)
            ->where('created_by', 'admin')
            ->where('module_id', $moduleId)
            ->where(function ($query) use ($zoneIds): void {
                $query->where(function ($zoneQuery) use ($zoneIds): void {
                    $zoneQuery->whereIn('type', ['store_wise', 'item_wise'])
                        ->whereIn('zone_id', $zoneIds);
                })->orWhere('type', 'default');
            })
            ->orderByDesc('featured')
            ->latest('id')
            ->get();

        $bannerIds = $banners->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $itemIds = $banners
            ->filter(fn (object $banner): bool => $banner->type === 'item_wise')
            ->pluck('data')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        $validStoreIds = array_fill_keys($storeIds, true);
        $validItemIds = $this->validBannerItemIds($itemIds, $storeIds, $moduleId);
        $translations = $this->translations(Banner::class, $bannerIds, ['title']);
        $storage = $this->storage(Banner::class, $bannerIds, ['image']);

        return $banners->map(function (object $banner) use ($translations, $storage, $validStoreIds, $validItemIds): ?array {
            $bannerId = (int) $banner->id;
            $action = $this->bannerAction($banner, $validStoreIds, $validItemIds);

            if ($action === null) {
                return null;
            }

            return [
                'id' => $bannerId,
                'title' => $this->translatedValue($banner->title, $translations, $bannerId, 'title'),
                'image_full_url' => $this->imageUrl('banner', $banner->image, $storage[$bannerId]['image'] ?? 'public'),
                'action' => $action,
            ];
        })->filter()->values()->all();
    }

    private function storeItemData(array $storeIds, int $moduleId): array
    {
        if (empty($storeIds)) {
            return [
                'category_ids_by_store' => [],
                'category_store_counts' => [],
                'item_discount_summaries' => [],
            ];
        }

        $items = DB::table('items')
            ->select(['store_id', 'category_id', 'category_ids', 'discount', 'discount_type'])
            ->whereIn('store_id', $storeIds)
            ->where('module_id', $moduleId)
            ->where('status', 1)
            ->where('is_approved', 1)
            ->get();

        $categoryIdsByStore = [];
        $categoryStoreIds = [];
        $itemDiscountsByStore = [];

        foreach ($items as $item) {
            $storeId = (int) $item->store_id;
            $categoryIds = $this->categoryIdsFromItem($item->category_ids, $item->category_id);

            $categoryIdsByStore[$storeId] = array_values(array_unique(array_merge($categoryIdsByStore[$storeId] ?? [], $categoryIds)));

            foreach ($categoryIds as $categoryId) {
                $categoryStoreIds[$categoryId][$storeId] = true;
            }

            if ((float) $item->discount > 0) {
                $this->captureItemDiscount($itemDiscountsByStore, $storeId, (float) $item->discount, $item->discount_type ?: 'percent');
            }
        }

        return [
            'category_ids_by_store' => $categoryIdsByStore,
            'category_store_counts' => array_map('count', $categoryStoreIds),
            'item_discount_summaries' => $this->formatItemDiscountSummaries($itemDiscountsByStore),
        ];
    }

    private function storeSchedules(array $storeIds): array
    {
        if (empty($storeIds)) {
            return [];
        }

        return DB::table('store_schedule')
            ->select(['store_id', 'day', 'opening_time', 'closing_time'])
            ->whereIn('store_id', $storeIds)
            ->orderBy('day')
            ->orderBy('opening_time')
            ->get()
            ->groupBy('store_id')
            ->map(function (Collection $rows): array {
                return $rows->map(fn (object $schedule): array => [
                    'day' => (int) $schedule->day,
                    'opening_time' => $this->timeString($schedule->opening_time),
                    'closing_time' => $this->timeString($schedule->closing_time),
                ])->all();
            })->all();
    }

    private function storeDiscounts(array $storeIds): array
    {
        if (empty($storeIds)) {
            return [];
        }

        return DB::table('discounts')
            ->select(['store_id', 'discount', 'discount_type', 'min_purchase', 'max_discount', 'start_date', 'end_date'])
            ->whereIn('store_id', $storeIds)
            ->where('discount', '>', 0)
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->whereTime('start_time', '<=', now()->format('H:i:s'))
            ->whereTime('end_time', '>=', now()->format('H:i:s'))
            ->get()
            ->keyBy('store_id')
            ->all();
    }

    private function storeConfigs(array $storeIds): array
    {
        if (empty($storeIds)) {
            return [];
        }

        return DB::table('store_configs')
            ->select(['store_id', 'is_recommended', 'is_recommended_deleted'])
            ->whereIn('store_id', $storeIds)
            ->get()
            ->keyBy('store_id')
            ->all();
    }

    private function module(string $moduleKey): ?object
    {
        $query = DB::table('modules')
            ->select(['id', 'module_name', 'module_type', 'icon', 'thumbnail', 'all_zone_service', 'slug'])
            ->where('status', 1);

        if (is_numeric($moduleKey)) {
            $query->where('id', (int) $moduleKey);
        } else {
            $query->where('slug', $moduleKey);
        }

        return $query->first();
    }

    private function formatModule(object $module): array
    {
        $translations = $this->translations(Module::class, [(int) $module->id], ['module_name']);
        $storage = $this->storage(Module::class, [(int) $module->id], ['icon', 'thumbnail']);

        return [
            'id' => (int) $module->id,
            'name' => $this->translatedValue($module->module_name, $translations, (int) $module->id, 'module_name'),
            'module_type' => $module->module_type,
            'icon_full_url' => $this->imageUrl('module', $module->icon, $storage[(int) $module->id]['icon'] ?? 'public'),
            'thumbnail_full_url' => $this->imageUrl('module', $module->thumbnail, $storage[(int) $module->id]['thumbnail'] ?? 'public'),
            'slug' => $module->slug,
        ];
    }

    private function moduleAvailableForZones(int $moduleId, array $zoneIds): bool
    {
        if (empty($zoneIds)) {
            return false;
        }

        return DB::table('module_zone')
            ->where('module_id', $moduleId)
            ->whereIn('zone_id', $zoneIds)
            ->exists();
    }

    private function validBannerItemIds(array $itemIds, array $storeIds, int $moduleId): array
    {
        if (empty($itemIds) || empty($storeIds)) {
            return [];
        }

        return DB::table('items')
            ->whereIn('id', $itemIds)
            ->whereIn('store_id', $storeIds)
            ->where('module_id', $moduleId)
            ->where('status', 1)
            ->where('is_approved', 1)
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    private function bannerAction(object $banner, array $validStoreIds, array $validItemIds): ?array
    {
        $dataId = (int) $banner->data;

        if ($banner->type === 'store_wise') {
            return isset($validStoreIds[$dataId]) ? [
                'type' => 'store',
                'id' => $dataId,
                'module_id' => (int) $banner->module_id,
            ] : null;
        }

        if ($banner->type === 'item_wise') {
            return isset($validItemIds[$dataId]) ? [
                'type' => 'item',
                'id' => $dataId,
                'module_id' => (int) $banner->module_id,
            ] : null;
        }

        if ($banner->type === 'default' && filled($banner->default_link)) {
            return [
                'type' => 'external_url',
                'url' => $banner->default_link,
            ];
        }

        return ['type' => 'none'];
    }

    private function sections(array $stores): array
    {
        return [
            'offers' => $this->sectionStoreIds(
                array_filter($stores, fn (array $store): bool => $store['has_discount'] || $store['free_delivery']),
                fn (array $store): array => [
                    -$store['open'],
                    $store['distance'] ?? PHP_FLOAT_MAX,
                    -$store['avg_rating'],
                ]
            ),
            'popular' => $this->sectionStoreIds(
                $stores,
                fn (array $store): array => [
                    -$store['total_order'],
                    -$store['avg_rating'],
                    $store['distance'] ?? PHP_FLOAT_MAX,
                ]
            ),
            'top_rated' => $this->sectionStoreIds(
                array_filter($stores, fn (array $store): bool => $store['rating_count'] > 0),
                fn (array $store): array => [
                    -$store['avg_rating'],
                    -$store['rating_count'],
                    $store['distance'] ?? PHP_FLOAT_MAX,
                ]
            ),
            'nearby' => $this->sectionStoreIds(
                array_filter($stores, fn (array $store): bool => $store['distance'] !== null),
                fn (array $store): array => [
                    $store['distance'],
                    -$store['open'],
                    -$store['avg_rating'],
                ]
            ),
        ];
    }

    private function sectionStoreIds(array $stores, callable $sortKey): array
    {
        usort($stores, function (array $left, array $right) use ($sortKey): int {
            return $sortKey($left) <=> $sortKey($right);
        });

        return array_map(
            fn (array $store): int => $store['id'],
            array_slice(array_values($stores), 0, self::SECTION_STORE_LIMIT)
        );
    }

    private function formatSections(array $sections): array
    {
        return [
            [
                'id' => 'offers',
                'title' => 'Offers near you',
                'store_ids' => $sections['offers'],
            ],
            [
                'id' => 'popular',
                'title' => 'Popular nearby',
                'store_ids' => $sections['popular'],
            ],
            [
                'id' => 'top_rated',
                'title' => 'Top rated',
                'store_ids' => $sections['top_rated'],
            ],
            [
                'id' => 'nearby',
                'title' => 'Closest to you',
                'store_ids' => $sections['nearby'],
            ],
        ];
    }

    private function filters(): array
    {
        return [
            ['id' => 'offers', 'label' => 'Offers', 'type' => 'toggle'],
            ['id' => 'fast_delivery', 'label' => 'Fast delivery', 'type' => 'toggle'],
            ['id' => 'open_now', 'label' => 'Open now', 'type' => 'toggle'],
            ['id' => 'top_rated', 'label' => 'Top rated', 'type' => 'toggle'],
            ['id' => 'free_delivery', 'label' => 'Free delivery', 'type' => 'toggle'],
        ];
    }

    private function sorts(): array
    {
        return [
            ['id' => 'recommended', 'label' => 'Recommended'],
            ['id' => 'delivery_time', 'label' => 'Delivery time'],
            ['id' => 'rating', 'label' => 'Rating'],
            ['id' => 'distance', 'label' => 'Distance'],
        ];
    }

    private function translations(string $model, array $ids, array $keys): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table('translations')
            ->select(['translationable_id', 'key', 'value'])
            ->where('translationable_type', $model)
            ->whereIn('translationable_id', $ids)
            ->where('locale', app()->getLocale())
            ->whereIn('key', $keys)
            ->get()
            ->groupBy('translationable_id')
            ->map(fn (Collection $rows): array => $rows->keyBy('key')->map(fn (object $row): string => $row->value)->all())
            ->all();
    }

    private function storage(string $model, array $ids, array $keys): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table('storages')
            ->select(['data_id', 'key', 'value'])
            ->where('data_type', $model)
            ->whereIn('data_id', $ids)
            ->whereIn('key', $keys)
            ->get()
            ->groupBy('data_id')
            ->map(fn (Collection $rows): array => $rows->keyBy('key')->map(fn (object $row): string => $row->value)->all())
            ->all();
    }

    private function translatedValue(?string $fallback, array $translations, int $id, string $key): ?string
    {
        return $translations[$id][$key] ?? $fallback;
    }

    private function imageUrl(string $path, ?string $file, string $disk): ?string
    {
        $url = Helpers::get_full_url($path, $file, $disk ?: 'public');

        if ($url !== null || ! $file) {
            return $url;
        }

        return 'https://admin.sdrd.store/storage/app/public/' . trim($path, '/') . '/' . ltrim($file, '/');
    }

    private function rating(?string $rawRating): array
    {
        $decoded = $rawRating ? json_decode($rawRating, true) : [];

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $breakdown = [
            (int) ($decoded[1] ?? $decoded['1'] ?? 0),
            (int) ($decoded[2] ?? $decoded['2'] ?? 0),
            (int) ($decoded[3] ?? $decoded['3'] ?? 0),
            (int) ($decoded[4] ?? $decoded['4'] ?? 0),
            (int) ($decoded[5] ?? $decoded['5'] ?? 0),
        ];

        $count = array_sum($breakdown);
        $average = $count > 0
            ? round((($breakdown[0] * 1) + ($breakdown[1] * 2) + ($breakdown[2] * 3) + ($breakdown[3] * 4) + ($breakdown[4] * 5)) / $count, 2)
            : 0;

        return [
            'breakdown' => $breakdown,
            'average' => $average,
            'count' => $count,
        ];
    }

    private function categoryIdsFromItem(?string $rawCategoryIds, ?int $fallbackCategoryId): array
    {
        $ids = [];
        $decoded = $rawCategoryIds ? json_decode($rawCategoryIds, true) : null;

        if (is_array($decoded)) {
            foreach ($decoded as $category) {
                if (is_array($category) && isset($category['id'])) {
                    $ids[] = (int) $category['id'];
                } elseif (is_numeric($category)) {
                    $ids[] = (int) $category;
                }
            }
        }

        if (empty($ids) && $fallbackCategoryId) {
            $ids[] = (int) $fallbackCategoryId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function formatDiscount(?object $discount): ?array
    {
        if (! $discount) {
            return null;
        }

        return [
            'discount' => (float) $discount->discount,
            'discount_type' => $discount->discount_type,
            'min_purchase' => (float) $discount->min_purchase,
            'max_discount' => (float) $discount->max_discount,
            'start_date' => $discount->start_date,
            'end_date' => $discount->end_date,
        ];
    }

    private function discountLabel(?object $discount): ?string
    {
        if (! $discount || (float) $discount->discount <= 0) {
            return null;
        }

        $value = rtrim(rtrim(number_format((float) $discount->discount, 2, '.', ''), '0'), '.');

        return $discount->discount_type === 'percent' ? "{$value}% off" : "{$value} off";
    }

    private function captureItemDiscount(array &$summaries, int $storeId, float $discount, string $discountType): void
    {
        $summaries[$storeId] ??= [
            'max_percent_discount' => 0,
            'max_amount_discount' => 0,
        ];

        if ($discountType === 'percent') {
            $summaries[$storeId]['max_percent_discount'] = max($summaries[$storeId]['max_percent_discount'], $discount);

            return;
        }

        $summaries[$storeId]['max_amount_discount'] = max($summaries[$storeId]['max_amount_discount'], $discount);
    }

    private function formatItemDiscountSummaries(array $summaries): array
    {
        $formatted = [];

        foreach ($summaries as $storeId => $summary) {
            $itemDiscountSummary = $this->formatItemDiscountSummary($summary);

            if ($itemDiscountSummary) {
                $formatted[(int) $storeId] = $itemDiscountSummary;
            }
        }

        return $formatted;
    }

    private function formatItemDiscountSummary(array $summary): ?array
    {
        $maxPercentDiscount = (float) ($summary['max_percent_discount'] ?? 0);
        $maxAmountDiscount = (float) ($summary['max_amount_discount'] ?? 0);

        if ($maxPercentDiscount > 0) {
            return [
                'discount' => $maxPercentDiscount,
                'discount_type' => 'percent',
                'discount_label' => 'Up to ' . $this->discountValue($maxPercentDiscount) . '% off',
            ];
        }

        if ($maxAmountDiscount > 0) {
            return [
                'discount' => $maxAmountDiscount,
                'discount_type' => 'amount',
                'discount_label' => 'Up to ' . $this->discountValue($maxAmountDiscount) . ' off',
            ];
        }

        return null;
    }

    private function discountValue(float $discount): string
    {
        return rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.');
    }

    private function minDeliveryMinutes(?string $deliveryTime): int
    {
        if (! $deliveryTime) {
            return 9999;
        }

        preg_match('/\d+/', $deliveryTime, $matches);

        if (empty($matches)) {
            return 9999;
        }

        $value = (int) $matches[0];

        return str_contains(strtolower($deliveryTime), 'hour') ? $value * 60 : $value;
    }

    private function isRecommended(?object $config): bool
    {
        return $config !== null
            && (int) $config->is_recommended_deleted === 0
            && (int) $config->is_recommended === 1;
    }

    private function isNewStore(?string $createdAt): bool
    {
        return $createdAt !== null && now()->diffInDays($createdAt) <= 30;
    }

    private function isOpenNow(bool $active, array $schedules): bool
    {
        if (! $active) {
            return false;
        }

        if (empty($schedules)) {
            return true;
        }

        $today = (int) now()->dayOfWeek;
        $now = now()->format('H:i:s');

        foreach ($schedules as $schedule) {
            if ((int) $schedule['day'] !== $today) {
                continue;
            }

            if ($schedule['opening_time'] < $now && $schedule['closing_time'] > $now) {
                return true;
            }
        }

        return false;
    }

    private function currentOpeningTime(array $schedules): ?string
    {
        if (empty($schedules)) {
            return null;
        }

        $today = (int) now()->dayOfWeek;
        $now = now()->format('H:i:s');

        foreach ($schedules as $schedule) {
            if ((int) $schedule['day'] !== $today) {
                continue;
            }

            if ($now >= $schedule['opening_time'] && $now <= $schedule['closing_time']) {
                return $schedule['opening_time'];
            }

            if ($now < $schedule['opening_time']) {
                return $schedule['opening_time'];
            }
        }

        return 'closed';
    }

    private function distanceKm(?float $latitude, ?float $longitude, mixed $storeLatitude, mixed $storeLongitude): ?float
    {
        if ($latitude === null || $longitude === null || ! is_numeric($storeLatitude) || ! is_numeric($storeLongitude)) {
            return null;
        }

        $storeLatitude = (float) $storeLatitude;
        $storeLongitude = (float) $storeLongitude;

        $earthRadiusKm = 6371;
        $latDistance = deg2rad($storeLatitude - $latitude);
        $lonDistance = deg2rad($storeLongitude - $longitude);
        $a = sin($latDistance / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($storeLatitude)) * sin($lonDistance / 2) ** 2;
        $distance = 2 * $earthRadiusKm * atan2(sqrt($a), sqrt(1 - $a));

        return round($distance, 2);
    }

    private function timeString(?string $time): string
    {
        return $time ? substr($time, 0, 8) : '00:00:00';
    }

    private function numberFromHeader(Request $request, string $header): ?float
    {
        $value = $request->header($header);

        return is_numeric($value) ? (float) $value : null;
    }

    private function zoneIdsFromRequest(Request $request): array
    {
        $raw = $request->header('zoneId');
        $decoded = json_decode($raw, true);
        $zoneIds = is_array($decoded) ? $decoded : [$decoded ?? $raw];

        return array_values(array_unique(array_filter(array_map('intval', $zoneIds))));
    }

    private function cacheKey(int $moduleId, array $zoneIds): string
    {
        sort($zoneIds);

        return 'yumzi_module_bootstrap_v1_' . md5($moduleId . '|' . implode(',', $zoneIds) . '|' . app()->getLocale());
    }

    private function error(string $code, string $message): array
    {
        return [
            'errors' => [
                [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
        ];
    }
}
