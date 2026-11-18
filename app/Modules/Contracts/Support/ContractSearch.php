<?php

namespace App\Modules\Contracts\Support;

use App\Modules\Contracts\Enums\ContractState;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The filters of the contracts list (D-064), shared by the list and ملف التثبيت (D-098): `state`, `q` (name,
 * nomination or reference number), `contract_type_id`, `unit_id`, `job_position_id`, `nominating_entity_id`,
 * `residence_district_id`, `residence_area_id` (the application's, as the reports take them), `written_from` /
 * `written_to` and `ends_from` / `ends_to` (YYYY-MM-DD, included), `latest` (1: the latest contract of each application).
 */
final class ContractSearch
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'state' => ['nullable', Rule::enum(ContractState::class)],
            'q' => ['nullable', 'string', 'max:150'],
            'contract_type_id' => ['nullable', 'integer', Rule::exists('contract_types', 'id')],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'job_position_id' => ['nullable', 'integer', Rule::exists('job_positions', 'id')],
            'nominating_entity_id' => ['nullable', 'integer', Rule::exists('nominating_entities', 'id')],
            'residence_district_id' => ['nullable', 'integer', Rule::exists('residence_districts', 'id')],
            'residence_area_id' => ['nullable', 'integer', Rule::exists('residence_areas', 'id')],
            'written_from' => ['nullable', 'date_format:Y-m-d'],
            'written_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:written_from'],
            'ends_from' => ['nullable', 'date_format:Y-m-d'],
            'ends_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:ends_from'],
            'latest' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
        ];
    }

    /** @param array<string, mixed> $filters validated with rules() */
    public static function apply(Builder $query, array $filters): Builder
    {
        $onApplication = fn(string $column) => fn($query, $value) => $query->whereHas('application', fn($application) => $application->where($column, $value));

        return $query
            ->whenGiven($filters['state'] ?? null, fn($query, $state) => $query->inState(ContractState::from($state)))
            ->whenGiven($filters['q'] ?? null, function ($query, string $q) {
                $like = '%' . addcslashes(ArabicText::searchKey(trim($q)), '\\%_') . '%';
                $plain = ArabicText::digitsToAscii(trim($q));

                $query->where(fn($inner) => $inner
                    ->where('contracts.reference_number', $plain)
                    ->orWhereHas('application', fn($application) => $application
                        ->where('nomination_number', $plain)
                        ->orWhereHas('candidate', fn($candidate) => $candidate->where('name_search_key', 'like', $like))));
            })
            ->whenGiven($filters['contract_type_id'] ?? null, fn($query, $value) => $query->where('contracts.contract_type_id', $value))
            ->whenGiven($filters['unit_id'] ?? null, fn($query, $value) => $query->where('contracts.unit_id', $value))
            ->whenGiven($filters['job_position_id'] ?? null, $onApplication('job_position_id'))
            ->whenGiven($filters['nominating_entity_id'] ?? null, $onApplication('nominating_entity_id'))
            ->whenGiven($filters['residence_district_id'] ?? null, $onApplication('residence_district_id'))
            ->whenGiven($filters['residence_area_id'] ?? null, $onApplication('residence_area_id'))
            ->whenGiven($filters['written_from'] ?? null, fn($query, $date) => $query->where('contracts.written_on', '>=', $date))
            ->whenGiven($filters['written_to'] ?? null, fn($query, $date) => $query->where('contracts.written_on', '<=', $date))
            ->whenGiven($filters['ends_from'] ?? null, fn($query, $date) => $query->where('contracts.ends_on', '>=', $date))
            ->whenGiven($filters['ends_to'] ?? null, fn($query, $date) => $query->where('contracts.ends_on', '<=', $date))
            ->when(in_array($filters['latest'] ?? null, ['1', 'true'], true), fn($query) => $query->whereNotExists(fn($renewal) => $renewal->from('contracts as renewal')->whereColumn('renewal.renews_contract_id', 'contracts.id')));
    }
}
