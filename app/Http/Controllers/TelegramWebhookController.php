<?php

namespace App\Http\Controllers;

use App\Services\TelegramSupplierReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramSupplierReviews $reviews): JsonResponse
    {
        abort_unless($reviews->configured() && hash_equals((string) config('telegram.webhook_secret'), (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        $callback = $request->input('callback_query');
        if (! is_array($callback)) {
            return response()->json(['ok' => true]);
        }
        $validator = Validator::make($callback, [
            'id' => ['required', 'string', 'max:256'],
            'data' => ['required', 'string', 'max:64'],
            'from' => ['required', 'array'],
            'from.id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'array'],
            'message.message_id' => ['required', 'integer', 'min:1'],
            'message.chat' => ['required', 'array'],
            'message.chat.id' => ['required', 'integer'],
        ]);
        $text = 'Некорректная заявка.';
        if (! $validator->fails()) {
            try {
                $text = $reviews->decide($validator->validated());
            } catch (ValidationException $exception) {
                $text = mb_substr(implode(' ', $exception->validator->errors()->all()), 0, 180);
            }
        }
        if (is_string($callback['id'] ?? null) && strlen($callback['id']) <= 256) {
            try {
                Http::connectTimeout(3)->timeout(5)->post('https://api.telegram.org/bot'.config('telegram.bot_token').'/answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => $text]);
            } catch (\Throwable) {
                // Acknowledgment failure must not undo a committed decision.
            }
        }

        return response()->json(['ok' => true]);
    }
}
