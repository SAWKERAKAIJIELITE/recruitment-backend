<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Models\PassPolicy;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/**
 * Adds a version of the pass rules (D-052, D-063). Versions are never changed: the new one is used by every exam plan
 * published from now on, and plans already published keep the version they were published with, so results already
 * scored never change.
 */
class AddPassPolicy
{
    /** @param array{subject_pass_mark: numeric-string|float|int, tolerance_floor: numeric-string|float|int, max_tolerated_subjects: int, average_must_exceed: numeric-string|float|int, description: string} $data */
    public function execute(array $data, StaffUser $actor): PassPolicy
    {
        return DB::transaction(function () use ($data, $actor) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('exam_pass_policies.version'))");

            $policy = new PassPolicy;
            $policy->forceFill([
                'version' => (int) PassPolicy::query()->max('version') + 1,
                'subject_pass_mark' => $data['subject_pass_mark'],
                'tolerance_floor' => $data['tolerance_floor'],
                'max_tolerated_subjects' => (int) $data['max_tolerated_subjects'],
                'average_must_exceed' => $data['average_must_exceed'],
                'description' => trim($data['description']),
            ])->save();

            AuditTrail::record('exam_pass_policy.added', $actor, ['version' => $policy->version]);

            return $policy->refresh();
        });
    }
}
