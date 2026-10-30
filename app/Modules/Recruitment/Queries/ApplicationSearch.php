<?php

namespace App\Modules\Recruitment\Queries;

use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\PhoneNumber;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The filters of the applications list (GET /applications), in one place: their validation rules and how each
 * narrows the query. Every filter is optional and they combine with AND. A filter is applied when its key is
 * present with a value, so 0 and false filter too.
 */
final class ApplicationSearch
{
    /** Exact match on an application column holding a list entry id. */
    private const LIST_FILTERS = [
        'job_position_id', 'nominating_entity_id', 'application_category_id', 'academic_qualification_id',
        'specialization_id', 'marital_status_id', 'health_condition_id', 'residence_district_id', 'residence_area_id',
    ];

    /** Yes/no columns of the application. */
    private const FLAG_FILTERS = ['studies_discontinued', 'works_for_other_government_entity', 'full_commitment', 'overnight_work'];

    /** Ranges on whole-number columns: filter name => column (`*_min` and `*_max`, both included). */
    private const RANGE_FILTERS = ['studying_years' => 'studying_years', 'quran_parts' => 'quran_parts_memorized'];

    public const SORTS = [
        'created_at' => 'applications.created_at',
        'nomination_number' => 'applications.nomination_number',
        'full_name' => 'candidates.name_search_key',
        'birth_date' => 'candidates.birth_date',
        'status' => 'applications.current_status',
    ];

    /** One `status` value is treated as a list of one, so it is validated like several. */
    public static function prepare(Request $request): Request
    {
        if (is_string($request->query('status')) && $request->query('status') !== '') {
            $request->merge(['status' => [$request->query('status')]]);
        }

        return $request;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $flag = ['nullable', Rule::in(['0', '1', 'true', 'false'])];
        $id = ['nullable', 'integer', 'min:1'];
        $count = ['nullable', 'integer', 'min:0', 'max:100'];

        $rules = [
            'q' => ['nullable', 'string', 'max:150'],
            'national_number' => ['nullable', 'string', 'max:30'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'existing_employee_number' => ['nullable', 'string', 'max:50'],
            'has_employee_number' => $flag,
            // One status, or several: status[]=awaiting_exam&status[]=exam_absent
            'status' => ['nullable', 'array', 'max:10'],
            'status.*' => [Rule::enum(ApplicationStatus::class)],
            'live' => $flag,                                  // 1: not ended yet; 0: contract ended or closed
            'birth_place_id' => $id,
            'candidate_active' => $flag,
            'birth_date_from' => ['nullable', 'date_format:Y-m-d'],
            'birth_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:birth_date_from'],
            'age_min' => $count,
            'age_max' => $count,
            'created_by' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'studying_years' => $count,                       // exact (kept for existing clients)
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];

        foreach (self::LIST_FILTERS as $filter) {
            $rules[$filter] = $id;
        }

        foreach (self::FLAG_FILTERS as $filter) {
            $rules[$filter] = $flag;
        }

        foreach (array_keys(self::RANGE_FILTERS) as $filter) {
            $rules["{$filter}_min"] = $count;
            $rules["{$filter}_max"] = $count;
        }

        return $rules;
    }

    /** @param array<string, mixed> $filters validated with rules() */
    public static function apply(Builder $query, array $filters): Builder
    {
        $has = fn(string $key) => isset($filters[$key]) && $filters[$key] !== '';
        $flag = fn(string $key) => filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN);

        if ($has('q')) {
            $q = trim($filters['q']);
            $like = '%' . addcslashes(ArabicText::searchKey($q), '\\%_') . '%';

            $query->where(fn(Builder $inner) => $inner
                ->where('applications.nomination_number', ArabicText::digitsToAscii($q))
                ->orWhereHas('candidate', fn(Builder $candidate) => $candidate->where('name_search_key', 'like', $like)));
        }

        if ($has('national_number')) {
            $query->whereHas('candidate', fn(Builder $candidate) => $candidate->where('national_number', NationalNumber::normalize($filters['national_number'])));
        }

        if ($has('phone_number')) {
            $query->where('applications.phone_number', PhoneNumber::normalize($filters['phone_number']));
        }

        if ($has('existing_employee_number')) {
            $query->where('applications.existing_employee_number', ArabicText::digitsToAscii(trim($filters['existing_employee_number'])));
        }

        if ($has('has_employee_number')) {
            $flag('has_employee_number') ? $query->whereNotNull('applications.existing_employee_number') : $query->whereNull('applications.existing_employee_number');
        }

        if ($has('status')) {
            $query->whereIn('applications.current_status', (array) $filters['status']);
        }

        if ($has('live')) {
            $flag('live') ? $query->live() : $query->whereIn('applications.current_status', ApplicationStatus::terminalValues());
        }

        foreach (self::LIST_FILTERS as $filter) {
            if ($has($filter)) {
                $query->where("applications.{$filter}", (int) $filters[$filter]);
            }
        }

        foreach (self::FLAG_FILTERS as $filter) {
            if ($has($filter)) {
                $query->where("applications.{$filter}", $flag($filter));
            }
        }

        if ($has('studying_years')) {
            $query->where('applications.studying_years', (int) $filters['studying_years']);
        }

        foreach (self::RANGE_FILTERS as $filter => $column) {
            if ($has("{$filter}_min")) {
                $query->where("applications.{$column}", '>=', (int) $filters["{$filter}_min"]);
            }

            if ($has("{$filter}_max")) {
                $query->where("applications.{$column}", '<=', (int) $filters["{$filter}_max"]);
            }
        }

        $person = array_filter([
            'birth_place_id' => $has('birth_place_id') ? (int) $filters['birth_place_id'] : null,
            'is_active' => $has('candidate_active') ? $flag('candidate_active') : null,
        ], fn($value) => $value !== null);

        // Ages in whole years today: age_min 25 means born on or before today 25 years ago.
        $bornFrom = $has('birth_date_from') ? $filters['birth_date_from'] : null;
        $bornTo = $has('birth_date_to') ? $filters['birth_date_to'] : null;

        if ($has('age_max')) {
            $latest = Carbon::today()->subYears((int) $filters['age_max'] + 1)->addDay()->toDateString();
            $bornFrom = $bornFrom === null ? $latest : max($bornFrom, $latest);
        }

        if ($has('age_min')) {
            $earliest = Carbon::today()->subYears((int) $filters['age_min'])->toDateString();
            $bornTo = $bornTo === null ? $earliest : min($bornTo, $earliest);
        }

        if ($person !== [] || $bornFrom !== null || $bornTo !== null) {
            $query->whereHas('candidate', function (Builder $candidate) use ($person, $bornFrom, $bornTo) {
                $candidate->where($person)
                    ->when($bornFrom !== null, fn(Builder $q) => $q->where('birth_date', '>=', $bornFrom))
                    ->when($bornTo !== null, fn(Builder $q) => $q->where('birth_date', '<=', $bornTo));
            });
        }

        if ($has('created_by')) {
            $query->where('applications.created_by', $filters['created_by']);
        }

        if ($has('from')) {
            $query->where('applications.created_at', '>=', $filters['from']);
        }

        if ($has('to')) {
            $query->where('applications.created_at', '<', Carbon::parse($filters['to'])->addDay()->toDateString());
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? ($sort === 'created_at' ? 'desc' : 'asc');

        if (str_starts_with(self::SORTS[$sort], 'candidates.')) {
            $query->join('candidates', 'candidates.id', '=', 'applications.candidate_id')->select('applications.*');
        }

        // The id breaks ties, so pages never repeat or skip a row.
        return $query->orderBy(self::SORTS[$sort], $direction)->orderBy('applications.id', $direction);
    }
}
