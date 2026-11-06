<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what (analysis doc §07, §17: "no one deletes audit events or changes marks quietly from the interface";
 * D-078). One row per action, written in the same transaction as the action itself, so an action that is rolled
 * back leaves no trace and an action that stays always has its row. Append-only: never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 100);                           // e.g. interview.recorded, access.role_assigned
            $table->string('category', 20);                         // operations | security (who may read it)
            $table->foreignUuid('actor_id')->nullable()->constrained('staff_users')->restrictOnDelete();   // null: the system or a candidate
            $table->foreignUuid('candidate_id')->nullable()->constrained('candidates')->restrictOnDelete(); // a candidate's own action
            $table->jsonb('context')->default('{}');
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at');

            $table->index(['created_at', 'id']);
            $table->index(['event', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['category', 'created_at']);
        });

        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_category CHECK (category IN ('operations', 'security'))");
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_event CHECK (event ~ '^[a-z_]+(\\.[a-z_]+)+$')");
        DB::statement('CREATE INDEX audit_events_context ON audit_events USING gin (context jsonb_path_ops)');
        DB::unprepared('CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()');
        DB::unprepared('CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
