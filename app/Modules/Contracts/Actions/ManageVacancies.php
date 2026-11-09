<?php

namespace App\Modules\Contracts\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Contracts\Models\VacancyPlan;
use App\Modules\Contracts\Support\VacancyFile;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\Application;
use App\Support\BusinessDay;
use App\Support\SafeFilename;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The vacancies (D-088):
 *  - upload a file for a period of three months from `starts_on`, which must not overlap a plan in force;
 *  - replace a plan (a corrected file) for the same period, while it is current or upcoming: contracts already
 *    written count against the new numbers;
 *  - check, while a first contract is written, that its job position has a vacancy left in the plan covering the
 *    writing day (renewals use none). A position missing from the file has none.
 */
class ManageVacancies
{
    public function upload(UploadedFile $file, string $startsOn, ?string $notes, StaffUser $actor): VacancyPlan
    {
        $start = Date::parse($startsOn)->startOfDay();
        $end = $start->copy()->addMonthsNoOverflow(VacancyPlan::months())->subDay();
        $overlap = VacancyPlan::query()->inForce()->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())->first();

        if ($overlap !== null) {
            $this->overlapping($overlap);
        }

        return $this->store($file, $start, $end, $notes, $actor, null);
    }

    public function replace(VacancyPlan $plan, UploadedFile $file, ?string $notes, StaffUser $actor): VacancyPlan
    {
        if ($plan->replaced_at !== null || $plan->ends_on->toDateString() < BusinessDay::today()) {
            throw ValidationException::withMessages(['plan' => [__('contracts.vacancy_plan_not_replaceable')]]);
        }

        return $this->store($file, $plan->starts_on, $plan->ends_on, $notes, $actor, $plan);
    }

    /**
     * Inside the transaction writing a first contract: refuses it (422) when no plan covers the writing day or the
     * application's job position has no vacancy left. Locks the plan item, so two contracts written at the same
     * moment take the last vacancy once.
     */
    public function assertAvailable(Application $application, CarbonInterface|string $writtenOn, ?string $exceptContractId = null): void
    {
        $day = $writtenOn instanceof CarbonInterface ? $writtenOn->toDateString() : $writtenOn;
        $plan = VacancyPlan::query()->covering($day)->sharedLock()->first();

        if ($plan === null) {
            throw ValidationException::withMessages(['vacancies' => [__('contracts.no_vacancy_plan', ['date' => $day])]]);
        }

        $allowed = (int) DB::table('vacancy_plan_items')->where('plan_id', $plan->id)->where('job_position_id', $application->job_position_id)->lockForUpdate()->value('vacancies');
        $used = $this->used($plan, [$application->job_position_id], $exceptContractId)->get($application->job_position_id, 0);

        if ($used >= $allowed) {
            throw ValidationException::withMessages(['vacancies' => [__('contracts.no_vacancy', [
                'position' => $application->jobPosition()->withTrashed()->value('name_ar'),
                'from' => $plan->starts_on->toDateString(),
                'to' => $plan->ends_on->toDateString(),
                'used' => $used,
                'allowed' => $allowed,
            ])]]);
        }
    }

    /**
     * First contracts written in the plan's period, by job position.
     *
     * @param  list<int>|null  $positions  only these positions (null: all)
     * @return Collection<int, int>
     */
    public function used(VacancyPlan $plan, ?array $positions = null, ?string $exceptContractId = null): Collection
    {
        return DB::table('contracts')
            ->join('applications', 'applications.id', '=', 'contracts.application_id')
            ->where('contracts.sequence', 1)
            ->whereBetween('contracts.written_on', [$plan->starts_on->toDateString(), $plan->ends_on->toDateString()])
            ->when($positions !== null, fn($query) => $query->whereIn('applications.job_position_id', $positions))
            ->when($exceptContractId !== null, fn($query) => $query->where('contracts.id', '<>', $exceptContractId))
            ->groupBy('applications.job_position_id')
            ->selectRaw('applications.job_position_id, count(*) as used')
            ->pluck('used', 'job_position_id')
            ->map(fn($used) => (int) $used);
    }

    private function store(UploadedFile $file, CarbonInterface $start, CarbonInterface $end, ?string $notes, StaffUser $actor, ?VacancyPlan $replaced): VacancyPlan
    {
        $items = VacancyFile::read($file->getRealPath());   // 422 on `file` with every problem and its row
        $id = (string) Str::uuid7();
        $extension = in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls'], true) ? strtolower($file->getClientOriginalExtension()) : 'xlsx';
        $disk = Storage::disk(config('contracts.vacancies_disk'));
        $path = $disk->putFileAs(trim((string) config('contracts.vacancies_directory'), '/'), $file, "{$id}.{$extension}");

        try {
            return DB::transaction(function () use ($id, $file, $path, $start, $end, $notes, $actor, $replaced, $items) {
                if ($replaced !== null) {
                    // Contracts being written against it finish first (they hold it FOR SHARE).
                    $locked = VacancyPlan::query()->whereKey($replaced->id)->lockForUpdate()->firstOrFail();

                    if ($locked->replaced_at !== null) {
                        throw ValidationException::withMessages(['plan' => [__('contracts.vacancy_plan_not_replaceable')]]);
                    }

                    $locked->forceFill(['replaced_at' => Date::now()])->save();
                }

                $plan = new VacancyPlan;
                $plan->forceFill([
                    'id' => $id,
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $end->toDateString(),
                    'original_filename' => SafeFilename::of($file->getClientOriginalName(), 'vacancies.xlsx'),
                    'stored_path' => $path,
                    'file_sha256' => hash_file('sha256', $file->getRealPath()),
                    'file_size' => $file->getSize(),
                    'notes' => $notes,
                    'replaces_plan_id' => $replaced?->id,
                    'uploaded_by' => $actor->id,
                    'created_at' => Date::now(),
                ])->save();

                $plan->items()->insert(array_map(fn(array $item) => $item + ['plan_id' => $id], $items));

                AuditTrail::record($replaced === null ? 'vacancy_plan.uploaded' : 'vacancy_plan.replaced', $actor, array_filter([
                    'plan_id' => $id,
                    'replaced_plan_id' => $replaced?->id,
                    'starts_on' => $plan->starts_on->toDateString(),
                    'ends_on' => $plan->ends_on->toDateString(),
                    'positions' => count($items),
                    'vacancies' => array_sum(array_column($items, 'vacancies')),
                ], fn($value) => $value !== null));

                return $plan;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            // Two uploads for overlapping periods at the same moment: the database let only one through.
            if ($e instanceof QueryException && str_contains($e->getMessage(), 'vacancy_plans_no_overlap')) {
                $other = VacancyPlan::query()->inForce()->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())->first();

                if ($other !== null) {
                    $this->overlapping($other);
                }
            }

            throw $e;
        }
    }

    private function overlapping(VacancyPlan $other): never
    {
        throw ValidationException::withMessages(['starts_on' => [__('contracts.vacancy_plan_overlaps', [
            'from' => $other->starts_on->toDateString(),
            'to' => $other->ends_on->toDateString(),
        ])]]);
    }
}
