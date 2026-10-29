<?php

namespace App\Modules\Recruitment\Database\Seeders\Demo;

use App\Modules\Recruitment\Models\AcademicQualification;
use App\Modules\Recruitment\Models\ApplicationCategory;
use App\Modules\Recruitment\Models\Area;
use App\Modules\Recruitment\Models\District;
use App\Modules\Recruitment\Models\HealthCondition;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Recruitment\Models\MaritalStatus;
use App\Modules\Recruitment\Models\NominatingEntity;
use App\Modules\Recruitment\Models\Specialization;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * The active list entries the demo data picks from. Every list, the nominating entities included, comes from
 * ReferenceDataSeeder, which runs first.
 */
final class DemoReferences
{
    /** @var array<string, Collection> */
    private array $lists;

    public function __construct()
    {
        $this->lists = [
            'nominating_entity_id' => NominatingEntity::query()->orderBy('id')->get(),
            'job_position_id' => JobPosition::query()->orderBy('id')->get(),
            'application_category_id' => ApplicationCategory::query()->orderBy('id')->get(),
            'academic_qualification_id' => AcademicQualification::query()->orderBy('id')->get(),
            'specialization_id' => Specialization::query()->orderBy('id')->get(),
            'marital_status_id' => MaritalStatus::query()->orderBy('id')->get(),
            'health_condition_id' => HealthCondition::query()->orderBy('id')->get(),
            'residence_district_id' => District::query()->orderBy('id')->get(),
        ];

        foreach ($this->lists as $field => $entries) {
            if ($entries->isEmpty()) {
                throw new RuntimeException("Demo data needs the managed lists: run ReferenceDataSeeder first ({$field} is empty).");
            }
        }
    }

    /** The entry of a list for this demo index (health: mostly the first entry, سليم). */
    public function entry(string $field, int $index): mixed
    {
        $entries = $this->lists[$field];

        if ($field === 'health_condition_id' && $index % 9 !== 4) {
            return $entries->first();
        }

        return DemoPeople::pick($entries->all(), $index, $field === 'job_position_id' ? 1 : 3);
    }

    /** An area of the district, or null for some people (the area is optional). */
    public function area(District $district, int $index): ?Area
    {
        if ($index % 5 === 3) {
            return null;
        }

        $areas = Area::query()->where('district_id', $district->id)->orderBy('id')->get();

        return $areas->isEmpty() ? null : DemoPeople::pick($areas->all(), $index, 2);
    }

    /** Request-shaped input for CreateApplication (the same shape StoreApplicationRequest validates). */
    public function applicationInput(int $index): array
    {
        $district = $this->entry('residence_district_id', $index);
        $qualification = $this->entry('academic_qualification_id', $index);
        $discontinued = $index % 8 === 6;
        $health = $this->entry('health_condition_id', $index);

        return [
            'nominating_entity_id' => $this->entry('nominating_entity_id', $index)->id,
            'job_position_id' => $this->entry('job_position_id', $index)->id,
            'application_category_id' => $this->entry('application_category_id', $index)->id,
            'existing_employee_number' => $index % 2 === 1 ? (string) (40000 + $index * 13) : null,
            'academic_qualification_id' => $qualification->id,
            'specialization_id' => $this->entry('specialization_id', $index)->id,
            'studying_years' => $index % 4 === 0 ? null : 1 + $index % 6,
            'studies_discontinued' => $discontinued,
            'quran_parts_memorized' => $index % 3 === 0 ? null : ($index * 7) % 31,
            'marital_status_id' => $this->entry('marital_status_id', $index)->id,
            'health_condition_id' => $health->id,
            'health_details' => $health->is($this->lists['health_condition_id']->first()) ? null : 'تفاصيل الحالة مرفقة بالملف الورقي',
            'residence_district_id' => $district->id,
            'residence_area_id' => $this->area($district, $index)?->id,
            'residence_details' => $index % 2 === 0 ? 'قرب الجامع الكبير' : null,
            'phone_number' => DemoPeople::phone($index),
            'works_for_other_government_entity' => $index % 6 === 5,
            'full_commitment' => $index % 7 !== 2,
            'overnight_work' => $index % 5 !== 1,
            'notes' => null,
            'candidate' => DemoPeople::person($index) + [
                'birth_place_id' => $this->entry('residence_district_id', $index + 2)->id,
            ],
        ];
    }
}
