<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contracts\Enums\ContractState;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Exams\Models\ExamHallDownload;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\Appointments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/** The counts beside the sidebar's entries (D-099): what is waiting in the sections the signed-in user may see. */
class BadgeController extends Controller
{
    /**
     * Sidebar counts
     *
     * Any signed-in staff user; a section the user does not see is null. `notifications.unread`: the bell (as
     * /notifications/unread-count). `interviews` (interviews.view): `to_book`, applications awaiting their interview with
     * none booked (the interview queue); `to_record`, booked interviews whose time has come, waiting for their result;
     * `total`. `contracts` (contracts.view): `awaiting_contract`, applications that passed their interview and wait for
     * their first contract; `ending_soon` and `expired`, latest contracts in that state today. `exams` (exams.view):
     * `results_to_approve`, sessions with decided results waiting for the approval (D-104); `hall_out`, sessions taken
     * out to a hall with no internet now; `hall_overdue`, those still out `exams.hall_overdue_hours` (48) after they
     * should have ended (D-102). `calls` (candidates.manage, Recruitment): `to_inform`, applications whose appointment
     * ahead (an exam booked, an interview scheduled) nobody told the candidate of yet, or whose time or place changed
     * since; `not_reached`, those whose latest call was not answered (D-105).
     *
     * @response array{data: array{notifications: array{unread: int}, interviews: array{to_book: int, to_record: int, total: int}|null, contracts: array{awaiting_contract: int, ending_soon: int, expired: int}|null, exams: array{results_to_approve: int, hall_out: int, hall_overdue: int}|null, calls: array{to_inform: int, not_reached: int}|null}}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $interviews = null;
        $contracts = null;
        $exams = null;
        $calls = null;

        if ($user->hasPermission('candidates.manage')) {
            // Who Recruitment has to call about their appointment (D-105), in one pass.
            $now = Date::now();
            $states = Application::query()
                ->whereIn('current_status', [ApplicationStatus::AwaitingExam, ApplicationStatus::AwaitingInterview])
                ->toBase()
                ->selectRaw(Appointments::stateSql() . ' AS state', [$now, $now])
                ->pluck('state')
                ->countBy();
            $calls = ['to_inform' => (int) $states->get('to_inform', 0), 'not_reached' => (int) $states->get('not_reached', 0)];
        }

        if ($user->hasPermission('exams.view')) {
            // Sessions taken out to a hall (D-102), and those still out long after they ended.
            $out = ExamHallDownload::query()->where('status', 'out');
            $exams = [
                'results_to_approve' => ExamSession::query()->has('bookingsAwaitingApproval')->count(),
                'hall_out' => (clone $out)->count(),
                'hall_overdue' => (clone $out)->whereHas('session', fn($session) => $session->whereRaw(
                    "starts_at + (duration_minutes + extra_minutes) * interval '1 minute' + ? * interval '1 hour' < ?",
                    [(int) config('exams.hall_overdue_hours'), Date::now()],
                ))->count(),
            ];
        }

        if ($user->hasPermission('interviews.view')) {
            $toBook = Application::query()
                ->where('current_status', ApplicationStatus::AwaitingInterview)
                ->whereNotExists(fn($interview) => $interview->from('interviews')
                    ->whereColumn('interviews.application_id', 'applications.id')
                    ->where('interviews.status', InterviewStatus::Scheduled->value))
                ->count();
            $toRecord = Interview::query()->where('status', InterviewStatus::Scheduled)->where('scheduled_at', '<=', Date::now())->count();
            $interviews = ['to_book' => $toBook, 'to_record' => $toRecord, 'total' => $toBook + $toRecord];
        }

        if ($user->hasPermission('contracts.view')) {
            $contracts = [
                'awaiting_contract' => Application::query()->where('current_status', ApplicationStatus::AwaitingContract)->count(),
                'ending_soon' => Contract::query()->inState(ContractState::EndingSoon)->count(),
                'expired' => Contract::query()->inState(ContractState::Expired)->count(),
            ];
        }

        return response()->json(['data' => [
            'notifications' => ['unread' => $user->unreadNotifications()->count()],
            'interviews' => $interviews,
            'contracts' => $contracts,
            'exams' => $exams,
            'calls' => $calls,
        ]]);
    }
}
