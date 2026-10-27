<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('osonsms_log', function (Blueprint $table): void {
            $table->id();
            $table->string('login');
            $table->string('sender_name', 11);
            $table->string('message', 2500);
            $table->string('phonenumber', 13);
            $table->string('server_response', 2500)->nullable();
            $table->string('msgid', 20)->nullable();
            $table->tinyInteger('is_sent')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('osonsms_log');
    }
};
