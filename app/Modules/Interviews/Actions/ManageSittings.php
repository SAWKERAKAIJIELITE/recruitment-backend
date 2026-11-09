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
 * that it is the evidence of who interviewed and stays as it was. A sitting runs from `starts_at` to `ends_at` (at
 * most 24 hours). The same members cannot sit at overlapping times (a sitting entered twice, even a few seconds
 * apart, overlaps itself), and a staff account cannot be a member of two overlapping sittings: refused (422 here; the
 * database refuses both at commit too, when two staff users enter them at the same moment).
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
            $this->assertTimes($sitting);
            $sitting->save();

            Committee::write($sitting, $members);
            $this->assertNoOverlap($sitting);

            AuditTrail::record('interview_sitting.created', $actor, ['sitting_id' => $sitting->id, 'kind' => $kind->value]);

            return $sitting;
        });
    }

    /**
     * Changes what is sent. Moving the sitting does not move the interviews already booked: each keeps its time
     * (reschedule them one by one, with a reason), and the sitting cannot start after any of them nor end before one.
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

            if (isset($data['ends_at'])) {
                $latest = $sitting->interviews()->reorder()->max('scheduled_at');

                if ($latest !== null && Date::parse($data['ends_at'])->lessThanOrEqualTo(Date::parse($latest))) {
                    $this->fail('ends_at', 'interviews.sitting_before_bookings', ['time' => Date::parse($latest)->toIso8601String()]);
                }
            }

            $sitting->fill($data);
            $this->assertTimes($sitting);

            if ($sitting->kind === SittingKind::Committee && blank($sitting->committee_name)) {
                $this->fail('committee_name', 'interviews.committee_name_required');
            }

            // Checked before saving: the exclusion constraint is checked at once, and would answer without the other's id.
            $this->assertNoOverlap($sitting);
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

    /** Ends after it starts, within 24 hours (the database's CHECK, answered here on the right field). */
    private function assertTimes(InterviewSitting $sitting): void
    {
        if ($sitting->ends_at->lessThanOrEqualTo($sitting->starts_at) || $sitting->ends_at->greaterThan($sitting->starts_at->copy()->addDay())) {
            $this->fail('ends_at', 'interviews.sitting_too_long');
        }
    }

    /**
     * Another sitting overlapping this one has the same members (compared as the database compares them: its
     * committee key is computed here from the members just written; the stored key is filled at commit), or one of
     * this sitting's staff accounts.
     */
    private function assertNoOverlap(InterviewSitting $sitting): void
    {
        $overlapping = fn() => DB::table('interview_sittings as other')
            ->where('other.id', '<>', $sitting->id)
            ->whereRaw('tstzrange(other.starts_at, other.ends_at) && tstzrange(?, ?)', [$sitting->starts_at, $sitting->ends_at]);

        $duplicate = $overlapping()->whereRaw('interview_committee_key(other.id) = interview_committee_key(?)', [$sitting->id])->value('other.id');

        if ($duplicate !== null) {
            $this->fail('starts_at', 'interviews.duplicate_sitting', ['id' => $duplicate]);
        }

        $busy = $overlapping()
            ->join('interview_members as theirs', 'theirs.sitting_id', '=', 'other.id')
            ->join('interview_members as ours', 'ours.staff_user_id', '=', 'theirs.staff_user_id')
            ->where('ours.sitting_id', $sitting->id)
            ->orderBy('ours.position')
            ->first(['ours.name', 'other.id']);

        if ($busy !== null) {
            $this->fail('members', 'interviews.staff_overlap', ['name' => $busy->name, 'id' => $busy->id]);
        }
    }

    /** The database's own refusal, when the duplicate was committed by someone else in the meantime. */
    private function transaction(\Closure $callback): InterviewSitting
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'interview_sittings_no_overlap')) {
                $this->fail('starts_at', 'interviews.duplicate_sitting_now');
            }

            if (str_contains($e->getMessage(), 'interview_staff_overlap')) {
                $this->fail('members', 'interviews.staff_overlap_now');
            }

            throw $e;
        }
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
