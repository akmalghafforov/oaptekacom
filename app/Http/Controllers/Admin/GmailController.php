<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Jobs\SyncGmailMessages;
use App\Models\GmailMessage;
use App\Models\Organization;
use App\Services\GmailClientFactory;
use App\Services\GmailException;
use App\Services\GmailImportReplay;
use App\Services\GmailImportStatus;
use App\Services\GmailProfileDrafts;
use App\Services\GmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GmailController extends Controller
{
    public function __construct(private GmailService $gmail, private GmailClientFactory $clients) {}

    public function index(Request $request): View
    {
        $input = $request->validate(['page' => ['nullable', 'string', 'max:2048'], 'size' => ['nullable', 'integer', 'between:1,50'], 'import_status' => ['nullable', Rule::in(array_keys(GmailImportStatus::FILTERS))]]);
        $connection = $request->user()->gmailConnection()->first();
        $inbox = ['messages' => [], 'next' => null];
        $error = null;
        if (! $this->clients->configured()) {
            $error = 'Gmail не настроен. Обратитесь к администратору сервера.';
        }
        if ($connection) {
            try {
                $inbox = $this->gmail->inbox($request->user(), (int) ($input['size'] ?? 20), $input['page'] ?? null, $input['import_status'] ?? null);
            } catch (GmailException $exception) {
                $error = $exception->getMessage();
                $connection = $request->user()->gmailConnection()->first();
            }
        }

        $canImport = $this->canImport($request);
        if ($connection && $inbox['messages']) {
            $receipts = app(GmailImportStatus::class)->receipts($connection->email, array_column($inbox['messages'], 'id'))->where('receipt_scope', 'message')->keyBy('gmail_message_id');
            foreach ($inbox['messages'] as &$message) {
                $message['inbox_id'] = $receipts->get($message['id'])?->id;
            }
            unset($message);
        }

        return view('admin.gmail.index', ['savedCount' => $connection ? GmailMessage::where('user_id', $request->user()->id)->where('mailbox_email', strtolower($connection->email))->count() : 0, 'canImport' => $canImport, 'connection' => $connection, 'inbox' => $inbox, 'error' => $error, 'configured' => $this->clients->configured(), 'size' => (int) ($input['size'] ?? 20), 'importStatus' => $input['import_status'] ?? null]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $request->session()->forget('gmail_oauth');
        try {
            $client = $this->clients->make();
            $state = bin2hex(random_bytes(32));
            $request->session()->put('gmail_oauth', ['hash' => hash('sha256', $state), 'user' => $request->user()->id, 'session' => hash('sha256', $request->session()->getId()), 'expires' => now()->addMinutes(10)->timestamp]);
            $client->setState($state);

            return redirect()->away($client->createAuthUrl());
        } catch (GmailException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('gmail_oauth');
        $request->session()->save();
        $state = $request->query('state');
        if (! is_array($pending) || ! is_string($state) || ! hash_equals($pending['hash'], hash('sha256', $state)) || $pending['user'] !== $request->user()->id || ! hash_equals($pending['session'], hash('sha256', $request->session()->getId())) || $pending['expires'] <= now()->timestamp) {
            return $this->failure('Подключение устарело или не подтверждено. Начните заново.');
        }
        if ($request->query('error') !== null) {
            return $this->failure('Подключение Gmail отменено.');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->failure('Google не передал код подключения.');
        }
        try {
            $this->gmail->connect($request->user(), $code);

            return redirect()->route('admin.gmail.index')->with('success', 'Gmail подключён.');
        } catch (GmailException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    public function show(Request $request, string $message): View|RedirectResponse
    {
        try {
            $data = $this->gmail->message($request->user(), $message);
            $canImport = $this->canImport($request);
            $active = false;
            if ($request->user()->gmailConnection()->exists()) {
                $status = app(GmailImportStatus::class)->decorate($request->user()->gmailConnection()->first()->email, [$data]);
                $data = $status['messages'][0];
                $active = $status['active'];
            }

            return view('admin.gmail.show', ['message' => $data, 'canImport' => $canImport, 'active' => $active, 'selectedPart' => $request->query('part_id'), 'suppliers' => $canImport ? Organization::where('type', OrganizationType::Wholesaler)->orderBy('name')->get(['id', 'name']) : collect()]);
        } catch (GmailException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private function canImport(Request $request): bool
    {
        try {
            app(GmailProfileDrafts::class)->authorize($request);

            return true;
        } catch (HttpException|GmailException) {
            return false;
        }
    }

    public function importProfile(Request $request, string $message, GmailProfileDrafts $drafts): RedirectResponse
    {
        $drafts->authorize($request);
        $input = $request->validate(['part_id' => ['required', 'string', 'max:255'], 'supplier_organization_id' => ['required', 'integer']]);
        $supplier = Organization::where('type', OrganizationType::Wholesaler)->findOrFail($input['supplier_organization_id']);
        try {
            $token = $drafts->stage($request, $supplier, $message, $input['part_id']);

            return redirect()->route('admin.supplier-import-profiles.edit', ['supplier' => $supplier, 'gmail_draft' => $token]);
        } catch (GmailException $exception) {
            return redirect()->route('admin.gmail.show', $message)->with('warning', $exception->getMessage());
        }
    }

    public function replay(Request $request, string $message, GmailProfileDrafts $drafts, GmailImportReplay $replay): RedirectResponse
    {
        $connection = $drafts->authorize($request);
        try {
            $replay->message($connection->email, $message);
        } catch (GmailException $exception) {
            return redirect()->route('admin.gmail.show', $message)->with('warning', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.gmail.show', $message)->with('warning', 'Не удалось подготовить импорт. Повторите попытку позже.');
        }

        return redirect()->route('admin.gmail.show', $message)->with('success', 'Импорт поставлен в очередь или уже обрабатывается. Завершённые импорты сохранены; для ошибочного прайс-листа используйте его страницу повтора.');
    }

    public function importStatus(Request $request, string $message, GmailProfileDrafts $drafts, GmailImportStatus $status): JsonResponse
    {
        $connection = $drafts->authorize($request);
        $receipts = $status->receipts($connection->email, [$message]);
        $saved = GmailMessage::where('user_id', $connection->user_id)->where('mailbox_email', strtolower($connection->email))->where('gmail_message_id', $message)->first();
        $attachments = $saved ? collect($saved->attachment_manifest)->keyBy('part_id')->all() : [];
        if (! $saved) {
            foreach ($receipts as $receipt) {
                foreach ($receipt->attachment_manifest ?? [] as $entry) {
                    $id = (string) ($entry['part_id'] ?? '');
                    $attachments[$id] = ['part_id' => $id, 'name' => $entry['filename'], 'size' => $entry['size'] ?? 0];
                }
            }
        }
        $result = $status->decorate($connection->email, [['id' => $message, 'attachments' => array_values($attachments)]]);

        return response()->json(['active' => $result['active'], 'reason' => $result['messages'][0]['intake_reason'], 'attachments' => $result['messages'][0]['attachments']]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $connection = $request->user()->gmailConnection()->first();
        if ($connection?->status === 'connected') {
            SyncGmailMessages::dispatch($connection->id);
        }

        return redirect()->route('admin.gmail.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('gmail_oauth');
        try {
            $revoked = $this->gmail->disconnect($request->user());

            return redirect()->route('admin.gmail.index')->with($revoked ? 'success' : 'warning', $revoked ? 'Gmail отключён.' : 'Gmail отключён локально. Отзыв доступа Google не подтверждён; удалите разрешение в аккаунте Google.');
        } catch (GmailException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()->route('admin.gmail.index')->with('warning', $message);
    }
}
