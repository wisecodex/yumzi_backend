<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\Banner;
use App\Models\Module;
use App\Models\Store;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HomeBootstrapService
{
    private const CACHE_TTL_SECONDS = 60;
    private const RECOMMENDED_STORE_LIMIT = 20;
    private const BANNER_LIMIT = 12;

    public function bootstrap(Request $request): array
    {
        Helpers::setZoneIds($request);

        $zoneIds = $this->zoneIdsFromRequest($request);
        if (empty($zoneIds)) {
            $zoneIds = $this->defaultZoneIds();
        }

        if (empty($zoneIds)) {
            return [
                'status' => 403,
                'body' => $this->error('zoneId', translate('No zone is available')),
            ];
        }

        $latitude = $this->numberFromHeader($request, 'latitude');
        $longitude = $this->numberFromHeader($request, 'longitude');
        $cacheKey = $this->cacheKey($zoneIds);

        $base = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => $this->basePayload($zoneIds)
        );

        return [
            'status' => 200,
            'body' => [
                'modules' => $base['modules'],
                'banners' => $base['banners'],
                'recommended_stores' => $this->storesWithRequestData($base['recommended_stores'], $latitude, $longitude),
                'meta' => [
                    'server_time' => now()->toJSON(),
                    'store_count' => count($base['recommended_stores']),
                    'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
                    'payload_version' => 1,
                ],
            ],
        ];
    }

    private function basePayload(array $zoneIds): array
    {
        $modules = $this->modules($zoneIds);
        $moduleIds = array_column($modules, 'id');
        $stores = $this->recommendedStores($zoneIds, $moduleIds);
        $storeIds = array_column($stores, 'id');

        return [
            'modules' => $modules,
            'banners' => $this->banners($zoneIds, $moduleIds),
            'recommended_stores' => $this->attachStoreSupportData($stores, $storeIds),
        ];
    }

    private function modules(array $zoneIds): array
    {
        $modules = DB::table('modules')
            ->select([
                'modules.id',
                'modules.module_name',
                'modules.module_type',
                'modules.icon',
                'modules.thumbnail',
                'modules.slug',
            ])
            ->selectSub(function ($query) use ($zoneIds): void {
                $query->from('stores')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('stores.module_id', 'modules.id')
                    ->whereIn('stores.zone_id', $zoneIds)
                    ->where('stores.status', 1);
            }, 'stores_count')
            ->where('modules.status', 1)
            ->whereNotIn('modules.module_type', ['parcel', 'rental', 'taxi'])
            ->whereExists(function ($query) use ($zoneIds): void {
                $query->select(DB::raw(1))
                    ->from('module_zone')
                    ->whereColumn('module_zone.module_id', 'modules.id')
                    ->whereIn('module_zone.zone_id', $zoneIds);
            })
            ->orderBy('modules.id')
            ->get();

        $moduleIds = $modules->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $translations = $this->translations(Module::class, $moduleIds, ['module_name']);
        $storage = $this->storage(Module::class, $moduleIds, ['icon', 'thumbnail']);

        return $modules->map(function (object $module) use ($translations, $storage): array {
            $moduleId = (int) $module->id;

            return [
                'id' => $moduleId,
                'module_name' => $this->translatedValue($module->module_name, $translations, $moduleId, 'module_name'),
                'module_type' => $module->module_type,
                'icon_full_url' => $this->imageUrl('module', $module->icon, $storage[$moduleId]['icon'] ?? 'public'),
                'thumbnail_full_url' => $this->imageUrl('module', $module->thumbnail, $storage[$moduleId]['thumbnail'] ?? 'public'),
                'slug' => $module->slug,
                'stores_count' => (int) $module->stores_count,
            ];
        })->all();
    }

    private function recommendedStores(array $zoneIds, array $moduleIds): array
    {
        if (empty($moduleIds)) {
            return [];
        }

        $stores = $this->recommendedStoresQuery($zoneIds, $moduleIds, true)->get();

        if ($stores->isEmpty()) {
            $stores = $this->recommendedStoresQuery($zoneIds, $moduleIds, false)->get();
        }

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
            $config = $configs[$storeId] ?? null;

            return [
                'id' => $storeId,
                'module_id' => (int) $store->module_id,
                'zone_id' => (int) $store->zone_id,
                'name' => $this->translatedValue($store->name, $nameTranslations, $storeId, 'name'),
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
                'schedules' => $schedules[$storeId] ?? [],
                'discount' => $this->formatDiscount($discount),
                'discount_label' => $this->discountLabel($discount),
                'is_recommended' => $this->isRecommended($config),
            ];
        })->all();
    }

    private function recommendedStoresQuery(array $zoneIds, array $moduleIds, bool $recommendedOnly)
    {
        return DB::table('stores')
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
            ])
            ->leftJoin('store_configs', 'store_configs.store_id', '=', 'stores.id')
            ->whereIn('stores.module_id', $moduleIds)
            ->whereIn('stores.zone_id', $zoneIds)
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
            ->when($recommendedOnly, function ($query): void {
                $query->where(function ($recommended): void {
                    $recommended->where('stores.featured', 1)
                        ->orWhere(function ($config): void {
                            $config->where('store_configs.is_recommended', 1)
                                ->where(function ($deleted): void {
                                    $deleted->whereNull('store_configs.is_recommended_deleted')
                                        ->orWhere('store_configs.is_recommended_deleted', 0);
                                });
                        });
                });
            })
            ->orderByDesc('stores.featured')
            ->orderByDesc('store_configs.is_recommended')
            ->orderByDesc('stores.total_order')
            ->orderBy('stores.name')
            ->limit(self::RECOMMENDED_STORE_LIMIT);
    }

    private function attachStoreSupportData(array $stores, array $storeIds): array
    {
        $itemDiscountSummaries = $this->itemDiscountSummaries($storeIds);

        return array_map(function (array $store) use ($itemDiscountSummaries): array {
            $storeId = $store['id'];
            $itemDiscountSummary = $itemDiscountSummaries[$storeId] ?? null;

            if ($store['discount'] === null && $itemDiscountSummary) {
                $store['discount_label'] = $itemDiscountSummary['discount_label'];
            }

            $store['has_discount'] = ! empty($store['discount_label']);

            return $store;
        }, $stores);
    }

    private function itemDiscountSummaries(array $storeIds): array
    {
        if (empty($storeIds)) {
            return [];
        }

        $rows = DB::table('items')
            ->select('store_id')
            ->selectRaw("MAX(CASE WHEN COALESCE(NULLIF(discount_type, ''), 'percent') = 'percent' THEN discount ELSE 0 END) as max_percent_discount")
            ->selectRaw("MAX(CASE WHEN COALESCE(NULLIF(discount_type, ''), 'percent') != 'percent' THEN discount ELSE 0 END) as max_amount_discount")
            ->whereIn('store_id', $storeIds)
            ->where('status', 1)
            ->where('is_approved', 1)
            ->where('discount', '>', 0)
            ->groupBy('store_id')
            ->get();

        $summaries = [];

        foreach ($rows as $row) {
            $summary = $this->formatItemDiscountSummary([
                'max_percent_discount' => (float) $row->max_percent_discount,
                'max_amount_discount' => (float) $row->max_amount_discount,
            ]);

            if ($summary) {
                $summaries[(int) $row->store_id] = $summary;
            }
        }

        return $summaries;
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

    private function banners(array $zoneIds, array $moduleIds): array
    {
        if (empty($moduleIds)) {
            return [];
        }

        $banners = DB::table('banners')
            ->select(['id', 'title', 'type', 'image', 'data', 'default_link', 'module_id'])
            ->where('status', 1)
            ->where('created_by', 'admin')
            ->whereIn('module_id', $moduleIds)
            ->where(function ($query) use ($zoneIds): void {
                $query->where(function ($zoneQuery) use ($zoneIds): void {
                    $zoneQuery->whereIn('type', ['store_wise', 'item_wise'])
                        ->whereIn('zone_id', $zoneIds);
                })->orWhere('type', 'default');
            })
            ->orderByDesc('featured')
            ->latest('id')
            ->limit(self::BANNER_LIMIT)
            ->get();

        $bannerIds = $banners->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $storeIds = $banners
            ->filter(fn (object $banner): bool => $banner->type === 'store_wise')
            ->pluck('data')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();
        $itemIds = $banners
            ->filter(fn (object $banner): bool => $banner->type === 'item_wise')
            ->pluck('data')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        $validStoreIds = $this->validBannerStoreIds($storeIds, $zoneIds, $moduleIds);
        $validItemIds = $this->validBannerItemIds($itemIds, $moduleIds);
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

    private function validBannerStoreIds(array $storeIds, array $zoneIds, array $moduleIds): array
    {
        if (empty($storeIds)) {
            return [];
        }

        return DB::table('stores')
            ->whereIn('id', $storeIds)
            ->whereIn('zone_id', $zoneIds)
            ->whereIn('module_id', $moduleIds)
            ->where('status', 1)
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    private function validBannerItemIds(array $itemIds, array $moduleIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        return DB::table('items')
            ->whereIn('id', $itemIds)
            ->whereIn('module_id', $moduleIds)
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

    private function defaultZoneIds(): array
    {
        $zone = Zone::where('status', 1)->where('is_default', 1)->first() ?? Zone::first();

        return $zone ? [(int) $zone->id] : [];
    }

    private function cacheKey(array $zoneIds): string
    {
        sort($zoneIds);

        return 'home_bootstrap_v1_' . md5(implode(',', $zoneIds) . '|' . app()->getLocale());
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
