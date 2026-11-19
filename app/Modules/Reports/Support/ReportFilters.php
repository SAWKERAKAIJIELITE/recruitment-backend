<?php

namespace App\Modules\Reports\Support;

use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Recruitment\Support\ApplicationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The filters every report takes: those of the applications list (which applications), and the period (when; see
 * ReportPeriod). Printed in the report's header as the user would read them: list entries by name, statuses by label.
 */
final class ReportFilters
{
    /** Ordering and paging belong to the list screen, not to a report. */
    private const LIST_ONLY = ['per_page'];

    /** @return array<string, list<mixed>> the rules of every report's filters (ReportRequest) */
    public static function rules(): array
    {
        return array_diff_key(ApplicationSearch::rules(), array_flip(self::LIST_ONLY)) + ReportPeriod::rules();
    }

    /**
     * For a reader who sees who the candidate is only (no applications.view, D-086): the identity filters of the
     * applications list, the period and the report's own (`format`, `sitting_id`), and `$also` of the applications'
     * filters (the contracts report keeps those of the contracts list, D-098). Any other filter is 422, as on the list:
     * filtering by health or residence would tell who has them, even with no such column.
     *
     * @param  list<string>  $also
     */
    public static function assertIdentityOnly(Request $request, array $also = []): void
    {
        $rules = ApplicationSearch::identityRules();
        $all = ApplicationSearch::rules();

        foreach ($also as $filter) {
            $rules[$filter] = $all[$filter];
        }

        Validator::make($request->all(), $rules, ['*.prohibited' => __('recruitment.identity_only_filter')])->validate();
    }

    /** @return array<string, mixed> the validated filters that were given ("0" is given; D-091) */
    public static function given(array $validated): array
    {
        return array_filter($validated, fn($value) => $value !== null && $value !== '' && $value !== []);
    }

    /** @return array<string, mixed> only the filters on applications */
    public static function applicationFilters(array $filters): array
    {
        return array_diff_key($filters, array_flip([...ReportPeriod::keys(), 'format', 'sitting_id']));
    }

    /** @return array<string, string> the applied filters, readable (the period is printed apart) */
    public static function describe(array $filters): array
    {
        $described = [];

        foreach (array_diff_key($filters, array_flip([...ReportPeriod::keys(), 'format', 'sort', 'direction'])) as $key => $value) {
            $table = ApplicationRules::LIST_FIELDS[$key] ?? ($key === 'birth_place_id' ? 'districts' : null);
            $label = __("validation.attributes.{$key}", [], 'ar');
            $label = $label === "validation.attributes.{$key}" ? $key : $label;

            $described[$label] = match (true) {
                $table !== null => (string) (DB::table($table)->where('id', (int) $value)->value('name_ar') ?? $value),
                $key === 'status' => collect((array) $value)->map(fn(string $status) => ApplicationStatus::from($status)->labelAr())->implode('، '),
                $key === 'sitting_id' => (string) (DB::table('interview_sittings')->where('id', $value)->value('location') ?? $value),
                default => is_array($value) ? implode('، ', $value) : (string) $value,
            };
        }

        return $described;
    }
}
