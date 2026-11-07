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
        $request->merge(['_promotion_form' => 'materials']);
        $fileRules = ['file', 'mimes:jpg,jpeg,png,webp,pdf,xlsx,mp4', 'extensions:jpg,jpeg,png,webp,pdf,xlsx,mp4', 'max:102400'];
        if ($request->has('role')) {
            $data = $request->validate(['role' => ['required', 'in:material,banner,logo'], 'file' => ['required', ...$fileRules]]);
            $uploads = [['file' => $data['file'], 'role' => $data['role'], 'field' => 'file']];
        } else {
            $data = $request->validate([
                'materials' => ['required_without_all:banners,logo', 'nullable', 'array', 'min:1'],
                'materials.*' => ['required', ...$fileRules],
                'banners' => ['nullable', 'array', 'min:1'],
                'banners.*' => ['required', ...$fileRules],
                'logo' => ['nullable', ...$fileRules],
            ], ['materials.required_without_all' => 'Выберите хотя бы один материал, баннер или логотип.']);
            $uploads = [];
            foreach (['materials' => 'material', 'banners' => 'banner'] as $field => $role) {
                foreach ($data[$field] ?? [] as $index => $file) {
                    $uploads[] = ['file' => $file, 'role' => $role, 'field' => $field.'.'.$index];
                }
            }
            if (isset($data['logo'])) {
                $uploads[] = ['file' => $data['logo'], 'role' => 'logo', 'field' => 'logo'];
            }
        }

        foreach ($uploads as $index => $upload) {
            $file = $upload['file'];
            $mime = $file->getMimeType();
            $image = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
            $limit = $image ? 5 * 1024 * 1024 : ($mime === 'video/mp4' ? 100 * 1024 * 1024 : 20 * 1024 * 1024);
            if (($upload['role'] !== 'material' && ! $image) || $file->getSize() > $limit) {
                throw ValidationException::withMessages([$upload['field'] => $file->getClientOriginalName().': изображения — до 5 МБ, PDF/XLSX — до 20 МБ, MP4 — до 100 МБ. Баннер и логотип — только изображения.']);
            }
            $uploads[$index]['mime'] = $mime;
        }

        $paths = [];
        try {
            foreach ($uploads as $index => $upload) {
                $path = $upload['file']->store('promotions/'.$promotion->id, 'local');
                if ($path === false) {
                    throw ValidationException::withMessages([$upload['field'] => 'Не удалось сохранить файл. Повторите загрузку.']);
                }
                $paths[] = $path;
                $uploads[$index]['path'] = $path;
            }

            $workflow->locked($promotion, function (Promotion $current) use ($request, $workflow, $uploads, $audit): void {
                $this->authorize('update', $current);
                foreach ($uploads as $upload) {
                    if ($upload['role'] === 'logo') {
                        $organization = $current->organization;
                        $before = $organization->only('logo_path');
                        $organization->update(['logo_path' => $upload['path']]);
                        $audit->log('organization.logo_updated', $organization, $before, $organization->only('logo_path'), $request->user());
                    } else {
                        $attachment = $current->attachments()->create(['path' => $upload['path'], 'role' => $upload['role'], 'mime_type' => $upload['mime'], 'size' => $upload['file']->getSize(), 'original_name' => $upload['file']->getClientOriginalName()]);
                        $audit->log('promotion.attachment_created', $attachment, [], $attachment->toArray(), $request->user());
                    }
                }
                $workflow->edited($current, $request->user());
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paths);
            throw $exception;
        }

        return back()->withFragment('materials')->with('success', 'Файлы загружены: '.count($uploads).'.');
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

        return back()->withFragment('materials')->with('success', 'Материал удалён.');
    }
}
