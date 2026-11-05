<?php

namespace App\Modules\Reports\Support;

use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Recruitment\Support\ApplicationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * The filters every report takes: those of the applications list (which applications), and the period (when; see
 * ReportPeriod). Printed in the report's header as the user would read them: list entries by name, statuses by label.
 */
final class ReportFilters
{
    /** Ordering and paging belong to the list screen, not to a report. */
    private const LIST_ONLY = ['per_page'];

    /** @return array<string, mixed> validated */
    public static function validate(Request $request, array $extra = []): array
    {
        ApplicationSearch::prepare($request);
        $rules = array_diff_key(ApplicationSearch::rules(), array_flip(self::LIST_ONLY)) + ReportPeriod::rules() + $extra;

        $validator = validator($request->query(), $rules);
        $validator->after(fn(Validator $validator) => ReportPeriod::after($validator, $request->query()));

        return array_filter($validator->validate(), fn($value) => $value !== null && $value !== '' && $value !== []);
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
