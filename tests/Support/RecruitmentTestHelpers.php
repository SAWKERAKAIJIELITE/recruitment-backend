<?php

namespace Tests\Support;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\AcademicQualification;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ApplicationCategory;
use App\Modules\Recruitment\Models\Area;
use App\Modules\Recruitment\Models\District;
use App\Modules\Recruitment\Models\HealthCondition;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Recruitment\Models\MaritalStatus;
use App\Modules\Recruitment\Models\NominatingEntity;
use App\Modules\Recruitment\Models\Specialization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait RecruitmentTestHelpers
{
    /** One entry of each managed list, created on first use and shared by every payload of the test. */
    private array $referenceIds = [];

    protected function jobPosition(string $name = 'مدرس'): JobPosition
    {
        return JobPosition::create(['name_ar' => $name]);
    }

    protected function district(string $name = 'دمشق'): District
    {
        return District::create(['name_ar' => $name]);
    }

    protected function area(District $district, string $name = 'المزة'): Area
    {
        return Area::create(['district_id' => $district->id, 'name_ar' => $name]);
    }

    /** A user of the Recruitment office (candidates, applications, imports; D-085). */
    protected function hiringStaff(): StaffUser
    {
        return $this->staffOf('recruitment');
    }

    /** A user of the Exams and Interviews office. */
    protected function examsStaff(): StaffUser
    {
        return $this->staffOf('exams_interviews');
    }

    /** A user of the Contracts office. */
    protected function contractsStaff(): StaffUser
    {
        return $this->staffOf('contracts');
    }

    /** A user of the Documenting office. */
    protected function documentsStaff(): StaffUser
    {
        return $this->staffOf('documenting');
    }

    /** A user holding the role (recruitment, exams_interviews, contracts, documenting, general_manager, system_admin). */
    protected function staffOf(string $role): StaffUser
    {
        return StaffUserFactory::new()->withRole($role)->create();
    }

    /**
     * A vacancies plan in force from 2000 to 2099 with $count vacancies for every job position there is now (D-088),
     * written straight to the database (the upload is tested on its own).
     */
    protected function openVacancies(int $count = 100): string
    {
        $id = (string) Str::uuid7();
        DB::table('vacancy_plans')->insert([
            'id' => $id, 'starts_on' => '2000-01-01', 'ends_on' => '2099-12-31', 'original_filename' => 'test.xlsx', 'stored_path' => 'vacancies/test.xlsx',
            'file_sha256' => str_repeat('0', 64), 'file_size' => 1, 'uploaded_by' => $this->staffOf('system_admin')->id, 'created_at' => now(),
        ]);
        DB::table('vacancy_plan_items')->insert(JobPosition::query()->pluck('id')->values()->map(fn (int $position, int $row) => [
            'plan_id' => $id, 'job_position_id' => $position, 'vacancies' => $count, 'row_number' => $row + 2,
        ])->all());

        return $id;
    }

    /** Ends an application through the lifecycle, as authorised staff would. */
    protected function closeApplication(Application $application): void
    {
        app(ApplicationLifecycle::class)->move($application, ApplicationStatus::Closed, DecisionType::Closure, $this->hiringStaff(), 'Closed for the test');
    }

    /** @return array<string, int> the default reference entry of each list, keyed by request field */
    protected function referenceIds(): array
    {
        if ($this->referenceIds !== []) {
            return $this->referenceIds;
        }

        $district = $this->district();

        return $this->referenceIds = [
            'nominating_entity_id' => NominatingEntity::create(['name_ar' => 'مديرية أوقاف دمشق'])->id,
            'job_position_id' => $this->jobPosition()->id,
            'application_category_id' => ApplicationCategory::create(['name_ar' => 'الفئة الأولى'])->id,
            'academic_qualification_id' => AcademicQualification::create(['name_ar' => 'إجازة جامعية'])->id,
            'specialization_id' => Specialization::create(['name_ar' => 'شريعة'])->id,
            'marital_status_id' => MaritalStatus::create(['name_ar' => 'أعزب'])->id,
            'health_condition_id' => HealthCondition::create(['name_ar' => 'سليم'])->id,
            'residence_district_id' => $district->id,
            'residence_area_id' => $this->area($district)->id,
            'birth_place_id' => $district->id,
        ];
    }

    /** A valid request body; pass overrides (merged recursively) to change parts of it. */
    protected function applicationPayload(array $overrides = []): array
    {
        $ids = $this->referenceIds();

        $base = [
            'nominating_entity_id' => $ids['nominating_entity_id'],
            'job_position_id' => $ids['job_position_id'],
            'application_category_id' => $ids['application_category_id'],
            'academic_qualification_id' => $ids['academic_qualification_id'],
            'specialization_id' => $ids['specialization_id'],
            'studying_years' => 12,
            'quran_parts_memorized' => 5,
            'marital_status_id' => $ids['marital_status_id'],
            'health_condition_id' => $ids['health_condition_id'],
            'residence_district_id' => $ids['residence_district_id'],
            'residence_area_id' => $ids['residence_area_id'],
            'residence_details' => 'شارع الجلاء',
            'phone_number' => '0912345678',
            'works_for_other_government_entity' => false,
            'full_commitment' => true,
            'overnight_work' => true,
            'candidate' => [
                'national_number' => '01234567890',
                'full_name' => 'أحمد محمد علي',
                'mother_name' => 'فاطمة حسن',
                'birth_date' => '1998-03-01',
                'birth_place_id' => $ids['birth_place_id'],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    /** Opens an application through the real action (no HTTP), started (awaiting its exam) unless $start is false. */
    protected function openApplication(?StaffUser $actor = null, array $overrides = [], bool $start = true): Application
    {
        return app(CreateApplication::class)
            ->execute($this->applicationPayload($overrides), $actor ?? $this->hiringStaff(), $start)['application'];
    }
}
