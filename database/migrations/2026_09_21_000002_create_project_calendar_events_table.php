<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency map for Google Calendar pushes.
     *
     * One row per (project, user, event key). The event key is `start`,
     * `due`, or `inspection:{baseline_schedule_id}`. Storing Google's event
     * id lets later syncs UPDATE the same event (e.g. deadline moved by an
     * extension) instead of creating duplicates, and lets stale inspection
     * events be deleted when a baseline month is removed.
     */
    public function up(): void
    {
        Schema::create('project_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->foreignId('baseline_schedule_id')->nullable()->constrained('project_baseline_schedules')->nullOnDelete();
            $table->string('google_event_id', 255)->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 32)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'event_key'], 'pce_project_user_key_unique');
            $table->index(['user_id', 'target_date'], 'pce_user_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_calendar_events');
    }
};
