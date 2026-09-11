<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReceiptController extends Controller
{
    public function show(PaymentRequest $paymentRequest): StreamedResponse
    {
        $this->authorize('view', $paymentRequest);
        abort_unless($paymentRequest->receipt_path && Storage::disk('local')->exists($paymentRequest->receipt_path), 404);

        return Storage::disk('local')->response($paymentRequest->receipt_path, 'receipt-'.$paymentRequest->id);
    }
}
