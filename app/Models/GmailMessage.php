<?php

namespace App\Models;

use App\Services\GmailMessageReader;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'mailbox_email', 'gmail_message_id', 'received_at', 'sender', 'subject', 'snippet', 'unread', 'attachment_manifest', 'plain_body', 'html_body', 'synced_at'])]
class GmailMessage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime', 'unread' => 'boolean', 'attachment_manifest' => 'array'];
    }

    public function snapshot(bool $body = false): array
    {
        $data = ['id' => $this->gmail_message_id, 'from' => $this->sender, 'subject' => $this->subject, 'date' => $this->received_at->toRfc2822String(), 'snippet' => $this->snippet, 'unread' => $this->unread, 'attachments' => $this->attachment_manifest];
        if ($body) {
            $data += ['plain' => $this->plain_body, 'html' => $this->html_body, 'document' => app(GmailMessageReader::class)->document($this->html_body)];
        }

        return $data;
    }
}
