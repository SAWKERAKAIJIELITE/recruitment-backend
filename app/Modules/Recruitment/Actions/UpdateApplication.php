<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects what was declared in a live application (D-049): entity, position, category, education,
 * marital and health status, residence, phone, availability, notes. Never the person (UpdateCandidate),
 * the nomination number or the status (ApplicationLifecycle). An ended application is history and is not
 * edited. Every change is audited with its before and after values.
 */
class UpdateApplication
{
    public function execute(Application $application, array $changes, StaffUser $actor): Application
    {
        return DB::transaction(function () use ($application, $changes, $actor) {
            $locked = Application::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->current_status->isLive()) {
                throw ValidationException::withMessages([
                    'status' => [__('recruitment.application_ended', ['status' => $locked->current_status->labelAr()])],
                ]);
            }

            $before = $locked->only(array_keys($changes));
            $locked->fill($changes);
            $dirty = $locked->getDirty();

            if ($dirty !== []) {
                $locked->save();

                AuditTrail::record('application.updated', $actor, [
                    'application_id' => $locked->id,
                    'changes' => collect($dirty)->map(fn($to, $field) => ['from' => $before[$field] ?? null, 'to' => $locked->getAttribute($field)])->all(),
                ]);
            }

            $application->setRawAttributes($locked->getAttributes(), true);

            return $application;
        });
    }
}
