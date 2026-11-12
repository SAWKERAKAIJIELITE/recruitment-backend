<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contracts\Enums\ContractState;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
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
     * their first contract; `ending_soon` and `expired`, latest contracts in that state today.
     *
     * @response array{data: array{notifications: array{unread: int}, interviews: array{to_book: int, to_record: int, total: int}|null, contracts: array{awaiting_contract: int, ending_soon: int, expired: int}|null}}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $interviews = null;
        $contracts = null;

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
        ]]);
    }
}
