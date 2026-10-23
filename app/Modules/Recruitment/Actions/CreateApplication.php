<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\NominationNumberGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens an application for a person: finds or creates the person, takes the next nomination number,
 * and writes the first history event, all in one transaction. The Excel import will reuse it per row.
 */
class CreateApplication
{
    /** The application fields of the input (everything except the person). */
    private const APPLICATION_FIELDS = [
        'nominating_entity_id',
        'job_position_id',
        'application_category_id',
        'existing_employee_number',
        'academic_qualification_id',
        'specialization_id',
        'studying_years',
        'quran_parts_memorized',
        'marital_status_id',
        'health_condition_id',
        'health_details',
        'residence_district_id',
        'residence_area_id',
        'residence_details',
        'phone_number',
        'works_for_other_government_entity',
        'full_commitment',
        'overnight_work',
        'notes',
    ];

    public function __construct(
        private readonly ApplicationLifecycle $lifecycle,
        private readonly NominationNumberGenerator $numbers,
    ) {
    }

    /**
     * @param  array  $input  validated data (see StoreApplicationRequest)
     * @return array{application: Application, candidate_matched: bool}
     */
    public function execute(array $input, StaffUser $actor): array
    {
        return DB::transaction(function () use ($input, $actor) {
            [$candidate, $matched] = $this->findOrCreateCandidate($input['candidate']);

            $this->assertNoLiveApplicationForPosition($candidate, (int) $input['job_position_id']);

            $application = new Application(Arr::only($input, self::APPLICATION_FIELDS));
            $application->candidate_id = $candidate->id;
            $application->forceFill([
                'nomination_number' => $this->numbers->next(),
                'created_by' => $actor->id,
            ])->save();

            $this->lifecycle->open($application, $actor);

            $application->setRelation('candidate', $candidate);

            return ['application' => $application, 'candidate_matched' => $matched];
        });
    }

    /**
     * A person is recognized only by national number, never by name: two people can share a name.
     * A matched person keeps the data already on file; a new application never overwrites it.
     *
     * @return array{0: Candidate, 1: bool}
     */
    private function findOrCreateCandidate(array $data): array
    {
        $nationalNumber = NationalNumber::normalize($data['national_number']);

        // Locked, so two requests for the same new person cannot both decide "not on file yet"
        // (the unique index would refuse the second one anyway).
        $existing = Candidate::query()->where('national_number', $nationalNumber)->lockForUpdate()->first();

        if ($existing !== null) {
            return [$existing, true];
        }

        return [Candidate::create(['national_number' => $nationalNumber] + $data), false];
    }

    private function assertNoLiveApplicationForPosition(Candidate $candidate, int $jobPositionId): void
    {
        $exists = Application::query()
            ->where('candidate_id', $candidate->id)
            ->where('job_position_id', $jobPositionId)
            ->whereNotIn('current_status', ApplicationStatus::terminalValues())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'job_position_id' => [__('recruitment.live_application_exists')],
            ]);
        }
    }
}
