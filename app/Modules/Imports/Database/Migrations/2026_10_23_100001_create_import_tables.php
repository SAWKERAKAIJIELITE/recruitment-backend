<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Excel import batches and their rows (analysis doc §09, D-040, D-041).
 *
 * A batch is one uploaded file: its original is kept, every row is recorded with what the cells said, what
 * the system understood, and what is wrong with it, and nothing becomes an application until the batch is
 * approved. Batches and rows are never deleted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The file: kept unchanged as the batch's evidence.
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');

            // What was read: the sheet, the header row, and which column became which field.
            $table->string('sheet_name', 100);
            $table->unsignedInteger('header_row');
            $table->jsonb('column_map');        // field => {column, header}
            $table->jsonb('ignored_columns');   // [{column, header}] headers the import does not use

            // Where the file comes from (optional; the entity also fills rows whose entity cell is empty).
            $table->foreignId('nominating_entity_id')->nullable()->constrained('nominating_entities')->restrictOnDelete();
            $table->foreignId('source_district_id')->nullable()->constrained('districts')->restrictOnDelete();
            $table->text('notes')->nullable();

            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('pending')->index();
            $table->foreignUuid('uploaded_by')->index()->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index('created_at');
        });

        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_approved_is_signed CHECK (status <> 'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_cancelled_is_explained CHECK (status <> 'cancelled' OR (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND btrim(coalesce(cancel_reason, '')) <> ''))");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_sha256_format CHECK (file_sha256 ~ '^[0-9a-f]{64}$')");
        // The same file cannot be imported twice (AC-10); a cancelled batch frees it.
        DB::statement("CREATE UNIQUE INDEX import_batches_one_live_per_file ON import_batches (file_sha256) WHERE status <> 'cancelled'");

        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            $table->unsignedInteger('row_number');      // the row in the Excel sheet

            $table->jsonb('raw');                       // column letter => the cell as displayed in Excel
            $table->jsonb('data');                      // dotted application input key => understood value
            $table->jsonb('parse_issues');              // key => {code, message, value}: what could not be read
            $table->jsonb('issues');                    // [{field, code, severity, message}]: from the last check

            $table->enum('status', ['valid', 'needs_review', 'invalid', 'excluded', 'imported'])->index();
            $table->foreignUuid('candidate_id')->nullable()->index()->constrained('candidates')->restrictOnDelete();

            // "Same person" confirmed by staff, for the national number it was confirmed for.
            $table->string('confirmed_national_number', 11)->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->foreignUuid('excluded_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('excluded_at')->nullable();
            $table->text('exclude_reason')->nullable();

            // The application the row became. One row, one application.
            $table->foreignUuid('application_id')->nullable()->unique()->constrained('applications')->restrictOnDelete();
            $table->timestampTz('imported_at')->nullable();

            $table->timestampsTz();

            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'status']);
        });

        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_imported_has_application CHECK ((status = 'imported') = (application_id IS NOT NULL AND imported_at IS NOT NULL))");
        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_excluded_is_explained CHECK (status <> 'excluded' OR (excluded_by IS NOT NULL AND btrim(coalesce(exclude_reason, '')) <> ''))");
        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_row_number_positive CHECK (row_number >= 1)");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
