<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Support\NationalNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Candidate login for an exam (owner, Step 10; D-072): the national number and the password of the session the
 * candidate is booked into, only around that session (from config exams.login_early_minutes before its start until
 * it ends). The token belongs to that booking. One login at a time: a new login ends the previous one (both are
 * logged, with the address, for the supervisors). Every failure gives the same message; the reason goes to the log.
 */
class CandidateExamLogin
{
    /** @return array{token: string, booking: ExamBooking} */
    public function login(string $nationalNumber, string $password, ?string $ip): array
    {
        $now = Date::now();
        $candidate = Candidate::query()->where('national_number', NationalNumber::normalize($nationalNumber))->first();

        if ($candidate === null || ! $candidate->is_active) {
            Hash::make($password);   // as slow as a booked person's check: the time tells nothing
            $this->fail($candidate === null ? 'unknown_national_number' : 'inactive', $nationalNumber, $ip);
        }

        $booking = ExamBooking::query()
            ->with('session')
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::Attended])
            ->whereIn('application_id', $candidate->applications()->select('id'))
            ->whereHas('session', fn($session) => $session->where('starts_at', '<=', $now->copy()->addMinutes((int) config('exams.login_early_minutes'))))
            ->get()
            ->first(fn(ExamBooking $booking) => $this->withinWindow($booking, $now) && Hash::check($password, $booking->session->password));

        if ($booking === null) {
            $this->fail('no_session_or_wrong_password', $nationalNumber, $ip);
        }

        if ($booking->status === BookingStatus::Booked && $now->greaterThanOrEqualTo($booking->session->lateUntil())) {
            throw ValidationException::withMessages(['national_number' => [__('exams.candidate.too_late', ['time' => $booking->session->lateUntil()->toIso8601String()])]]);
        }

        $token = DB::transaction(function () use ($candidate, $booking, $now, $ip) {
            // Locked: two logins at the same moment cannot both keep a token.
            Candidate::query()->whereKey($candidate->id)->lockForUpdate()->first();
            $replaced = $candidate->tokens()->delete();
            $minutes = (int) config('exams.candidate_token_minutes');
            $token = $candidate->createToken('exam', ['exam'], $now->copy()->addMinutes($minutes));
            $token->accessToken->forceFill(['exam_booking_id' => $booking->id])->save();

            AuditTrail::record('exam.candidate_login', null, ['candidate_id' => $candidate->id, 'booking_id' => $booking->id, 'ip' => $ip, 'previous_logins_ended' => $replaced], $candidate->id);

            return $token->plainTextToken;
        });

        return ['token' => $token, 'booking' => $booking];
    }

    /** From a while before the start until the session (or this candidate's own extended attempt) ends. */
    private function withinWindow(ExamBooking $booking, CarbonInterface $now): bool
    {
        $end = $booking->session->endsAt();
        $deadline = $booking->attempt()->value('deadline_at');

        if ($deadline !== null && Date::parse($deadline)->greaterThan($end)) {
            $end = Date::parse($deadline);
        }

        return $now->lessThan($end);
    }

    private function fail(string $reason, string $nationalNumber, ?string $ip): never
    {
        Log::notice('candidate_login_failed', ['reason' => $reason, 'national_number_suffix' => substr($nationalNumber, -4), 'ip' => $ip]);

        throw ValidationException::withMessages(['national_number' => [__('exams.candidate.login_failed')]]);
    }
}
