<?php

namespace App\Http\Controllers;

use App\Models\CatalogBanner;
use App\Services\CatalogBannerRotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogBannerController extends Controller
{
    public function next(Request $request, CatalogBannerRotation $rotation): JsonResponse
    {
        $data = $request->validate(['banner_id' => ['nullable', 'integer'], 'previous' => ['sometimes', 'boolean']]);
        $result = $rotation->next($request->user(), $data['banner_id'] ?? null, $data['previous'] ?? false);
        if (! $result) {
            return response()->json(['banner' => null]);
        }
        $banner = $result['banner'];

        return response()->json([
            'banner' => ['id' => $banner->id, 'title' => $banner->title, 'duration' => $banner->duration_seconds,
                'url' => route('catalog.banners.show', $banner), 'expires_at' => $banner->ends_at?->toIso8601String(),
                'images' => collect(CatalogBanner::IMAGE_SIZES)->mapWithKeys(fn (array $size, string $device): array => [$device => route('catalog.banners.image', [$banner, $device])])],
            'appearance' => $result['appearance'], 'remaining' => $result['remaining'], 'ids' => $result['ids'],
            'impression_url' => $request->user()->isAdmin() ? null : route('catalog.banners.impression', $result['appearance']),
        ])->header('Cache-Control', 'no-store');
    }

    public function impression(Request $request, string $appearance): Response
    {
        abort_if($request->user()->isAdmin(), 403);
        DB::transaction(function () use ($request, $appearance): void {
            $row = DB::table('catalog_banner_appearances')->where('id', $appearance)->where('user_id', $request->user()->id)->first();
            abort_unless($row, 404);
            $banner = CatalogBanner::eligible()->whereKey($row->catalog_banner_id)->lockForUpdate()->first();
            abort_unless($banner, 404);
            abort_if(now()->diffInMilliseconds($row->created_at, true) < 1000, 422);
            $counted = DB::table('catalog_banner_appearances')->where('id', $appearance)->whereNull('counted_at')->update(['counted_at' => now()]);
            if ($counted) {
                $banner->increment('impressions');
            }
        }, 3);

        return response()->noContent();
    }

    public function show(CatalogBanner $banner): View
    {
        abort_unless(CatalogBanner::eligible()->whereKey($banner->id)->exists(), 404);

        return view('catalog.banners.show', compact('banner'));
    }

    public function image(Request $request, CatalogBanner $banner, string $device): BinaryFileResponse
    {
        abort_unless(array_key_exists($device, CatalogBanner::IMAGE_SIZES), 404);
        abort_unless($request->user()->isAdmin() || CatalogBanner::eligible()->whereKey($banner->id)->exists(), 404);
        $path = $banner->{$device.'_image'};
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache']);
    }
}
