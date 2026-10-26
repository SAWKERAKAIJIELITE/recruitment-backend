<?php

namespace App\Modules\Recruitment\Support;

/**
 * The limits of an application's fields, in one place. Used by the API request (StoreApplicationRequest)
 * and by the Excel import, so the two can never accept different data. The database CHECKs repeat the
 * ranges and formats as the last guard.
 */
final class ApplicationRules
{
    public const NAME_MIN = 3;

    public const NAME_MAX = 150;

    /** No ASCII, Arabic-Indic or extended Arabic-Indic digits in a name. */
    public const NAME_FORBIDDEN = '/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u';

    public const PHONE_PATTERN = '/^\+?[0-9]{6,15}$/';

    public const BIRTH_DATE_AFTER = '1950-01-01';

    public const STUDYING_YEARS_MAX = 15;

    public const QURAN_PARTS_MAX = 30;

    public const EMPLOYEE_NUMBER_MAX = 50;

    public const RESIDENCE_DETAILS_MAX = 500;

    public const LONG_TEXT_MAX = 2000;   // notes, health details

    /** The list (managed reference data) each list field points to, keyed by input field. */
    public const LIST_FIELDS = [
        'nominating_entity_id' => 'nominating_entities',
        'job_position_id' => 'job_positions',
        'application_category_id' => 'application_categories',
        'academic_qualification_id' => 'academic_qualifications',
        'specialization_id' => 'specializations',
        'marital_status_id' => 'marital_statuses',
        'health_condition_id' => 'health_conditions',
        'residence_district_id' => 'districts',
        'residence_area_id' => 'areas',            // optional; when given, must belong to residence_district_id
        'candidate.birth_place_id' => 'districts',
    ];

    /** Fields every application must have. */
    public const REQUIRED = [
        'nominating_entity_id', 'job_position_id', 'application_category_id',
        'academic_qualification_id', 'specialization_id', 'marital_status_id', 'health_condition_id',
        'residence_district_id', 'phone_number',
        'works_for_other_government_entity', 'full_commitment', 'overnight_work',
        'candidate.national_number', 'candidate.full_name', 'candidate.mother_name',
        'candidate.birth_date', 'candidate.birth_place_id',
    ];
}
