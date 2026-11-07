<?php

namespace App\Http\Controllers;

use App\Enums\PromotionStatus;
use App\Models\Organization;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PromotionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Promotion::class);
        $filters = $request->validate(['company' => ['nullable', 'integer'], 'city' => ['nullable', 'string', 'max:255'], 'q' => ['nullable', 'string', 'max:255']]);
        $promotions = Promotion::query()->active()->with('organization', 'attachments')
            ->when($filters['company'] ?? null, fn (Builder $query, int $id): Builder => $query->where('organization_id', $id))
            ->when($filters['city'] ?? null, fn (Builder $query, string $city): Builder => $query->where('city', $city))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term): Builder => $query->where('title', 'like', '%'.$term.'%'))
            ->orderByDesc('id')->paginate(24)->withQueryString();
        $companies = Organization::query()->whereHas('promotions', fn (Builder $query): Builder => $query->active())->orderBy('name')->orderBy('id')->get(['id', 'name']);
        $cities = Promotion::query()->active()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city');

        return view('promotions.index', compact('promotions', 'companies', 'cities', 'filters'));
    }

    public function show(Promotion $promotion, Request $request): View
    {
        $this->authorize('view', $promotion);
        $promotion->load('organization', 'items', 'attachments');
        $phone = preg_replace('/\D/', '', $promotion->organization->whatsapp_phone ?? '');
        $whatsappUrl = $phone ? 'https://wa.me/'.$phone.'?text='.rawurlencode($promotion->title.' '.route('promotions.show', $promotion)) : null;

        return view('promotions.show', compact('promotion', 'whatsappUrl'));
    }

    public function logo(Organization $organization, Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Promotion::class);
        $canManage = $request->user()->isAdminMode() || ($request->user()->canSupply() && $request->user()->activeOrganizationId() === $organization->id);
        abort_unless($canManage || ($organization->status === 'active' && ! $organization->setup_required && ! $organization->price_list_hidden
            && $organization->promotions()->where('status', PromotionStatus::Published)->exists()), 403);
        abort_unless($organization->logo_path && Storage::disk('local')->exists($organization->logo_path), 404);

        return response()->file(Storage::disk('local')->path($organization->logo_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
