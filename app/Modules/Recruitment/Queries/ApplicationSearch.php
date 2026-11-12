<?php

namespace App\Modules\Recruitment\Queries;

use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\PhoneNumber;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The filters of the applications list (GET /applications), in one place: their validation rules and how each
 * narrows the query. Every filter is optional and they combine with AND. A filter is applied when its key is
 * present with a value, so 0 and false filter too.
 */
final class ApplicationSearch
{
    /** Exact match on an application column holding a list entry id: filter => the list's table (the id must exist there; archived entries too). */
    private const LIST_FILTERS = [
        'job_position_id' => 'job_positions',
        'nominating_entity_id' => 'nominating_entities',
        'application_category_id' => 'application_categories',
        'academic_qualification_id' => 'academic_qualifications',
        'specialization_id' => 'specializations',
        'marital_status_id' => 'marital_statuses',
        'health_condition_id' => 'health_conditions',
        'residence_district_id' => 'districts',
        'residence_area_id' => 'areas',
    ];

    /** Yes/no columns of the application. */
    private const FLAG_FILTERS = ['studies_discontinued', 'works_for_other_government_entity', 'full_commitment', 'overnight_work'];

    /** Ranges on whole-number columns: filter name => [column, largest value] (`*_min` and `*_max`, both included; the columns' CHECKs). */
    private const RANGE_FILTERS = ['studying_years' => ['studying_years', 15], 'quran_parts' => ['quran_parts_memorized', 30]];

    /** The filters the offices other than Recruitment may use: who the candidate is and where the application stands (D-086). */
    public const IDENTITY_FILTERS = ['q', 'national_number', 'status', 'status.*', 'live', 'job_position_id', 'import_batch_id', 'from', 'to', 'sort', 'direction', 'per_page'];

    public const SORTS = [
        'created_at' => 'applications.created_at',
        // The latest change: a correction, or a status move (the history's trigger sets it; D-099).
        'updated_at' => 'applications.updated_at',
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
        $age = ['nullable', 'integer', 'min:0', 'max:100'];

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
            'birth_place_id' => [...$id, Rule::exists('districts', 'id')],
            'candidate_active' => $flag,
            'birth_date_from' => ['nullable', 'date_format:Y-m-d'],
            'birth_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:birth_date_from'],
            'age_min' => $age,
            'age_max' => $age,
            'created_by' => ['nullable', 'uuid'],
            'import_batch_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'studying_years' => ['nullable', 'integer', 'min:0', 'max:15'],   // exact (kept for existing clients)
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];

        foreach (self::LIST_FILTERS as $filter => $table) {
            $rules[$filter] = [...$id, Rule::exists($table, 'id')];
        }

        foreach (self::FLAG_FILTERS as $filter) {
            $rules[$filter] = $flag;
        }

        foreach (self::RANGE_FILTERS as $filter => [, $max]) {
            $rules["{$filter}_min"] = ['nullable', 'integer', 'min:0', "max:{$max}"];
            $rules["{$filter}_max"] = ['nullable', 'integer', 'min:0', "max:{$max}"];
        }

        return $rules;
    }

    /**
     * rules() for an office that sees who the candidate is only: every other filter is refused, and the list is not
     * sorted by birth date.
     *
     * @return array<string, list<mixed>>
     */
    public static function identityRules(): array
    {
        $rules = [];

        foreach (self::rules() as $filter => $filterRules) {
            $rules[$filter] = in_array($filter, self::IDENTITY_FILTERS, true) ? $filterRules : ['prohibited'];
        }

        $rules['sort'] = ['nullable', Rule::in(array_values(array_diff(array_keys(self::SORTS), ['birth_date'])))];

        return $rules;
    }

    /** For an office that sees who the candidate is only: 422 on any other filter (and on sorting by birth date). */
    public static function assertIdentityOnly(Request $request): void
    {
        Validator::make($request->all(), self::identityRules(), ['*.prohibited' => __('recruitment.identity_only_filter')])->validate();
    }

    /** Filters and sorts. @param array<string, mixed> $filters validated with rules() */
    public static function apply(Builder $query, array $filters): Builder
    {
        return self::sort(self::filter($query, $filters), $filters);
    }

    /** The filters only (for lists with their own order, like the interview queue). @param array<string, mixed> $filters */
    public static function filter(Builder $query, array $filters): Builder
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

        foreach (array_keys(self::LIST_FILTERS) as $filter) {
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

        foreach (self::RANGE_FILTERS as $filter => [$column]) {
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

        if ($has('import_batch_id')) {
            $query->whereExists(fn($row) => $row->from('import_rows')
                ->whereColumn('import_rows.application_id', 'applications.id')
                ->where('import_rows.import_batch_id', $filters['import_batch_id']));
        }

        // The opening day in Damascus, like every business day (D-081, D-093).
        if ($has('from')) {
            $query->where('applications.created_at', '>=', BusinessDay::startOf($filters['from']));
        }

        if ($has('to')) {
            $query->where('applications.created_at', '<', BusinessDay::startOfNext($filters['to']));
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private static function sort(Builder $query, array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? (in_array($sort, ['created_at', 'updated_at'], true) ? 'desc' : 'asc');

        if (str_starts_with(self::SORTS[$sort], 'candidates.')) {
            $query->join('candidates', 'candidates.id', '=', 'applications.candidate_id')->select('applications.*');
        }

        // The id breaks ties, so pages never repeat or skip a row.
        return $query->orderBy(self::SORTS[$sort], $direction)->orderBy('applications.id', $direction);
    }
}
