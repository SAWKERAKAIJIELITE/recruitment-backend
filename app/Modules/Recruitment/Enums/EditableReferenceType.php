<?php

namespace App\Modules\Recruitment\Enums;

/**
 * The lists staff change through the API: every ReferenceType except marital statuses, which the owner seeds
 * (D-048). The change endpoints bind {type} to this enum, so marital-statuses there is an unknown list (404)
 * and does not appear in the API documentation; reading still uses ReferenceType.
 */
enum EditableReferenceType: string
{
    case Districts = 'districts';
    case Areas = 'areas';
    case NominatingEntities = 'nominating-entities';
    case JobPositions = 'job-positions';
    case ApplicationCategories = 'application-categories';
    case AcademicQualifications = 'academic-qualifications';
    case Specializations = 'specializations';
    case HealthConditions = 'health-conditions';

    public function type(): ReferenceType
    {
        return ReferenceType::from($this->value);
    }
}
