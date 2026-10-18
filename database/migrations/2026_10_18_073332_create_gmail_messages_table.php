<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mailbox_email');
            $table->string('gmail_message_id');
            $table->timestamp('received_at');
            $table->text('sender');
            $table->text('subject');
            $table->text('snippet');
            $table->boolean('unread');
            $table->json('attachment_manifest');
            $table->longText('plain_body');
            $table->longText('html_body');
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['user_id', 'mailbox_email', 'gmail_message_id'], 'gmail_message_owner_unique');
            $table->index(['user_id', 'mailbox_email', 'received_at', 'id'], 'gmail_message_chronological');
        });
        /** Existing discovery checkpoints describe receipts, rather than saved messages. */
        DB::table('gmail_connections')->update(array_fill_keys(['history_cursor', 'scan_baseline', 'scan_page', 'history_page', 'scan_completed_at', 'last_synced_at'], null));
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_messages');
    }
};
