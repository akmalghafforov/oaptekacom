<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCatalogBannerRequest;
use App\Models\CatalogBanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class CatalogBannerController extends Controller
{
    public function index(): View
    {
        return view('admin.catalog-banners.index', ['banners' => CatalogBanner::query()->latest()->orderByDesc('id')->paginate(25)]);
    }

    public function create(): View
    {
        return view('admin.catalog-banners.form', ['banner' => new CatalogBanner(['duration_seconds' => 8])]);
    }

    public function edit(CatalogBanner $banner): View
    {
        return view('admin.catalog-banners.form', compact('banner'));
    }

    public function store(SaveCatalogBannerRequest $request): RedirectResponse
    {
        return $this->save($request, new CatalogBanner);
    }

    public function update(SaveCatalogBannerRequest $request, CatalogBanner $banner): RedirectResponse
    {
        return $this->save($request, $banner);
    }

    private function save(SaveCatalogBannerRequest $request, CatalogBanner $banner): RedirectResponse
    {
        $stored = [];
        $obsolete = [];
        try {
            foreach (CatalogBanner::IMAGE_SIZES as $device => $size) {
                if ($request->hasFile($device.'_image')) {
                    $path = $request->file($device.'_image')->store('catalog-banners', 'local');
                    if ($path === false) {
                        throw new \RuntimeException('Не удалось сохранить изображение баннера.');
                    }
                    $stored[$device.'_image'] = $path;
                }
            }
            DB::transaction(function () use ($request, &$banner, $stored, &$obsolete): void {
                if ($banner->exists) {
                    $banner = CatalogBanner::query()->whereKey($banner->id)->lockForUpdate()->firstOrFail();
                }
                foreach ($stored as $field => $path) {
                    if ($banner->$field) {
                        $obsolete[] = $banner->$field;
                    }
                }
                $banner->fill($request->safe()->except(['desktop_image', 'tablet_image', 'mobile_image']));
                $banner->fill($stored)->save();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($stored));
            throw $exception;
        }
        Storage::disk('local')->delete($obsolete);

        return redirect()->route('admin.catalog-banners.index')->with('success', 'Баннер сохранён.');
    }

    public function activation(Request $request, CatalogBanner $banner): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $banner->update($data);

        return back()->with('success', 'Статус баннера обновлён.');
    }

    public function destroy(CatalogBanner $banner): RedirectResponse
    {
        $paths = DB::transaction(function () use ($banner): array {
            $banner = CatalogBanner::query()->whereKey($banner->id)->lockForUpdate()->firstOrFail();
            $paths = [$banner->desktop_image, $banner->tablet_image, $banner->mobile_image];
            $banner->delete();

            return $paths;
        });
        Storage::disk('local')->delete($paths);

        return redirect()->route('admin.catalog-banners.index')->with('success', 'Баннер удалён.');
    }
}
