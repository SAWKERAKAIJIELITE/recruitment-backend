<?php

use App\Modules\Recruitment\Support\StatusEventCauseColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts (analysis doc §08 "كتابة العقد الأول"; owner: contracts are renewed; D-064).
 *
 * The first contract of an application is written after a passed interview and moves the application to
 * contract_written. A renewal is a new contract that renews the previous one, so an application has a chain:
 * 1 → 2 → 3 ... When the latest contract ends without a renewal, staff record the end and the application becomes
 * contract_ended. "Written" does not mean signed or appointed (§08). Contracts are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Managed list: contract types (نوع العقد), like the lists of the Recruitment module (D-024, D-034).
        Schema::create('contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_key', 200)->unique();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Managed list: the units a contractor joins (الجهة الملتحق بها: السوية التنظيمية الثانية, D-098), like
        // the contract types; seeded from the «الجهة» list of the owner's ملف التثبيت.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_key', 200)->unique();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // The job code (الرمز الوظيفي) of a job position, printed on its contracts (D-098).
        Schema::table('job_positions', function (Blueprint $table) {
            $table->string('job_code', 50)->nullable();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');                       // 1: the first contract; then renewals
            $table->uuid('renews_contract_id')->nullable()->unique();      // a contract is renewed at most once
            $table->foreignId('contract_type_id')->constrained('contract_types')->restrictOnDelete();
            $table->string('reference_number', 100)->unique();             // رقم المرجع
            $table->date('written_on');                                     // تاريخ الكتابة
            $table->date('starts_on');
            $table->date('ends_on')->nullable();                            // null: no end date
            $table->uuid('document_id')->nullable()->unique();             // the contract's file (Documents)
            $table->text('notes')->nullable();
            // The contract form (تعاقد عمل) and ملف التثبيت (D-098): entered by the Contracts office from the documents
            // the form asks for (the ID, the family statement, the certificate); a renewal copies them. Optional: a
            // missing one is left blank on the form.
            $table->foreignId('unit_id')->nullable()->index()->constrained('units')->restrictOnDelete();   // السوية الثانية
            $table->string('level_3', 150)->nullable();                     // السوية التنظيمية الثالثة
            $table->string('level_4', 150)->nullable();                     // الرابعة
            $table->string('level_5', 150)->nullable();                     // الخامسة
            $table->string('level_6', 150)->nullable();                     // السادسة
            $table->string('workplace', 200)->nullable();                   // مكان العمل
            $table->string('work_pattern', 100)->nullable();                // نمط العمل
            $table->string('job_specialty', 150)->nullable();               // التخصص
            $table->string('work_schedule', 100)->nullable();               // نظام الدوام
            $table->decimal('salary_usd', 10, 2)->nullable();               // قيمة الراتب بالدولار
            $table->string('certificate_type', 20)->nullable();            // نوع الشهادة (CertificateType)
            $table->string('university', 200)->nullable();                  // اسم الجامعة
            $table->decimal('gpa', 5, 2)->nullable();                       // المعدل بالنسبة المئوية
            $table->date('certificate_date')->nullable();                   // تاريخ الحصول على الشهادة
            $table->unsignedSmallInteger('wives_count')->nullable();        // عدد الزوجات
            $table->unsignedSmallInteger('children_count')->nullable();     // عدد الأبناء
            $table->string('origin', 150)->nullable();                      // البلد الأصلي (else the place of birth)
            // The end without renewal (on the latest contract only): the application becomes contract_ended.
            $table->date('ended_on')->nullable();
            $table->text('end_reason')->nullable();
            $table->foreignUuid('ended_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('ended_at')->nullable();
            // When the "ends soon" reminder went out; cleared when the end date is corrected.
            $table->timestampTz('expiry_reminded_at')->nullable();
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['application_id', 'sequence']);
            $table->unique(['id', 'application_id']);
            $table->index('ends_on');
            // A renewal renews a contract of the same application.
            $table->foreign(['renews_contract_id', 'application_id'], 'contracts_renews_same_application')
                ->references(['id', 'application_id'])->on('contracts')->restrictOnDelete();
            // The file is a document of the same application.
            $table->foreign(['document_id', 'application_id'], 'contracts_document_of_application')
                ->references(['id', 'application_id'])->on('documents')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_sequence_positive CHECK (sequence > 0)');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_first_renews_nothing CHECK ((sequence = 1) = (renews_contract_id IS NULL))');
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_reference_number CHECK (btrim(reference_number) <> '')");
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_period CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_certificate_type CHECK (certificate_type IS NULL OR certificate_type IN ('preparatory', 'secondary', 'institute', 'bachelor_4', 'bachelor_5', 'bachelor_6', 'master', 'doctorate'))");
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_form_ranges CHECK ((salary_usd IS NULL OR salary_usd >= 0) AND (gpa IS NULL OR gpa BETWEEN 0 AND 100) AND (wives_count IS NULL OR wives_count <= 4) AND (children_count IS NULL OR children_count <= 30))');
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_end_explained CHECK ((ended_on IS NULL AND end_reason IS NULL AND ended_by IS NULL AND ended_at IS NULL) OR (ended_on IS NOT NULL AND ended_by IS NOT NULL AND ended_at IS NOT NULL AND btrim(coalesce(end_reason, '')) <> '' AND ended_on >= written_on))");
        // One end per application.
        DB::statement('CREATE UNIQUE INDEX contracts_one_end_per_application ON contracts (application_id) WHERE ended_on IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION contracts_never_deleted() RETURNS trigger AS
            $$
                BEGIN
                    RAISE EXCEPTION 'Contracts are never deleted' USING ERRCODE = 'integrity_constraint_violation';
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER contracts_no_delete BEFORE DELETE ON contracts FOR EACH ROW EXECUTE FUNCTION contracts_never_deleted()');

        // The chain: a renewal follows the previous contract (next number, not before its end, which must exist), an
        // ended contract is not renewed, and only the latest contract can end.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION contracts_chain() RETURNS trigger AS
            $$
                DECLARE
                    previous RECORD;
                    renewal RECORD;
                BEGIN
                    IF NEW.renews_contract_id IS NOT NULL THEN
                        SELECT sequence, ends_on, ended_on INTO previous FROM contracts WHERE id = NEW.renews_contract_id FOR UPDATE;

                        IF NEW.sequence <> previous.sequence + 1 THEN
                            RAISE EXCEPTION 'A renewal takes the next number of the chain' USING ERRCODE = 'check_violation';
                        END IF;

                        IF previous.ends_on IS NULL OR NEW.starts_on < previous.ends_on THEN
                            RAISE EXCEPTION 'A renewal starts at the end of the contract it renews or later' USING ERRCODE = 'check_violation';
                        END IF;

                        IF TG_OP = 'INSERT' AND previous.ended_on IS NOT NULL THEN
                            RAISE EXCEPTION 'An ended contract is not renewed' USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        SELECT starts_on INTO renewal FROM contracts WHERE renews_contract_id = NEW.id;

                        IF FOUND AND (NEW.ends_on IS NULL OR NEW.ends_on > renewal.starts_on) THEN
                            RAISE EXCEPTION 'A renewed contract ends no later than its renewal starts' USING ERRCODE = 'check_violation';
                        END IF;

                        IF FOUND AND NEW.ended_on IS NOT NULL THEN
                            RAISE EXCEPTION 'Only the latest contract of an application can end' USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER contracts_chain BEFORE INSERT OR UPDATE ON contracts FOR EACH ROW EXECUTE FUNCTION contracts_chain()');

        // The contract behind a status change: the first contract (contract_written) and the end (contract_ended).
        StatusEventCauseColumns::add('contract_id', 'contracts');
    }

    public function down(): void
    {
        StatusEventCauseColumns::remove('contract_id');
        Schema::dropIfExists('contracts');
        Schema::table('job_positions', fn(Blueprint $table) => $table->dropColumn('job_code'));
        Schema::dropIfExists('units');
        Schema::dropIfExists('contract_types');
        DB::unprepared('DROP FUNCTION IF EXISTS contracts_never_deleted()');
        DB::unprepared('DROP FUNCTION IF EXISTS contracts_chain()');
    }
};
