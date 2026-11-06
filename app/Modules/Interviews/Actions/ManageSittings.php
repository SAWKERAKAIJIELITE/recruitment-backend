<?php

namespace App\Modules\Interviews\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\SittingKind;
use App\Modules\Interviews\Models\InterviewSitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sittings (D-059, D-061, D-067): a committee sitting, or a one-off sitting with exactly one interviewer. Both hold
 * many candidates. The committee can change until a result is entered for one of the sitting's interviews; after
 * that it is the evidence of who interviewed and stays as it was. A sitting with the same start, place and members as
 * another is the same sitting entered twice, and is refused (422 here; the database refuses it at commit too, when
 * two staff users enter it at the same moment).
 */
class ManageSittings
{
    /**
     * Don't use it in without $request->safe(), because it use Mass assignment
     *  @param list<array{name: string, capacity: mixed, staff_user_id?: ?string}> $members
     */
    public function create(array $data, array $members, StaffUser $actor, SittingKind $kind = SittingKind::Committee): InterviewSitting
    {
        return $this->transaction(function () use ($data, $members, $actor, $kind) {
            $this->assertMemberCount($kind, $members);

            $sitting = new InterviewSitting($data);
            $sitting->kind = $kind;
            $sitting->created_by = $actor->id;
            $sitting->save();

            Committee::write($sitting, $members);
            $this->assertNotDuplicate($sitting);

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
        return $this->transaction(function () use ($sitting, $data, $members, $actor) {
            $sitting = InterviewSitting::query()->whereKey($sitting->id)->lockForUpdate()->firstOrFail();

            if ($members !== null) {
                if ($sitting->hasResults()) {
                    $this->fail('members', 'interviews.committee_frozen');
                }

                $this->assertMemberCount($sitting->kind, $members);
                $sitting->members()->delete();
                Committee::write($sitting, $members);
                // The old fingerprint no longer describes the committee; the new one is computed at commit.
                $sitting->forceFill(['committee_key' => null]);
            }

            if (isset($data['starts_at'])) {
                $startsAt = Date::parse($data['starts_at']);
                $earliest = $sitting->interviews()->reorder()->min(DB::raw('least(scheduled_at, coalesce(held_at, scheduled_at))'));

                if ($earliest !== null && $startsAt->greaterThan(Date::parse($earliest))) {
                    $this->fail('starts_at', 'interviews.sitting_after_bookings', ['time' => Date::parse($earliest)->toIso8601String()]);
                }
            }

            $sitting->fill($data);

            if ($sitting->kind === SittingKind::Committee && blank($sitting->committee_name)) {
                $this->fail('committee_name', 'interviews.committee_name_required');
            }

            // Checked before saving: the unique index is checked at once, and would answer without the other's id.
            $this->assertNotDuplicate($sitting);
            $sitting->save();

            AuditTrail::record('interview_sitting.updated', $actor, ['sitting_id' => $sitting->id, 'fields' => array_keys($data), 'members' => $members !== null]);

            return $sitting;
        });
    }

    /**
     * Deletes a sitting nothing was booked into: no interview in it, and no reschedule that moved one out of it
     * (both are history that points to it). Its members go with it.
     */
    public function delete(InterviewSitting $sitting, StaffUser $actor): void
    {
        DB::transaction(function () use ($sitting, $actor) {
            $sitting = InterviewSitting::query()->whereKey($sitting->id)->lockForUpdate()->firstOrFail();

            $used = $sitting->interviews()->exists()
                || DB::table('interview_reschedules')->where('from_sitting_id', $sitting->id)->orWhere('to_sitting_id', $sitting->id)->exists();

            if ($used) {
                $this->fail('sitting', 'interviews.sitting_in_use');
            }

            $sitting->members()->delete();
            $sitting->delete();

            AuditTrail::record('interview_sitting.deleted', $actor, ['sitting_id' => $sitting->id, 'kind' => $sitting->kind->value, 'starts_at' => $sitting->starts_at->toIso8601String(), 'location' => $sitting->location]);
        });
    }

    /** A one-off sitting is one interviewer. */
    private function assertMemberCount(SittingKind $kind, array $members): void
    {
        if ($kind === SittingKind::OneOff && count($members) !== 1) {
            $this->fail('members', 'interviews.one_off_one_member');
        }
    }

    /**
     * Another sitting with the same start, place and members (compared as the database compares them, with
     * `interview_text_key`) already exists. Its committee key is computed here from the members just written: the
     * stored key is filled at commit.
     */
    private function assertNotDuplicate(InterviewSitting $sitting): void
    {
        $duplicate = DB::table('interview_sittings')
            ->where('id', '<>', $sitting->id)
            ->where('starts_at', $sitting->starts_at)
            ->whereRaw('interview_text_key(location) = interview_text_key(?)', [$sitting->location])
            ->whereRaw('interview_committee_key(id) = interview_committee_key(?)', [$sitting->id])
            ->value('id');

        if ($duplicate !== null) {
            $this->fail('starts_at', 'interviews.duplicate_sitting', ['id' => $duplicate]);
        }
    }

    /** The database's own refusal, when the duplicate was committed by someone else in the meantime. */
    private function transaction(\Closure $callback): InterviewSitting
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'interview_sittings_no_duplicate')) {
                $this->fail('starts_at', 'interviews.duplicate_sitting_now');
            }

            throw $e;
        }
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
