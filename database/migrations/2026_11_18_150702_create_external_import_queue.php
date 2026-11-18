<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_import_queue_lanes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('owner_item_id')->nullable();
        });
        Schema::create('external_import_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->string('lane_id');
            $table->foreign('lane_id')->references('id')->on('external_import_queue_lanes');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supplier_sync_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('external_download_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('price_list_import_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('group_id');
            $table->string('state')->default('queued')->index();
            $table->string('wait_reason')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('enqueued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('recovery_deadline')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->uuid('worker_token')->nullable();
            $table->timestamp('worker_expires_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('dispatch_requested_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['lane_id', 'supplier_sync_run_id', 'generation'], 'external_queue_execution_unique');
            $table->index(['lane_id', 'finished_at', 'id']);
        });
        Schema::table('supplier_sync_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('execution_queue_item_id')->nullable()->index();
        });
        DB::table('external_import_queue_lanes')->insert(['id' => 'system']);
        DB::table('supplier_sync_runs')->whereNotNull('active_supplier_id')->where('trigger', '!=', 'mailbox')
            ->where(function ($query): void {
                $query->whereIn('configuration_snapshot->selected_source', ['icorp', 'pricelist_tj'])
                    ->orWhere('configuration_snapshot->sources->icorp->enabled', true)->orWhere('configuration_snapshot->sources->pricelist_tj->enabled', true)
                    ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('supplier_sync_attempts')->whereColumn('supplier_sync_run_id', 'supplier_sync_runs.id')->whereIn('source', ['icorp', 'pricelist_tj']));
            })->orderBy('id')->eachById(function (object $run): void {
                $id = DB::table('external_import_queue_items')->insertGetId([
                    'lane_id' => 'system', 'supplier_sync_run_id' => $run->id, 'group_id' => (string) Str::uuid(),
                    'enqueued_at' => $run->created_at, 'dispatch_requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('supplier_sync_runs')->where('id', $run->id)->update(['execution_queue_item_id' => $id]);
            });
    }

    public function down(): void
    {
        Schema::table('supplier_sync_runs', fn (Blueprint $table) => $table->dropColumn('execution_queue_item_id'));
        Schema::dropIfExists('external_import_queue_items');
        Schema::dropIfExists('external_import_queue_lanes');
    }
};
