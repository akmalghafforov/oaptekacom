<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GmailClientFactory;
use App\Services\GmailException;
use App\Services\GmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GmailController extends Controller
{
    public function __construct(private GmailService $gmail, private GmailClientFactory $clients) {}

    public function index(Request $request): View
    {
        $input = $request->validate(['page' => ['nullable', 'string', 'max:2048'], 'size' => ['nullable', 'integer', 'between:1,50']]);
        $connection = $request->user()->gmailConnection()->first();
        $inbox = ['messages' => [], 'next' => null];
        $error = null;
        if (! $this->clients->configured()) {
            $error = 'Gmail не настроен. Обратитесь к администратору сервера.';
        } elseif ($connection?->status === 'connected') {
            try {
                $inbox = $this->gmail->inbox($request->user(), (int) ($input['size'] ?? 20), $input['page'] ?? null);
            } catch (GmailException $exception) {
                $error = $exception->getMessage();
                $connection = $request->user()->gmailConnection()->first();
            }
        }

        return view('admin.gmail.index', ['connection' => $connection, 'inbox' => $inbox, 'error' => $error, 'configured' => $this->clients->configured(), 'size' => (int) ($input['size'] ?? 20)]);
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
            return view('admin.gmail.show', ['message' => $this->gmail->message($request->user(), $message)]);
        } catch (GmailException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    public function refresh(): RedirectResponse
    {
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
