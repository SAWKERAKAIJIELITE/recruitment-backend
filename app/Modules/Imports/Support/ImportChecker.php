<?php

namespace App\Modules\Imports\Support;

use App\Modules\Imports\Enums\ImportRowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Support\ApplicationRules as R;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Support\ArabicText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Checks every open row of a batch against the same rules as POST /applications, plus what only an import
 * has: duplicates inside the file and people already on file (D-040, D-041). Run after upload, after
 * every correction or review decision, when lists change (revalidate), and again just before approval.
 *
 * - error: the row cannot be imported until corrected (status invalid)
 * - review: a person must decide; the row cannot be imported until then (status needs_review)
 *
 * Lists, people and live applications are loaded once per run, not once per row.
 */
class ImportChecker
{
    public function check(ImportBatch $batch): void
    {
        $rows = $batch->rows()->get();
        $open = $rows->filter(fn(ImportRow $row) => $row->status->isChecked());

        $lookup = new ReferenceLookup();
        $people = $this->people($open);
        $live = $this->liveApplications($people);
        $firstRowOf = $this->firstRowPerNationalNumber($open);

        foreach ($open as $row) {
            [$issues, $candidate] = $this->issues($row, $lookup, $people, $live, $firstRowOf);

            $status = match (true) {
                collect($issues)->contains('severity', 'error') => ImportRowStatus::Invalid,
                $issues !== [] => ImportRowStatus::NeedsReview,
                default => ImportRowStatus::Valid,
            };

            $row->forceFill(['issues' => $issues, 'status' => $status, 'candidate_id' => $candidate?->id]);

            if ($row->isDirty()) {
                $row->save();
            }
        }
    }

    /**
     * @param  Collection<string, Candidate>  $people
     * @param  array<string, string>  $live  candidate id => nomination number
     * @param  array<string, int>  $firstRowOf  national number => first row number using it
     * @return array{0: list<array{field: string, code: string, severity: string, message: string}>, 1: ?Candidate}
     */
    private function issues(ImportRow $row, ReferenceLookup $lookup, Collection $people, array $live, array $firstRowOf): array
    {
        $issues = [];
        $data = $row->data;
        $error = function (string $field, string $code, array $params = []) use (&$issues) {
            $issues[] = ['field' => $field, 'code' => $code, 'severity' => 'error', 'message' => __("imports.{$code}", $params)];
        };
        $label = fn(string $field) => __("imports.fields.{$field}");

        // What could not be read from the cells (until corrected).
        foreach ($row->parse_issues ?? [] as $field => $issue) {
            $issues[] = ['field' => $field, 'code' => $issue['code'], 'severity' => 'error', 'message' => $issue['message']];
        }

        $unreadable = array_keys($row->parse_issues ?? []);
        $present = fn(string $field) => ($data[$field] ?? null) !== null && ($data[$field] ?? null) !== '' && ! in_array($field, $unreadable, true);

        foreach (R::REQUIRED as $field) {
            if (! in_array($field, $unreadable, true) && ! $present($field)) {
                $error($field, 'missing', ['field' => $label($field)]);
            }
        }

        // List values must still be active entries (an entry may have been archived since upload).
        foreach (R::LIST_FIELDS as $field => $table) {
            if (! $present($field)) {
                continue;
            }

            $id = (int) $data[$field];

            if ($field === 'residence_area_id') {
                $district = $present('residence_district_id') ? (int) $data['residence_district_id'] : null;

                if (! $lookup->isActive('areas', $id)) {
                    $error($field, 'inactive_entry', ['field' => $label($field)]);
                } elseif ($district !== null && $lookup->districtOfArea($id) !== $district) {
                    $error($field, 'area_not_in_district');
                }

                continue;
            }

            if (! $lookup->isActive($table, $id)) {
                $error($field, 'inactive_entry', ['field' => $label($field)]);
            }
        }

        foreach (['candidate.full_name', 'candidate.mother_name'] as $field) {
            if ($present($field) && ($problem = RowParser::nameProblem((string) $data[$field])) !== null) {
                $error($field, $problem, ['field' => $label($field), 'min' => R::NAME_MIN, 'max' => R::NAME_MAX]);
            }
        }

        $nationalNumber = $present('candidate.national_number') ? (string) $data['candidate.national_number'] : null;

        if ($nationalNumber !== null && preg_match('/^\d{' . NationalNumber::LENGTH . '}$/', $nationalNumber) !== 1) {
            $error('candidate.national_number', 'national_number_format', ['value' => $nationalNumber]);
            $nationalNumber = null;
        }

        if ($present('candidate.birth_date')) {
            // Native parser: it returns false instead of throwing, and the round trip rejects 2001-02-30.
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['candidate.birth_date']);
            $day = $date === false ? null : $date->format('Y-m-d');

            if ($day === null || $day !== $data['candidate.birth_date']) {
                $error('candidate.birth_date', 'invalid_date', ['value' => $data['candidate.birth_date']]);
            } elseif ($day <= R::BIRTH_DATE_AFTER || $day >= Date::today()->toDateString()) {
                $error('candidate.birth_date', 'date_out_of_range', ['after' => R::BIRTH_DATE_AFTER]);
            }
        }

        if ($present('phone_number') && preg_match(R::PHONE_PATTERN, (string) $data['phone_number']) !== 1) {
            $error('phone_number', 'phone_format', ['value' => $data['phone_number']]);
        }

        if (($data['studies_discontinued'] ?? false) === true && ! $present('studying_years') && ! in_array('studying_years', $unreadable, true)) {
            $error('studying_years', 'missing', ['field' => $label('studying_years')]);
        }

        foreach (['studying_years' => R::STUDYING_YEARS_MAX, 'quran_parts_memorized' => R::QURAN_PARTS_MAX] as $field => $max) {
            if ($present($field) && ((int) $data[$field] < 0 || (int) $data[$field] > $max)) {
                $error($field, 'number_out_of_range', ['field' => $label($field), 'max' => $max]);
            }
        }

        $lengths = [
            'existing_employee_number' => R::EMPLOYEE_NUMBER_MAX,
            'residence_details' => R::RESIDENCE_DETAILS_MAX,
            'health_details' => R::LONG_TEXT_MAX,
            'notes' => R::LONG_TEXT_MAX,
        ];

        foreach ($lengths as $field => $max) {
            if ($present($field) && mb_strlen((string) $data[$field]) > $max) {
                $error($field, 'text_too_long', ['field' => $label($field), 'max' => $max]);
            }
        }

        if ($nationalNumber === null) {
            return [$issues, null];
        }

        // One row per person in a file: later rows with the same national number are duplicates.
        if (($firstRowOf[$nationalNumber] ?? $row->row_number) !== $row->row_number) {
            $error('candidate.national_number', 'duplicate_in_file', ['row' => $firstRowOf[$nationalNumber]]);
        }

        $person = $people->get($nationalNumber);

        if ($person === null) {
            return [$issues, null];
        }

        if (isset($live[$person->id])) {
            $error('candidate.national_number', 'live_application', ['number' => $live[$person->id]]);
        }

        $differences = $this->differences($data, $person);

        if ($differences !== [] && $row->confirmed_national_number !== $nationalNumber) {
            $issues[] = [
                'field' => 'candidate.national_number',
                'code' => 'person_mismatch',
                'severity' => 'review',
                'message' => __('imports.person_mismatch', ['differences' => implode('؛ ', $differences)]),
            ];
        }

        return [$issues, $person];
    }

    /** What the file says about the person that differs from the stored person (spelling variants ignored). */
    private function differences(array $data, Candidate $person): array
    {
        $differences = [];
        $compare = [
            'candidate.full_name' => [$person->full_name, fn($value) => ArabicText::searchKey((string) $value)],
            'candidate.mother_name' => [$person->mother_name, fn($value) => ArabicText::searchKey((string) $value)],
            'candidate.birth_date' => [$person->birth_date?->toDateString(), fn($value) => (string) $value],
        ];

        foreach ($compare as $field => [$stored, $normalize]) {
            $file = $data[$field] ?? null;

            if ($file !== null && $file !== '' && $normalize($file) !== $normalize($stored)) {
                $differences[] = __('imports.difference', ['field' => __("imports.fields.{$field}"), 'file' => $file, 'stored' => $stored]);
            }
        }

        return $differences;
    }

    /** @return Collection<string, Candidate> national number => person, for the numbers used by the rows */
    private function people(Collection $rows): Collection
    {
        $numbers = $rows->map(fn(ImportRow $row) => $row->nationalNumber())->filter()->unique()->values();

        return $numbers->isEmpty()
            ? collect()
            : Candidate::query()->whereIn('national_number', $numbers)->get()->keyBy('national_number');
    }

    /** @return array<string, string> candidate id => nomination number of their live application */
    private function liveApplications(Collection $people): array
    {
        if ($people->isEmpty()) {
            return [];
        }

        return Application::query()->live()
            ->whereIn('candidate_id', $people->pluck('id'))
            ->pluck('nomination_number', 'candidate_id')
            ->all();
    }

    /** @return array<string, int> national number => the first open row (by row number) that uses it */
    private function firstRowPerNationalNumber(Collection $rows): array
    {
        $first = [];

        foreach ($rows->sortBy('row_number') as $row) {
            $number = $row->nationalNumber();

            if ($number !== null && ! isset($first[$number])) {
                $first[$number] = $row->row_number;
            }
        }

        return $first;
    }
}
