<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Support\NationalNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a person's own data (D-049): names, birth date and place, notes, and the national number when it
 * was entered wrongly. The applications keep pointing at the same person. Every change is audited with
 * its before and after values.
 */
class UpdateCandidate
{
    public function execute(Candidate $candidate, array $changes, StaffUser $actor): Candidate
    {
        return DB::transaction(function () use ($candidate, $changes, $actor) {
            $candidate = Candidate::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();

            if (array_key_exists('national_number', $changes)) {
                $changes['national_number'] = NationalNumber::normalize((string) $changes['national_number']);

                // Both numbers are locked: nobody can create a person or an application with them meanwhile.
                CreateApplication::lockPeople([$candidate->national_number, $changes['national_number']]);

                $taken = Candidate::query()
                    ->where('national_number', $changes['national_number'])
                    ->whereKeyNot($candidate->getKey())
                    ->exists();

                if ($taken) {
                    throw ValidationException::withMessages(['national_number' => [__('recruitment.national_number_taken')]]);
                }
            }

            $before = $candidate->only(array_keys($changes));
            $candidate->fill($changes);
            $dirty = $candidate->getDirty();

            if ($dirty !== []) {
                $candidate->save();

                AuditTrail::record('candidate.updated', $actor, [
                    'candidate_id' => $candidate->id,
                    'changes' => collect($dirty)
                        ->except('name_search_key')
                        ->map(fn($to, $field) => ['from' => self::plain($before[$field] ?? null), 'to' => self::plain($candidate->getAttribute($field))])
                        ->all(),
                ]);
            }

            return $candidate;
        });
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
