<?php

namespace App\Services;

use App\Enums\PromotionStatus;
use App\Models\Organization;
use App\Models\Promotion;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PromotionWorkflow
{
    public function __construct(private AuditLogger $audit) {}

    /** Lock the company before promotion rows, matching cart checkout and import activation. */
    public function locked(Promotion $promotion, Closure $action, array $additionalOrganizationIds = []): mixed
    {
        return DB::transaction(function () use ($promotion, $action, $additionalOrganizationIds): mixed {
            Organization::query()->whereIn('id', [$promotion->organization_id, ...$additionalOrganizationIds])->orderBy('id')->lockForUpdate()->get();
            $current = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            if ($current->organization_id !== $promotion->organization_id) {
                throw ValidationException::withMessages(['promotion' => 'Компания изменилась. Обновите страницу.']);
            }

            return $action($current);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function save(User $user, ?Promotion $promotion, array $data, ?UploadedFile $poster = null): Promotion
    {
        Gate::forUser($user)->authorize($promotion === null ? 'create' : 'update', $promotion ?? Promotion::class);
        $posterPath = $poster?->store('promotions/posters', 'local');
        if ($posterPath === false) {
            throw ValidationException::withMessages(['poster' => 'Не удалось сохранить постер. Повторите загрузку.']);
        }

        try {
            if ($promotion === null) {
                return DB::transaction(function () use ($user, $data, $poster, $posterPath): Promotion {
                    $organization = Organization::query()->lockForUpdate()->findOrFail($data['organization_id']);
                    abort_unless($user->isAdminMode() || $user->activeOrganizationId() === $organization->id, 403);
                    $promotion = Promotion::create($data + ['author_id' => $user->id, 'status' => PromotionStatus::Draft]);
                    if ($poster !== null) {
                        $this->attachPoster($promotion, $poster, $posterPath, $user);
                    }
                    $this->audit->log('promotion.created', $promotion, [], $promotion->toArray(), $user);

                    return $promotion;
                }, 3);
            }

            return $this->locked($promotion, function (Promotion $current) use ($user, $data, $poster, $posterPath): Promotion {
                Gate::forUser($user)->authorize('update', $current);
                abort_unless($user->isAdminMode() || (int) $data['organization_id'] === $user->activeOrganizationId(), 403);
                if ((int) $data['organization_id'] !== $current->organization_id && ($current->first_published_at !== null || $current->items()->withTrashed()->exists())) {
                    throw ValidationException::withMessages(['organization_id' => 'Компания акции с позициями или историей публикации неизменна.']);
                }
                $before = $current->toArray();
                $current->fill($data);
                if ($poster !== null) {
                    $this->attachPoster($current, $poster, $posterPath, $user);
                }
                $this->edited($current, $user, $before);

                return $current;
            }, [(int) $data['organization_id']]);
        } catch (Throwable $exception) {
            if ($posterPath !== null) {
                Storage::disk('local')->delete($posterPath);
            }
            throw $exception;
        }
    }

    private function attachPoster(Promotion $promotion, UploadedFile $poster, string $path, User $user): void
    {
        $attachment = $promotion->attachments()->where('role', 'banner')->orderBy('id')->lockForUpdate()->firstOrNew();
        $before = $attachment->toArray();
        $oldPath = $attachment->path;
        $attachment->fill([
            'role' => 'banner', 'path' => $path, 'original_name' => $poster->getClientOriginalName(),
            'mime_type' => $poster->getMimeType(), 'size' => $poster->getSize(),
        ]);
        $attachment->save();
        $this->audit->log($oldPath === null ? 'promotion.attachment_created' : 'promotion.attachment_updated', $attachment, $before, $attachment->toArray(), $user);
        if ($oldPath !== null) {
            DB::afterCommit(function () use ($oldPath): void {
                try {
                    Storage::disk('local')->delete($oldPath);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    /** @param array<string, mixed>|null $before */
    public function edited(Promotion $promotion, User $user, ?array $before = null): void
    {
        $before ??= $promotion->toArray();
        $promotion->terms_version++;
        if (! $user->isAdminMode() || in_array($promotion->status, [PromotionStatus::NeedsRevision, PromotionStatus::Rejected], true)) {
            $promotion->status = PromotionStatus::Draft;
        }
        $promotion->save();
        $this->audit->log('promotion.updated', $promotion, $before, $promotion->toArray(), $user);
    }

    public function transition(User $user, Promotion $promotion, string $action, ?string $comment = null): void
    {
        $this->locked($promotion, function (Promotion $current) use ($user, $action, $comment): void {
            $ability = match ($action) {
                'submit' => 'submit', 'withdraw' => 'withdraw',
                'publish' => $user->isAdminMode() && $current->status === PromotionStatus::Draft ? 'submit' : 'review',
                default => 'review',
            };
            Gate::forUser($user)->authorize($ability, $current);
            if (in_array($action, ['revise', 'reject'], true) && blank($comment)) {
                throw ValidationException::withMessages(['comment' => 'Укажите причину решения.']);
            }
            if ($action === 'publish') {
                abort_unless($user->isAdminMode(), 403);
            }
            $before = $current->toArray();
            $current->status = match ($action) {
                'submit' => PromotionStatus::PendingReview, 'withdraw' => PromotionStatus::Draft,
                'publish' => PromotionStatus::Published, 'revise' => PromotionStatus::NeedsRevision, 'reject' => PromotionStatus::Rejected,
            };
            if (in_array($action, ['publish', 'revise', 'reject'], true)) {
                $current->reviewed_by = $user->id;
                $current->reviewed_at = now();
                $current->moderation_comment = $comment;
            }
            if ($action === 'publish') {
                $current->first_published_at ??= now();
            }
            $current->save();
            $this->audit->log('promotion.'.$action, $current, $before, $current->toArray(), $user);
        });
    }
}
