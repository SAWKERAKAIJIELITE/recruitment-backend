<?php

namespace App\Modules\Imports\Enums;

/**
 * The columns of the legacy "بيانات المرشحين" sheet the import reads (analysis doc §23), and where each one
 * goes in an application. The header texts that identify each column live in config/imports.php.
 */
enum ImportField: string
{
    case NominatingEntity = 'nominating_entity';
    case JobPosition = 'job_position';
    case ApplicationCategory = 'application_category';
    case ExistingEmployeeNumber = 'existing_employee_number';
    case FullName = 'full_name';
    case MotherName = 'mother_name';
    case BirthPlace = 'birth_place';
    case BirthDate = 'birth_date';
    case NationalNumber = 'national_number';
    case MaritalStatus = 'marital_status';
    case Health = 'health';
    case ResidenceDistrict = 'residence_district';
    case ResidencePlace = 'residence_place';
    case AcademicQualification = 'academic_qualification';
    case Specialization = 'specialization';
    case StudyingYears = 'studying_years';
    case QuranParts = 'quran_parts';
    case OtherGovernmentEntity = 'other_government_entity';
    case OvernightWork = 'overnight_work';
    case FullCommitment = 'full_commitment';
    case PhoneNumber = 'phone_number';
    case Notes = 'notes';

    /** The application input key the value goes to (same keys as POST /applications, dotted). */
    public function dataKey(): string
    {
        return match ($this) {
            self::NominatingEntity => 'nominating_entity_id',
            self::JobPosition => 'job_position_id',
            self::ApplicationCategory => 'application_category_id',
            self::ExistingEmployeeNumber => 'existing_employee_number',
            self::FullName => 'candidate.full_name',
            self::MotherName => 'candidate.mother_name',
            self::BirthPlace => 'candidate.birth_place_id',
            self::BirthDate => 'candidate.birth_date',
            self::NationalNumber => 'candidate.national_number',
            self::MaritalStatus => 'marital_status_id',
            self::Health => 'health_condition_id',
            self::ResidenceDistrict => 'residence_district_id',
            self::ResidencePlace => 'residence_area_id',
            self::AcademicQualification => 'academic_qualification_id',
            self::Specialization => 'specialization_id',
            self::StudyingYears => 'studying_years',
            self::QuranParts => 'quran_parts_memorized',
            self::OtherGovernmentEntity => 'works_for_other_government_entity',
            self::OvernightWork => 'overnight_work',
            self::FullCommitment => 'full_commitment',
            self::PhoneNumber => 'phone_number',
            self::Notes => 'notes',
        };
    }

    /**
     * For list columns that may carry extra text after the list value ("مريض - سكري", "المزة، شارع الجلاء"):
     * the key that receives that text.
     */
    public function detailsKey(): ?string
    {
        return match ($this) {
            self::Health => 'health_details',
            self::ResidencePlace => 'residence_details',
            default => null,
        };
    }

    /** The column must be present in the sheet (its values may still be checked per row). */
    public function isRequiredColumn(): bool
    {
        return ! in_array($this, [self::ExistingEmployeeNumber, self::StudyingYears, self::QuranParts, self::Notes, self::NominatingEntity], true);
    }

    /** @return list<string> header texts that identify this column */
    public function headers(): array
    {
        return (array) config("imports.headers.{$this->value}", []);
    }

    public static function fromDataKey(string $key): ?self
    {
        foreach (self::cases() as $field) {
            if ($field->dataKey() === $key) {
                return $field;
            }
        }

        return null;
    }
}
