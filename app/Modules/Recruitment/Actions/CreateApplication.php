<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Support\BlindIndex;
use App\Modules\Recruitment\Support\NationalId;
use App\Modules\Recruitment\Support\NominationNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens an application for a person: finds or creates the person, takes the next nomination number,
 * and writes the first history row, all in one transaction (the Imports step will reuse this per row).
 */
class CreateApplication
{
    public function __construct(
        private readonly ApplicationLifecycle $lifecycle,
        private readonly NominationNumberGenerator $numbers,
    ) {
    }

    /**
     * @param  array  $input  validated request data (see StoreApplicationRequest)
     * @return array{application: Application, candidate_matched: bool}
     */
    public function execute(array $input, StaffUser $actor): array
    {
        return DB::transaction(function () use ($input, $actor) {
            [$candidate, $matched] = $this->findOrCreateCandidate($input['candidate'], $input['sensitive'] ?? []);

            $this->assertNoLiveApplicationForTitle($candidate, $input['job_title_id']);

            $availability = $input['availability'] ?? [];
            $declared = array_filter($availability, fn($value) => $value !== null) !== [];

            $application = new Application([
                'candidate_id' => $candidate->id,
                'job_title_id' => $input['job_title_id'],
                'source_office_id' => $input['source_office_id'],
                'category_id' => $input['category_id'] ?? null,
                'existing_employee_number' => $input['existing_employee_number'] ?? null,
                'notes' => $input['notes'] ?? null,
                'works_for_other_government_entity' => $availability['works_for_other_government_entity'] ?? null,
                'can_stay_overnight' => $availability['can_stay_overnight'] ?? null,
                'full_time_available' => $availability['full_time_available'] ?? null,
            ]);

            $application->forceFill([
                'nomination_number' => $this->numbers->next(),
                'current_status' => ApplicationStatus::AwaitingExam,
                'availability_declared_at' => $declared ? now() : null,
                'created_by' => $actor->id,
            ])->save();

            $this->lifecycle->open($application, $actor);

            return ['application' => $application, 'candidate_matched' => $matched];
        });
    }

    /**
     * A person is recognised only by national ID (never by name: two people can share a name).
     * A matched person keeps the data already on file; a new application never overwrites it.
     *
     * @return array{0: Candidate, 1: bool}
     */
    private function findOrCreateCandidate(array $data, array $sensitive): array
    {
        $nationalId = isset($data['national_id']) ? NationalId::normalize($data['national_id']) : '';
        unset($data['national_id']);

        if ($nationalId !== '') {
            $existing = Candidate::where('national_id_hash', BlindIndex::for($nationalId))->first();

            if ($existing !== null) {
                return [$existing, true];
            }
        }

        $candidate = new Candidate($data);
        $candidate->setNationalId($nationalId);
        $candidate->save();

        if (array_filter($sensitive, fn($value) => $value !== null) !== []) {
            $candidate->sensitiveDetails()->create($sensitive);
        }

        return [$candidate, false];
    }

    private function assertNoLiveApplicationForTitle(Candidate $candidate, string $jobTitleId): void
    {
        $exists = Application::query()
            ->where('candidate_id', $candidate->id)
            ->where('job_title_id', $jobTitleId)
            ->whereNotIn('current_status', ApplicationStatus::terminalValues())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'job_title_id' => ['This person already has a live application for this job title.'],
            ]);
        }
    }
}
