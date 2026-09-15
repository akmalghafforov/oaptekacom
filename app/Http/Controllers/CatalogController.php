<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogSearchRequest;
use App\Services\CatalogSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(): View
    {
        return view('catalog.index');
    }

    public function search(CatalogSearchRequest $request, CatalogSearchService $catalogSearch): JsonResponse
    {
        $filters = $request->validated();
        $offers = $catalogSearch->search($filters);

        return response()->json([
            'html' => view('catalog.partials.offer-cards', ['offers' => $offers->getCollection()])->render(),
            'next_cursor' => $offers->nextCursor()?->encode(),
            'has_more' => $offers->hasMorePages(),
            'facets' => empty($filters['cursor']) ? $catalogSearch->facets($filters)->values() : null,
        ]);
    }
}
