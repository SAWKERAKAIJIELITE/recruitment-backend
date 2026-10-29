<?php

use App\Modules\Recruitment\Enums\ApplicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents along the application's life (D-057).
 *
 * A document type says what a file is (صورة البطاقة الشخصية, العقد, ...), whether it belongs to the person
 * (reused by every application) or to one application, whether the candidate brings it or the organization issues
 * it, and from which status on an application it is required. Required documents that are missing are shown, never
 * blocking (owner's choice).
 *
 * A document row is the evidence of an upload: it is never changed or deleted, only archived once, with a reason.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_key', 200)->unique();
            $table->string('scope', 20);                       // candidate | application
            $table->string('direction', 20);                   // incoming (brought by the candidate) | outgoing (issued)
            $table->string('required_from_status', 30)->nullable();   // null: optional
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        $statuses = "'" . implode("', '", array_column(ApplicationStatus::cases(), 'value')) . "'";
        DB::statement("ALTER TABLE document_types ADD CONSTRAINT document_types_scope CHECK (scope IN ('candidate', 'application'))");
        DB::statement("ALTER TABLE document_types ADD CONSTRAINT document_types_direction CHECK (direction IN ('incoming', 'outgoing'))");
        DB::statement("ALTER TABLE document_types ADD CONSTRAINT document_types_required_from_status CHECK (required_from_status IS NULL OR required_from_status IN ({$statuses}))");

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->foreignUuid('candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->uuid('application_id')->nullable();        // null: a document of the person
            $table->string('original_filename', 255);
            $table->string('stored_path', 300)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('notes')->nullable();
            $table->foreignUuid('uploaded_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('archived_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->text('archive_reason')->nullable();

            // The application, when given, is the same person's.
            $table->foreign(['application_id', 'candidate_id'], 'documents_application_of_candidate')
                ->references(['id', 'candidate_id'])->on('applications')->restrictOnDelete();
            $table->index(['candidate_id', 'document_type_id']);
            $table->index('application_id');
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_archive_explained CHECK ((archived_at IS NULL AND archived_by IS NULL AND archive_reason IS NULL) OR (archived_at IS NOT NULL AND archived_by IS NOT NULL AND btrim(coalesce(archive_reason, '')) <> ''))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_size_positive CHECK (size_bytes > 0)');

        // A document of a person-level type has no application; one of an application-level type has one.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION documents_match_type_scope() RETURNS trigger AS
            $$
                DECLARE
                    type_scope text;
                BEGIN
                    SELECT scope INTO type_scope FROM document_types WHERE id = NEW.document_type_id;

                    IF (type_scope = 'application') <> (NEW.application_id IS NOT NULL) THEN
                        RAISE EXCEPTION 'A document of a % type % an application', type_scope, CASE WHEN type_scope = 'application' THEN 'needs' ELSE 'cannot have' END
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER documents_scope BEFORE INSERT ON documents FOR EACH ROW EXECUTE FUNCTION documents_match_type_scope()');

        // The upload itself never changes; the only change is archiving it, once. Rows are never deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION documents_archive_only() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Documents are archived, never deleted' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.archived_at IS NOT NULL
                        OR (to_jsonb(NEW) - ARRAY['archived_at', 'archived_by', 'archive_reason']) <> (to_jsonb(OLD) - ARRAY['archived_at', 'archived_by', 'archive_reason']) THEN
                        RAISE EXCEPTION 'Document % cannot change: only archiving it, once, is allowed', OLD.id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER documents_immutable BEFORE UPDATE OR DELETE ON documents FOR EACH ROW EXECUTE FUNCTION documents_archive_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
        DB::unprepared('DROP FUNCTION IF EXISTS documents_archive_only()');
        DB::unprepared('DROP FUNCTION IF EXISTS documents_match_type_scope()');
    }
};
