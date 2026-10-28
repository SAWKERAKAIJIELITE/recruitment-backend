<?php

namespace App\Modules\Recruitment\Enums;

use App\Modules\Recruitment\Models\AcademicQualification;
use App\Modules\Recruitment\Models\ApplicationCategory;
use App\Modules\Recruitment\Models\Area;
use App\Modules\Recruitment\Models\District;
use App\Modules\Recruitment\Models\HealthCondition;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Recruitment\Models\MaritalStatus;
use App\Modules\Recruitment\Models\NominatingEntity;
use App\Modules\Recruitment\Models\Specialization;

/** The managed lists (the hidden "_Lists" sheet of the Excel template). The value is the URL segment. */
enum ReferenceType: string
{
    case Districts = 'districts';
    case Areas = 'areas';
    case NominatingEntities = 'nominating-entities';
    case JobPositions = 'job-positions';
    case ApplicationCategories = 'application-categories';
    case AcademicQualifications = 'academic-qualifications';
    case Specializations = 'specializations';
    case MaritalStatuses = 'marital-statuses';
    case HealthConditions = 'health-conditions';

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public function model(): string
    {
        return match ($this) {
            self::Districts => District::class,
            self::Areas => Area::class,
            self::NominatingEntities => NominatingEntity::class,
            self::JobPositions => JobPosition::class,
            self::ApplicationCategories => ApplicationCategory::class,
            self::AcademicQualifications => AcademicQualification::class,
            self::Specializations => Specialization::class,
            self::MaritalStatuses => MaritalStatus::class,
            self::HealthConditions => HealthCondition::class,
        };
    }

    /** Areas belong to a district: their names are unique per district and they are listed per district. */
    public function isScopedToDistrict(): bool
    {
        return $this === self::Areas;
    }

    /**
     * Lists the owner seeds and that the API only reads (decision D-048): marital statuses. The others are
     * managed by staff holding candidates.manage.
     */
    public function isEditableThroughApi(): bool
    {
        return $this !== self::MaritalStatuses;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
