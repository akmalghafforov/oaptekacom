<?php

namespace App\Services;

use App\Enums\SupplierFileType;
use App\Models\GmailConnection;
use App\Models\Organization;
use App\Services\PriceList\SampleWorkbookReader;
use Google\Service\Gmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GmailProfileDrafts
{
    public function __construct(private GmailMailboxAccess $access, private GmailMessageReader $reader, private GmailImportIntake $intake, private ImportFileValidator $validator, private SampleWorkbookReader $samples) {}

    public function authorize(Request $request): GmailConnection
    {
        abort_unless($request->user()?->isAdminMode() && config('gmail-imports.enabled'), 403);
        try {
            $connection = $this->access->connection();
        } catch (GmailException) {
            abort(403, 'Подключите настроенный почтовый ящик для импорта.');
        }
        abort_unless($connection->user_id === $request->user()->id, 403);

        return $connection;
    }

    public function stage(Request $request, Organization $supplier, string $messageId, string $partId): string
    {
        $connection = $this->authorize($request);
        try {
            $metadata = $this->access->run(function (Gmail $gmail) use ($messageId, $partId): array {
                $reader = $this->reader;
                $intake = $this->intake;
                $message = $reader->attachmentMetadata($gmail, $messageId);
                abort_unless(in_array('INBOX', $message->getLabelIds() ?? [], true), 422, 'Письмо недоступно во входящих.');
                $parts = [];
                if ($message->getPayload()) {
                    $intake->parts($message->getPayload(), $parts);
                }
                $part = collect($parts)->first(fn ($part): bool => (string) $part->getPartId() === $partId);
                if (! $part) {
                    throw ValidationException::withMessages(['part_id' => 'Вложение больше недоступно.']);
                }
                $type = SupplierFileType::tryFrom(strtolower(pathinfo($part->getFilename(), PATHINFO_EXTENSION)));
                if (! $type || ($part->getBody()?->getSize() ?? 0) > config('price-list-imports.max_file_kilobytes') * 1024) {
                    throw ValidationException::withMessages(['part_id' => 'Недопустимый формат или размер вложения.']);
                }
                $headers = collect($message->getPayload()->getHeaders() ?? [])->filter(fn ($header): bool => strcasecmp($header->getName(), 'From') === 0);
                $sender = $headers->count() === 1 ? $intake->sender($headers->first()->getValue()) : null;
                if (! $sender) {
                    throw ValidationException::withMessages(['part_id' => 'Не удалось определить адрес отправителя.']);
                }
                $body = $part->getBody();
                $bytes = $intake->decode(($body?->getAttachmentId() ? $gmail->users_messages_attachments->get('me', $messageId, $body->getAttachmentId())->getData() : $body?->getData()) ?? '');
                if ($bytes === null || ($body?->getSize() !== null && strlen($bytes) !== (int) $body->getSize())) {
                    throw ValidationException::withMessages(['part_id' => 'Вложение повреждено или превышает допустимый размер.']);
                }
                $path = 'gmail-profile-drafts/'.Str::uuid().'.'.$type->value;
                $disk = Storage::disk(config('price-list-imports.disk'));
                if (! $disk->put($path, $bytes)) {
                    throw new \RuntimeException('private_storage_unavailable');
                }
                try {
                    $this->validator->validate($disk->path($path), $part->getFilename(), $type);
                    $inspection = $this->samples->inspect($path, $type->value);

                    return ['sample' => ['path' => $path, 'filename' => $part->getFilename(), 'extension' => $type->value, 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'worksheets' => $inspection['worksheets']], 'sender' => $sender];
                } catch (Throwable $exception) {
                    $disk->delete($path);
                    throw $exception;
                }
            });
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['part_id'])) {
                throw $exception;
            }
            throw ValidationException::withMessages(['part_id' => 'Файл не прошёл проверку. Проверьте формат, размер и содержимое таблицы.']);
        } catch (Throwable $exception) {
            report($exception);
            throw new GmailException('Не удалось подготовить вложение. Проверьте подключение Gmail и повторите попытку.');
        }
        $token = (string) Str::uuid();
        $request->session()->put('gmail_profile_drafts.'.$token, $metadata + ['user' => $request->user()->id, 'supplier' => $supplier->id, 'connection' => $connection->id, 'mailbox' => mb_strtolower($connection->email), 'message' => $messageId, 'expires' => now()->addDay()->timestamp]);

        return $token;
    }

    public function get(Request $request, int $supplier): ?array
    {
        if (! $request->has('gmail_draft')) {
            return null;
        }
        $token = $request->input('gmail_draft');
        abort_unless(is_string($token) && Str::isUuid($token), 404);
        $connection = $this->authorize($request);
        $draft = $request->session()->get('gmail_profile_drafts.'.$token);
        abort_unless(is_array($draft) && $draft['user'] === $request->user()->id && $draft['supplier'] === $supplier && $draft['connection'] === $connection->id && $draft['mailbox'] === mb_strtolower($connection->email), 404);
        abort_unless($draft['expires'] > now()->timestamp && Storage::disk(config('price-list-imports.disk'))->exists($draft['sample']['path']), 410, 'Черновик устарел. Откройте настройку вложения заново.');

        return $draft;
    }
}
