<?php

namespace App\Modules\Interviews\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\SittingKind;
use App\Modules\Interviews\Models\InterviewSitting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sittings (D-059, D-061). A committee sitting's committee can change until a result is entered for one of its
 * interviews; after that it is the evidence of who interviewed and stays as it was. A one-off appointment's sitting
 * is not changed directly: rescheduling the interview gives it a new one, so the old place and committee are kept.
 */
class ManageSittings
{
    /** @param list<array{name: string, capacity: mixed, staff_user_id?: ?string}> $members */
    public function create(array $data, array $members, StaffUser $actor, SittingKind $kind = SittingKind::Committee): InterviewSitting
    {
        return DB::transaction(function () use ($data, $members, $actor, $kind) {
            $sitting = new InterviewSitting($data);
            $sitting->kind = $kind;
            $sitting->created_by = $actor->id;
            $sitting->save();

            Committee::write($sitting->members(), $members);

            AuditTrail::record('interview_sitting.created', $actor, ['sitting_id' => $sitting->id, 'kind' => $kind->value]);

            return $sitting;
        });
    }

    /**
     * Changes what is sent. Moving the sitting does not move the interviews already booked: each keeps its time
     * (reschedule them one by one, with a reason), and the sitting cannot start after any of them.
     */
    public function update(InterviewSitting $sitting, array $data, ?array $members, StaffUser $actor): InterviewSitting
    {
        return DB::transaction(function () use ($sitting, $data, $members, $actor) {
            $sitting = InterviewSitting::query()->whereKey($sitting->id)->lockForUpdate()->firstOrFail();

            if ($sitting->isOneOff()) {
                $this->fail('sitting', 'interviews.one_off_not_editable');
            }

            if ($members !== null) {
                if ($sitting->hasResults()) {
                    $this->fail('members', 'interviews.committee_frozen');
                }

                $sitting->members()->delete();
                Committee::write($sitting->members(), $members);
            }

            if (isset($data['starts_at'])) {
                $startsAt = Date::parse($data['starts_at']);
                $earliest = $sitting->interviews()->reorder()->min(DB::raw('least(scheduled_at, coalesce(held_at, scheduled_at))'));

                if ($earliest !== null && $startsAt->greaterThan(Date::parse($earliest))) {
                    $this->fail('starts_at', 'interviews.sitting_after_bookings', ['time' => Date::parse($earliest)->toIso8601String()]);
                }
            }

            $sitting->fill($data)->save();

            AuditTrail::record('interview_sitting.updated', $actor, ['sitting_id' => $sitting->id, 'fields' => array_keys($data), 'members' => $members !== null]);

            return $sitting;
        });
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
