<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\Category;
use App\Models\Item;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StoreBootstrapService
{
    private const CACHE_TTL_SECONDS = 60;

    public function bootstrap(Request $request, string $storeKey): array
    {
        Helpers::setZoneIds($request);

        $zoneIds = $this->zoneIdsFromRequest($request);
        $store = $this->store($storeKey, $zoneIds);

        if (! $store) {
            return [
                'status' => 403,
                'body' => $this->error('storeId', translate('messages.not_found')),
            ];
        }

        $latitude = $this->numberFromHeader($request, 'latitude');
        $longitude = $this->numberFromHeader($request, 'longitude');
        $storeId = (int) $store->id;

        $base = Cache::remember(
            $this->cacheKey($storeId),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => $this->basePayload($store)
        );

        $base['store'] = $this->storeWithRequestData($base['store'], $latitude, $longitude);
        $base['meta']['server_time'] = now()->toJSON();

        return [
            'status' => 200,
            'body' => $base,
        ];
    }

    private function basePayload(object $store): array
    {
        $storeId = (int) $store->id;
        $moduleId = (int) $store->module_id;
        $storeDiscount = $this->storeDiscounts([$storeId])[$storeId] ?? null;
        $items = $this->items($storeId, $moduleId, $storeDiscount);
        $categoryIds = $this->storeCategoryIds($items);
        $categories = $this->categories($categoryIds);
        $recommendedItemIds = array_values(array_map(
            'intval',
            array_column(array_filter($items, fn (array $item): bool => (int) ($item['recommended'] ?? 0) === 1), 'id')
        ));
        $itemDiscountSummary = $storeDiscount ? null : $this->itemDiscountSummaryFromItems($items);

        $formattedStore = $this->formatStore($store, $categoryIds, $categories, count($items), $storeDiscount, $itemDiscountSummary);

        return [
            'store' => $formattedStore,
            'categories' => $categories,
            'items' => $this->stripInternalItemFields($items),
            'recommended_item_ids' => $recommendedItemIds,
            'meta' => [
                'total_items' => count($items),
                'recommended_count' => count($recommendedItemIds),
                'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
                'payload_version' => 2,
            ],
        ];
    }

    private function store(string $storeKey, array $zoneIds): ?object
    {
        return DB::table('stores')
            ->join('modules', 'modules.id', '=', 'stores.module_id')
            ->select([
                'stores.id',
                'stores.vendor_id',
                'stores.module_id',
                'stores.zone_id',
                'stores.name',
                'stores.logo',
                'stores.cover_photo',
                'stores.slug',
                'stores.rating',
                'stores.delivery_time',
                'stores.free_delivery',
                'stores.active',
                'stores.featured',
                'stores.total_order',
                'stores.order_count',
                'stores.address',
                'stores.phone',
                'stores.email',
                'stores.latitude',
                'stores.longitude',
                'stores.minimum_order',
                'stores.tax',
                'stores.announcement',
                'stores.announcement_message',
                'stores.delivery',
                'stores.take_away',
                'stores.schedule_order',
                'stores.order_place_to_schedule_interval',
                'stores.cutlery',
                'stores.created_at',
                'modules.all_zone_service',
            ])
            ->where('stores.status', 1)
            ->where('modules.status', 1)
            ->where(function ($query) use ($storeKey): void {
                if (is_numeric($storeKey)) {
                    $query->where('stores.id', (int) $storeKey);
                } else {
                    $query->where('stores.slug', $storeKey);
                }
            })
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
            ->where(function ($query) use ($zoneIds): void {
                $query->where('modules.all_zone_service', 1)
                    ->orWhereIn('stores.zone_id', $zoneIds);
            })
            ->first();
    }

    private function formatStore(
        object $store,
        array $categoryIds,
        array $categories,
        int $itemCount,
        ?object $discount,
        ?array $itemDiscountSummary
    ): array
    {
        $storeId = (int) $store->id;
        $nameTranslations = $this->translations(Store::class, [$storeId], ['name']);
        $storage = $this->storage(Store::class, [$storeId], ['logo', 'cover_photo']);
        $schedules = $this->storeSchedules([$storeId]);
        $rating = $this->rating($store->rating);
        $discountLabel = $this->discountLabel($discount) ?? ($itemDiscountSummary['discount_label'] ?? null);

        return [
            'id' => $storeId,
            'vendor_id' => (int) $store->vendor_id,
            'module_id' => (int) $store->module_id,
            'zone_id' => (int) $store->zone_id,
            'name' => $this->translatedValue($store->name, $nameTranslations, $storeId, 'name'),
            'logo_full_url' => $this->imageUrl('store', $store->logo, $storage[$storeId]['logo'] ?? 'public'),
            'cover_photo_full_url' => $this->imageUrl('store/cover', $store->cover_photo, $storage[$storeId]['cover_photo'] ?? 'public'),
            'slug' => $store->slug,
            'ratings' => $rating['breakdown'],
            'avg_rating' => $rating['average'],
            'rating_count' => $rating['count'],
            'reviews_comments_count' => $rating['count'],
            'delivery_time' => $store->delivery_time,
            'min_delivery_time' => $this->minDeliveryMinutes($store->delivery_time),
            'free_delivery' => (bool) $store->free_delivery,
            'discount' => $this->formatDiscount($discount),
            'discount_label' => $discountLabel,
            'has_discount' => ! empty($discountLabel),
            'active' => (bool) $store->active,
            'featured' => (int) $store->featured,
            'total_order' => max((int) $store->total_order, (int) $store->order_count),
            'address' => $store->address,
            'phone' => $store->phone,
            'email' => $store->email,
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'minimum_order' => (float) $store->minimum_order,
            'tax' => (float) $store->tax,
            'announcement' => (int) $store->announcement,
            'announcement_message' => $store->announcement_message,
            'schedules' => $schedules[$storeId] ?? [],
            'category_ids' => $categoryIds,
            'category_details' => $categories,
            'total_items' => $itemCount,
            'delivery' => (bool) $store->delivery,
            'take_away' => (bool) $store->take_away,
            'schedule_order' => (bool) $store->schedule_order,
            'order_place_to_schedule_interval' => (int) $store->order_place_to_schedule_interval,
            'extra_packaging_status' => isset($store->extra_packaging_status) ? (bool) $store->extra_packaging_status : false,
            'extra_packaging_amount' => isset($store->extra_packaging_amount) ? (float) $store->extra_packaging_amount : 0,
            'cutlery' => (bool) $store->cutlery,
        ];
    }

    private function storeWithRequestData(array $store, ?float $latitude, ?float $longitude): array
    {
        $store['distance'] = $this->distanceKm($latitude, $longitude, $store['latitude'], $store['longitude']);
        $store['open'] = $this->isOpenNow((bool) $store['active'], $store['schedules']) ? 1 : 0;
        $store['current_opening_time'] = $this->currentOpeningTime($store['schedules']);

        return $store;
    }

    private function items(int $storeId, int $moduleId, ?object $storeDiscount): array
    {
        $items = DB::table('items')
            ->select([
                'id',
                'module_id',
                'store_id',
                'name',
                'description',
                'image',
                'slug',
                'price',
                'discount',
                'discount_type',
                'avg_rating',
                'rating_count',
                'stock',
                'available_time_starts',
                'available_time_ends',
                'category_id',
                'category_ids',
                'recommended',
                'created_at',
            ])
            ->where('store_id', $storeId)
            ->where('module_id', $moduleId)
            ->where('status', 1)
            ->where('is_approved', 1)
            ->latest('id')
            ->get();

        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $translations = $this->translations(Item::class, $itemIds, ['name', 'description']);
        $storage = $this->storage(Item::class, $itemIds, ['image']);
        $categoryNames = $this->categoryNamesForItems($items);

        return $items->map(function (object $item) use ($translations, $storage, $storeDiscount, $categoryNames): array {
            $itemId = (int) $item->id;
            $discount = $this->productDiscount($item, $storeDiscount);

            return [
                'id' => $itemId,
                'module_id' => (int) $item->module_id,
                'store_id' => (int) $item->store_id,
                'name' => $this->translatedValue($item->name, $translations, $itemId, 'name'),
                'description' => $this->translatedValue($item->description, $translations, $itemId, 'description'),
                'image_full_url' => $this->imageUrl('product', $item->image, $storage[$itemId]['image'] ?? 'public'),
                'slug' => $item->slug,
                'price' => (float) $item->price,
                'discount' => $discount['discount'],
                'discount_type' => $discount['discount_type'],
                'avg_rating' => (float) ($item->avg_rating ?? 0),
                'rating_count' => (int) ($item->rating_count ?? 0),
                'stock' => (int) ($item->stock ?? 0),
                'available_time_starts' => $item->available_time_starts
                    ? $this->timeString($item->available_time_starts)
                    : null,
                'available_time_ends' => $item->available_time_ends
                    ? $this->timeString($item->available_time_ends)
                    : null,
                'availability' => $this->availability($item->available_time_starts, $item->available_time_ends),
                'category_id' => (int) $item->category_id,
                'category_ids' => $this->categoryIdsWithNames($item->category_ids, (int) $item->category_id, $categoryNames),
                'recommended' => (int) $item->recommended,
            ];
        })->all();
    }

    private function productDiscount(object $item, ?object $storeDiscount): array
    {
        $productDiscount = (float) $item->discount;
        $productType = $item->discount_type ?: 'percent';
        $productAmount = $productType === 'percent'
            ? ((float) $item->price / 100) * $productDiscount
            : $productDiscount;
        $storeAmount = $storeDiscount
            ? ((float) $item->price / 100) * (float) $storeDiscount->discount
            : 0;

        if ($storeDiscount && $storeAmount >= $productAmount) {
            return [
                'discount' => (float) $storeDiscount->discount,
                'discount_type' => 'percent',
            ];
        }

        return [
            'discount' => $productDiscount,
            'discount_type' => $productType,
        ];
    }

    private function availability(?string $startsAt, ?string $endsAt): array
    {
        $start = $this->timeString($startsAt);
        $end = $this->timeString($endsAt);

        return [
            'available_now' => $this->isTimeAvailableNow($start, $end),
            'label' => substr($start, 0, 5),
        ];
    }

    private function isTimeAvailableNow(string $start, string $end): bool
    {
        if ($start === $end) {
            return true;
        }

        $now = now()->format('H:i:s');

        if ($start < $end) {
            return $now >= $start && $now <= $end;
        }

        return $now >= $start || $now <= $end;
    }

    private function stripInternalItemFields(array $items): array
    {
        return array_map(function (array $item): array {
            unset($item['recommended']);

            return $item;
        }, $items);
    }

    private function storeCategoryIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $parents = [];
            foreach ($item['category_ids'] ?? [] as $category) {
                if (! is_array($category)) {
                    continue;
                }

                if ((int) ($category['position'] ?? 0) === 1 && isset($category['id'])) {
                    $parents[] = (int) $category['id'];
                }
            }

            $ids = array_merge($ids, $parents ?: [(int) ($item['category_id'] ?? 0)]);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function categories(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $categories = DB::table('categories')
            ->select(['id', 'name', 'image', 'slug'])
            ->whereIn('id', $categoryIds)
            ->where('status', 1)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $ids = $categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $translations = $this->translations(Category::class, $ids, ['name']);
        $storage = $this->storage(Category::class, $ids, ['image']);

        return $categories->map(function (object $category) use ($translations, $storage): array {
            $categoryId = (int) $category->id;

            return [
                'id' => $categoryId,
                'name' => $this->translatedValue($category->name, $translations, $categoryId, 'name'),
                'image_full_url' => $this->imageUrl('category', $category->image, $storage[$categoryId]['image'] ?? 'public'),
                'slug' => $category->slug,
            ];
        })->all();
    }

    private function categoryNamesForItems(Collection $items): array
    {
        $categoryIds = [];

        foreach ($items as $item) {
            $categoryIds = array_merge(
                $categoryIds,
                $this->categoryIdsFromItem($item->category_ids, (int) $item->category_id)
            );
        }

        $categoryIds = array_values(array_unique(array_filter($categoryIds)));

        if (empty($categoryIds)) {
            return [];
        }

        $categories = DB::table('categories')
            ->select(['id', 'name'])
            ->whereIn('id', $categoryIds)
            ->get();

        $translations = $this->translations(Category::class, $categoryIds, ['name']);

        return $categories
            ->mapWithKeys(fn (object $category): array => [
                (int) $category->id => $this->translatedValue($category->name, $translations, (int) $category->id, 'name'),
            ])
            ->all();
    }

    private function categoryIdsWithNames(mixed $rawCategoryIds, ?int $fallbackCategoryId, array $categoryNames): array
    {
        $decoded = $this->decodedCategoryIds($rawCategoryIds);

        if (empty($decoded) && $fallbackCategoryId) {
            $decoded = [['id' => $fallbackCategoryId, 'position' => 1]];
        }

        return array_map(function (array $category) use ($categoryNames): array {
            $id = (int) ($category['id'] ?? 0);

            return [
                'id' => (string) $id,
                'position' => (int) ($category['position'] ?? 0),
                'name' => $categoryNames[$id] ?? 'NA',
            ];
        }, $decoded);
    }

    private function categoryIdsFromItem(mixed $rawCategoryIds, ?int $fallbackCategoryId): array
    {
        $ids = [];

        foreach ($this->decodedCategoryIds($rawCategoryIds) as $category) {
            if (isset($category['id'])) {
                $ids[] = (int) $category['id'];
            }
        }

        if (empty($ids) && $fallbackCategoryId) {
            $ids[] = (int) $fallbackCategoryId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function decodedCategoryIds(mixed $rawCategoryIds): array
    {
        if (is_array($rawCategoryIds)) {
            return $rawCategoryIds;
        }

        if (! is_string($rawCategoryIds) || trim($rawCategoryIds) === '') {
            return [];
        }

        $decoded = json_decode($rawCategoryIds, true);

        return is_array($decoded) ? $decoded : [];
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

    private function itemDiscountSummaryFromItems(array $items): ?array
    {
        $summary = [
            'max_percent_discount' => 0,
            'max_amount_discount' => 0,
        ];

        foreach ($items as $item) {
            $discount = (float) ($item['discount'] ?? 0);
            if ($discount <= 0) {
                continue;
            }

            if (($item['discount_type'] ?? 'percent') === 'percent') {
                $summary['max_percent_discount'] = max($summary['max_percent_discount'], $discount);

                continue;
            }

            $summary['max_amount_discount'] = max($summary['max_amount_discount'], $discount);
        }

        return $this->formatItemDiscountSummary($summary);
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
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($decoded)) {
            $ids = array_values(array_filter(array_map('intval', $decoded)));
            if (! empty($ids)) {
                return $ids;
            }
        }

        return DB::table('zones')
            ->where('status', 1)
            ->orderBy('id')
            ->limit(1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function cacheKey(int $storeId): string
    {
        return 'store_bootstrap_v2_' . md5($storeId . '|' . app()->getLocale());
    }

    private function error(string $code, string $message): array
    {
        return [
            'errors' => [
                ['code' => $code, 'message' => $message],
            ],
        ];
    }
}
