<?php

namespace App\Services;

use App\Models\CatalogBanner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogBannerRotation
{
    /** @return array{banner: CatalogBanner, appearance: string, remaining: array<int, int>, ids: array<int, int>}|null */
    public function next(User $user, ?int $requestedId = null, bool $previous = false): ?array
    {
        return DB::transaction(function () use ($user, $requestedId, $previous): ?array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $banners = CatalogBanner::eligible()->orderBy('id')->get()->keyBy('id');
            $ids = $banners->keys()->all();
            if ($ids === []) {
                return null;
            }
            $state = DB::table('catalog_banner_rotations')->where('user_id', $user->id)->first();
            if (! $state) {
                $counter = DB::table('catalog_banner_rotation_counters')->where('id', 1)->lockForUpdate()->first();
                DB::table('catalog_banner_rotation_counters')->where('id', 1)->increment('position');
                $state = (object) ['remaining' => '[]', 'cycle_ids' => '[]', 'position' => $counter->position, 'cycle' => 0, 'last_banner_id' => null];
            }
            $remaining = array_values(array_intersect(json_decode($state->remaining, true), $ids));
            $cycleIds = json_decode($state->cycle_ids, true);
            $remaining = array_merge($remaining, array_values(array_diff($ids, $cycleIds)));
            if ($cycleIds === [] || $remaining === []) {
                $offset = ($state->position + $state->cycle) % count($ids);
                $remaining = array_merge(array_slice($ids, $offset), array_slice($ids, 0, $offset));
                if (count($remaining) > 1 && $remaining[0] === $state->last_banner_id) {
                    $remaining[] = array_shift($remaining);
                }
                $state->cycle++;
                $cycleIds = $ids;
            } else {
                $cycleIds = array_values(array_unique(array_merge($cycleIds, $ids)));
            }
            $selected = in_array($requestedId, $remaining, true) ? $requestedId : ($previous ? end($remaining) : $remaining[0]);
            $remaining = array_values(array_diff($remaining, [$selected]));
            DB::table('catalog_banner_rotations')->updateOrInsert(['user_id' => $user->id], [
                'remaining' => json_encode($remaining), 'cycle_ids' => json_encode($cycleIds),
                'position' => $state->position, 'cycle' => $state->cycle, 'last_banner_id' => $selected,
            ]);
            $appearance = (string) Str::uuid();
            DB::table('catalog_banner_appearances')->insert([
                'id' => $appearance, 'user_id' => $user->id, 'catalog_banner_id' => $selected, 'created_at' => now(),
            ]);

            return ['banner' => $banners[$selected], 'appearance' => $appearance, 'remaining' => $remaining, 'ids' => $ids];
        }, 3);
    }
}
