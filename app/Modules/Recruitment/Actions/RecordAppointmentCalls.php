<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\AppointmentCall;
use App\Modules\Recruitment\Queries\Appointments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recruitment records its calls to the candidates about their appointments (D-105): for each application, about the
 * appointment ahead as it stands (its time and place), reached or not, with a note. All or nothing: an application
 * without an appointment ahead refuses the whole request (422 on `application_ids.N`). Information only: nothing moves.
 */
class RecordAppointmentCalls
{
    /**
     * @param  list<string>  $ids
     * @return array<string, array> the appointments after the calls, by application id
     */
    public function record(array $ids, bool $reached, ?string $note, StaffUser $actor): array
    {
        return DB::transaction(function () use ($ids, $reached, $note, $actor) {
            $now = CarbonImmutable::instance(Date::now());
            $applications = Application::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $appointments = Appointments::of($applications, $now);
            $errors = [];

            foreach (array_values($ids) as $index => $id) {
                if (! isset($appointments[$id])) {
                    $errors["application_ids.{$index}"] = [__('recruitment.calls.no_appointment')];
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $note = $note === null || trim($note) === '' ? null : trim($note);

            AppointmentCall::query()->insert(array_map(fn(string $id) => [
                'application_id' => $id,
                'exam_booking_id' => $appointments[$id]['exam_booking_id'],
                'interview_id' => $appointments[$id]['interview_id'],
                'appointment_at' => $appointments[$id]['at'],
                'location' => $appointments[$id]['location'],
                'reached' => $reached,
                'note' => $note,
                'called_by' => $actor->id,
                'created_at' => $now,
            ], array_values($ids)));

            AuditTrail::record('application.calls_recorded', $actor, ['application_ids' => array_values($ids), 'reached' => $reached, 'note' => $note]);

            return Appointments::of($applications, $now);
        });
    }
}
