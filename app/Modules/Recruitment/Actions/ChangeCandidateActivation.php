<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deactivates or reactivates a person (D-049). A deactivated person cannot be nominated, by the API or by an
 * import. A person with a live application cannot be deactivated: close the application first, so its
 * end is a recorded decision too.
 */
class ChangeCandidateActivation
{
    public function deactivate(Candidate $candidate, StaffUser $actor, string $reason): Candidate
    {
        return DB::transaction(function () use ($candidate, $actor, $reason) {
            $candidate = $this->lock($candidate);

            if (! $candidate->is_active) {
                throw ValidationException::withMessages(['candidate' => [__('recruitment.candidate_already_inactive')]]);
            }

            $live = Application::query()->where('candidate_id', $candidate->id)->live()->value('nomination_number');

            if ($live !== null) {
                throw ValidationException::withMessages(['candidate' => [__('recruitment.candidate_has_live_application', ['number' => $live])]]);
            }

            $candidate->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $actor->id,
                'deactivation_reason' => trim($reason),
            ])->save();

            AuditTrail::record('candidate.deactivated', $actor, ['candidate_id' => $candidate->id, 'reason' => trim($reason)]);

            return $candidate;
        });
    }

    public function activate(Candidate $candidate, StaffUser $actor): Candidate
    {
        return DB::transaction(function () use ($candidate, $actor) {
            $candidate = $this->lock($candidate);

            if ($candidate->is_active) {
                throw ValidationException::withMessages(['candidate' => [__('recruitment.candidate_already_active')]]);
            }

            $candidate->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null, 'deactivation_reason' => null])->save();

            AuditTrail::record('candidate.activated', $actor, ['candidate_id' => $candidate->id]);

            return $candidate;
        });
    }

    private function lock(Candidate $candidate): Candidate
    {
        // The person lock as well: no application can be opened for them while this runs.
        CreateApplication::lockPeople([$candidate->national_number]);

        return Candidate::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
    }
}
