<?php

namespace App\Modules\Recruitment\Support;

use App\Modules\Documents\Models\DocumentType;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Notifications\ApplicationsMoved;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the offices when applications move (D-089). Every status event is recorded here; once its transaction
 * commits (an event of a rolled-back transaction is never told), the moves are grouped (same move, same staff
 * user) and each group becomes ONE notification per recipient, however many applications it holds: an import, a
 * bulk start or the results of a session do not flood anyone.
 *
 * Recipients: the Recruitment office always (it is the only office that talks to the candidates, so it follows every
 * move); the office whose section the application has reached; the Documenting office when documents become
 * required at that status. Never the staff user who made the move, nor the general manager or the administrator.
 */
final class LifecycleNotices
{
    /** The office whose section each status belongs to. */
    private const SECTIONS = [
        'received' => StaffRole::Recruitment,
        'awaiting_exam' => StaffRole::ExamsInterviews,
        'exam_failed' => StaffRole::ExamsInterviews,
        'exam_absent' => StaffRole::ExamsInterviews,
        'awaiting_interview' => StaffRole::ExamsInterviews,
        'interview_failed' => StaffRole::ExamsInterviews,
        'interview_absent' => StaffRole::ExamsInterviews,
        'awaiting_contract' => StaffRole::Contracts,
        'contract_written' => StaffRole::Contracts,
        'contract_ended' => StaffRole::Recruitment,
        'closed' => StaffRole::Recruitment,
    ];

    /** @var list<array{application_id: string, from: ?ApplicationStatus, to: ApplicationStatus, type: DecisionType, actor_id: ?string}> committed, not told yet */
    private array $committed = [];

    /** Inside batch(): the commits wait, and are told together at its end. */
    private int $holding = 0;

    public static function section(ApplicationStatus $status): StaffRole
    {
        return self::SECTIONS[$status->value];
    }

    /** Called for every event written, inside its transaction. */
    public function recorded(string $applicationId, ?ApplicationStatus $from, ApplicationStatus $to, DecisionType $type, ?StaffUser $actor): void
    {
        $move = ['application_id' => $applicationId, 'from' => $from, 'to' => $to, 'type' => $type, 'actor_id' => $actor?->id];

        // Kept only if the transaction (and every savepoint around the event) commits.
        DB::afterCommit(function () use ($move) {
            $this->committed[] = $move;
        });
    }

    /**
     * Runs $work, which commits many transactions of its own (one per booking in the exam sweep), and tells their moves
     * together at the end, grouped as if they were one commit.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public function batch(\Closure $work): mixed
    {
        $this->holding++;

        try {
            return $work();
        } finally {
            $this->holding--;
            $this->flush();
        }
    }

    /** Runs after every commit (TransactionCommitted): tells what the commit's callbacks just handed over. */
    public function flush(): void
    {
        if ($this->committed === [] || $this->holding > 0) {
            return;
        }

        [$moves, $this->committed] = [$this->committed, []];

        try {
            $this->tell($moves);
        } catch (\Throwable $e) {
            // The moves are committed: a failed notification must not turn them into an error for the caller.
            Log::error('lifecycle.notification_failed', ['error' => $e->getMessage(), 'moves' => count($moves)]);
            report($e);
        }
    }

    /** @param list<array{application_id: string, from: ?ApplicationStatus, to: ApplicationStatus, type: DecisionType, actor_id: ?string}> $moves */
    private function tell(array $moves): void
    {
        $groups = collect($moves)->groupBy(fn(array $move) => implode('|', [$move['from']?->value, $move['to']->value, $move['type']->value, $move['actor_id']]));
        $staff = $this->staffByRole();
        $documented = $this->statusesRequiringDocuments();
        $applications = Application::query()->with('candidate:id,full_name')
            ->whereKey(collect($moves)->pluck('application_id')->unique()->values()->all())
            ->get(['id', 'candidate_id', 'nomination_number'])
            ->keyBy('id');

        foreach ($groups as $group) {
            $first = $group->first();
            $roles = collect([StaffRole::Recruitment, self::section($first['to'])]);

            if (in_array($first['to']->value, $documented, true)) {
                $roles->push(StaffRole::Documenting);
            }

            $recipients = $roles->unique(fn(StaffRole $role) => $role->value)
                ->flatMap(fn(StaffRole $role) => $staff->get($role->value, collect()))
                ->unique('id')
                ->reject(fn(StaffUser $user) => $user->id === $first['actor_id'])
                ->values();

            if ($recipients->isEmpty()) {
                continue;
            }

            $moved = $group->pluck('application_id')->unique()->map(fn(string $id) => $applications->get($id))->filter()->values();
            $actor = $first['actor_id'] === null ? null : StaffUser::query()->find($first['actor_id']);

            Notification::send($recipients, new ApplicationsMoved($moved, $first['from'], $first['to'], $first['type'], $actor));
        }
    }

    /** @return Collection<string, Collection<int, StaffUser>> active staff users of the offices, by role slug */
    private function staffByRole(): Collection
    {
        return StaffUser::query()
            ->where('is_active', true)
            ->join('roles', 'roles.id', '=', 'staff_users.role_id')
            ->whereIn('roles.slug', [StaffRole::Recruitment->value, StaffRole::ExamsInterviews->value, StaffRole::Contracts->value, StaffRole::Documenting->value])
            ->get(['staff_users.*', 'roles.slug as role_slug'])
            ->groupBy('role_slug');
    }

    /** @return list<string> the statuses from which some active document type is required */
    private function statusesRequiringDocuments(): array
    {
        return DocumentType::query()->whereNotNull('required_from_status')->toBase()->distinct()->pluck('required_from_status')->all();
    }
}
