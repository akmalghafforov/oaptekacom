<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\PromotionAttachment;
use App\Services\AuditLogger;
use App\Services\PromotionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PromotionAttachmentController extends Controller
{
    public function store(Promotion $promotion, Request $request, PromotionWorkflow $workflow, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $promotion);
        $request->validate(['role' => ['required', 'in:material,banner,logo'], 'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,xlsx,mp4', 'extensions:jpg,jpeg,png,webp,pdf,xlsx,mp4', 'max:102400']]);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $image = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
        $limit = $image ? 5 * 1024 * 1024 : ($mime === 'video/mp4' ? 100 * 1024 * 1024 : 20 * 1024 * 1024);
        if (($request->input('role') !== 'material' && ! $image) || $file->getSize() > $limit) {
            throw ValidationException::withMessages(['file' => 'Для баннера и логотипа выберите изображение до 5 МБ. PDF/XLSX — до 20 МБ, MP4 — до 100 МБ.']);
        }
        $path = $file->store('promotions/'.$promotion->id, 'local');
        try {
            $workflow->locked($promotion, function (Promotion $current) use ($request, $workflow, $file, $path, $mime, $audit): void {
                $this->authorize('update', $current);
                if ($request->input('role') === 'logo') {
                    $organization = $current->organization;
                    $before = $organization->only('logo_path');
                    $organization->update(['logo_path' => $path]);
                    $audit->log('organization.logo_updated', $organization, $before, $organization->only('logo_path'), $request->user());
                } else {
                    $attachment = $current->attachments()->create(['path' => $path, 'role' => $request->input('role'), 'mime_type' => $mime, 'size' => $file->getSize(), 'original_name' => $file->getClientOriginalName()]);
                    $audit->log('promotion.attachment_created', $attachment, [], $attachment->toArray(), $request->user());
                }
                $workflow->edited($current, $request->user());
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('success', 'Материал загружен.');
    }

    public function show(PromotionAttachment $attachment, Request $request): BinaryFileResponse
    {
        $this->authorize('view', $attachment);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $headers = ['Content-Type' => $attachment->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('download') || $attachment->mime_type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            return response()->download(Storage::disk('local')->path($attachment->path), $attachment->original_name, $headers)->setPrivate();
        }

        return response()->file(Storage::disk('local')->path($attachment->path), $headers)->setPrivate();
    }

    public function destroy(PromotionAttachment $attachment, Request $request, PromotionWorkflow $workflow, AuditLogger $audit): RedirectResponse
    {
        $workflow->locked($attachment->promotion, function (Promotion $current) use ($attachment, $request, $workflow, $audit): void {
            $attachment = $current->attachments()->lockForUpdate()->findOrFail($attachment->id);
            $this->authorize('delete', $attachment);
            $audit->log('promotion.attachment_deleted', $attachment, $attachment->toArray(), [], $request->user());
            $attachment->delete();
            $workflow->edited($current, $request->user());
        });
        Storage::disk('local')->delete($attachment->path);

        return back()->with('success', 'Материал удалён.');
    }
}
