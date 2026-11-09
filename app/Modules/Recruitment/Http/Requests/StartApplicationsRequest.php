<?php

namespace App\Modules\Recruitment\Http\Requests;

use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Which received applications to start (D-087): `application_ids`, or `filters` (those of the applications list,
 * applied to the received ones; no `status`, `live`, sort or paging).
 */
class StartApplicationsRequest extends FormRequest
{
    /** Filters that make no sense here: the status is received, and the whole match is started. */
    private const NOT_FILTERS = ['status', 'status.*', 'live', 'sort', 'direction', 'per_page'];

    public function authorize(): bool
    {
        return true;   // the can:applications.start route middleware decides
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('application_ids') && $this->has('filters')) {
            throw ValidationException::withMessages(['filters' => [__('recruitment.start_needs_one')]]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'application_ids' => ['required_without:filters', 'array', 'min:1', 'max:1000'],
            'application_ids.*' => ['uuid', 'distinct'],
            /** The applications list's filters, e.g. {"import_batch_id": "…", "job_position_id": 3}. */
            'filters' => ['required_without:application_ids', 'array'],
        ];

        foreach (ApplicationSearch::rules() as $filter => $filterRules) {
            $rules["filters.{$filter}"] = in_array($filter, self::NOT_FILTERS, true) ? ['prohibited'] : $filterRules;
        }

        return $rules;
    }

    /**
     * The ids to start: those sent, or the received applications the filters match.
     *
     * @return list<string>
     */
    public function applicationIds(): array
    {
        if ($this->has('application_ids')) {
            return array_values($this->validated('application_ids'));
        }

        $query = ApplicationSearch::filter(Application::query()->where('applications.current_status', ApplicationStatus::Received), $this->validated('filters') ?? []);
        $count = (clone $query)->count();

        if ($count === 0) {
            throw ValidationException::withMessages(['filters' => [__('recruitment.start_nothing')]]);
        }

        if ($count > ApplicationLifecycle::START_MAX) {
            throw ValidationException::withMessages(['filters' => [__('recruitment.start_too_many', ['count' => $count, 'max' => ApplicationLifecycle::START_MAX])]]);
        }

        return $query->orderBy('applications.nomination_number')->pluck('applications.id')->all();
    }
}
