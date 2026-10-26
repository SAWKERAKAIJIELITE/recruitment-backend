<?php

namespace App\Modules\Imports\Support;

use App\Modules\Imports\Enums\ImportField;
use App\Modules\Recruitment\Support\ApplicationRules;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\PhoneNumber;
use App\Support\ArabicText;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Turns the cells of one row into application values (D-040).
 *
 * Nothing is guessed silently: a value that cannot be read becomes null with a "parse issue" that says
 * what the cell contained and why it was not accepted. Rules that also apply to corrected values
 * (required fields, ranges, lengths, duplicates, the person on file) are checked later by ImportChecker.
 */
final class RowParser
{
    /** Words accepted as yes / no (compared after Arabic normalisation). */
    private const YES = ['نعم', 'ن', 'اجل', 'اي', 'بلي', 'yes', 'y', 'true', '1', 'صح', '✓', '✔', 'موافق', 'ملتزم', 'متفرغ', 'يعمل'];

    private const NO = ['لا', 'كلا', 'no', 'n', 'false', '0', 'خطا', '✗', '✘', 'غير موافق', 'غير ملتزم', 'غير متفرغ', 'لا يعمل'];

    public function __construct(
        private readonly ReferenceLookup $lookup,
        private readonly string $calendar = '1900',
        private readonly ?int $defaultNominatingEntityId = null,
    ) {
    }

    /**
     * @param  array<string, CellValue>  $cells  ImportField value => cell (fields without a column are absent)
     * @return array{data: array<string, mixed>, parse_issues: array<string, array{code: string, message: string, value: string}>}
     */
    public function parse(array $cells): array
    {
        $data = [];
        $issues = [];

        // Enum order matters: the residence district is read before the residence place.
        foreach (ImportField::cases() as $field) {
            $cell = $cells[$field->value] ?? new CellValue(null, '');
            $key = $field->dataKey();

            if ($field->detailsKey() !== null) {
                $data[$field->detailsKey()] = null;
            }

            if ($field === ImportField::StudyingYears) {
                $data['studies_discontinued'] = false;
            }

            [$value, $issue] = $this->parseField($field, $cell, $data);

            $data[$key] = $value;

            if (is_array($value) && array_key_exists('details', $value)) {   // list value with details
                $data[$key] = $value['id'];
                $data[$field->detailsKey()] = $value['details'];
            }

            if (is_array($value) && array_key_exists('discontinued', $value)) {   // "3 ومنقطع"
                $data[$key] = $value['years'];
                $data['studies_discontinued'] = $value['discontinued'];
            }

            if ($issue !== null) {
                $issues[$key] = $issue + ['value' => $cell->display];
            }
        }

        return ['data' => $data, 'parse_issues' => $issues];
    }

    /** @return array{0: mixed, 1: array{code: string, message: string}|null} */
    private function parseField(ImportField $field, CellValue $cell, array $data): array
    {
        if ($cell->isEmpty()) {
            return match ($field) {
                ImportField::NominatingEntity => [$this->defaultNominatingEntityId, null],
                default => [null, null],   // missing values are reported by the checker (they may be optional)
            };
        }

        return match ($field) {
            ImportField::NominatingEntity => $this->listValue('nominating_entities', $cell),
            ImportField::JobPosition => $this->listValue('job_positions', $cell),
            ImportField::ApplicationCategory => $this->listValue('application_categories', $cell),
            ImportField::BirthPlace, ImportField::ResidenceDistrict => $this->listValue('districts', $cell),
            ImportField::MaritalStatus => $this->listValue('marital_statuses', $cell),
            ImportField::AcademicQualification => $this->listValue('academic_qualifications', $cell),
            ImportField::Specialization => $this->listValue('specializations', $cell),
            ImportField::Health => $this->listValueWithDetails('health_conditions', $cell, null),
            ImportField::ResidencePlace => $this->residencePlace($cell, $data['residence_district_id'] ?? null),
            ImportField::FullName, ImportField::MotherName => [self::cleanText($cell->text()), null],
            ImportField::ExistingEmployeeNumber => [ArabicText::digitsToAscii(self::cleanText($cell->text())), null],
            ImportField::Notes => [self::cleanText($cell->text()), null],
            ImportField::NationalNumber => $this->nationalNumber($cell),
            ImportField::PhoneNumber => $this->phoneNumber($cell),
            ImportField::BirthDate => $this->date($cell),
            ImportField::StudyingYears => $this->studyYears($cell),
            ImportField::QuranParts => $this->integer($cell),
            ImportField::OtherGovernmentEntity, ImportField::OvernightWork, ImportField::FullCommitment => $this->boolean($cell),
        };
    }

    private function listValue(string $table, CellValue $cell): array
    {
        $found = $this->lookup->find($table, $cell->text());

        return $this->listResult($table, $cell, $found);
    }

    /** "مريض - سكري" → condition "مريض", details "سكري". Unmatched text is kept as details so nothing is lost. */
    private function listValueWithDetails(string $table, CellValue $cell, ?int $districtId): array
    {
        $found = $this->lookup->findWithDetails($table, $cell->text(), $districtId);

        if ($found === null || $found['archived']) {
            [, $issue] = $this->listResult($table, $cell, $found);

            return [['id' => null, 'details' => $found['details'] ?? $cell->text()], $issue];
        }

        return [['id' => $found['id'], 'details' => $found['details']], null];
    }

    /**
     * The legacy "مكان الإقامة الحالية" is free text (often a town). The area is optional (D-043): when the
     * text starts with an active area of the residence district, that area is used and the rest is details;
     * otherwise the whole text is kept as details. Never an error.
     */
    private function residencePlace(CellValue $cell, ?int $districtId): array
    {
        $found = $districtId === null ? null : $this->lookup->findWithDetails('areas', $cell->text(), $districtId);

        if ($found === null || $found['archived']) {
            return [['id' => null, 'details' => self::cleanText($cell->text())], null];
        }

        return [['id' => $found['id'], 'details' => $found['details']], null];
    }

    /** The legacy list offers "1" … "6" and "1 ومنقطع" … "6 ومنقطع" (studied N years, then stopped; D-044). */
    private function studyYears(CellValue $cell): array
    {
        if ($cell->isNumeric()) {
            [$years, $issue] = $this->integer($cell);

            return [['years' => $years, 'discontinued' => false], $issue];
        }

        $text = ArabicText::searchKey(ArabicText::digitsToAscii($cell->text()));

        if (preg_match('/^(\d{1,2})\s*(?:-|،|,)?\s*(و\s*)?(منقطع|متوقف)?$/u', $text, $m) !== 1) {
            return [['years' => null, 'discontinued' => false], self::issue('invalid_number', ['value' => $cell->display])];
        }

        return [['years' => (int) $m[1], 'discontinued' => ($m[3] ?? '') !== ''], null];
    }

    private function listResult(string $table, CellValue $cell, ?array $found): array
    {
        $params = ['value' => $cell->display, 'list' => __("imports.lists.{$table}")];

        return match (true) {
            $found === null => [null, self::issue('unknown_value', $params)],
            $found['archived'] => [null, self::issue('archived_value', $params)],
            default => [$found['id'], null],
        };
    }

    private function nationalNumber(CellValue $cell): array
    {
        $digits = self::digitsOnly($cell->text());

        if ($digits === null) {
            return [null, self::issue('national_number_format', ['value' => $cell->display])];
        }

        // Excel kept it as a number: a leading zero is gone and cannot be recovered with certainty.
        if ($cell->isNumeric() && strlen($digits) < NationalNumber::LENGTH) {
            return [null, self::issue('national_number_leading_zero', [
                'suggestion' => str_pad($digits, NationalNumber::LENGTH, '0', STR_PAD_LEFT),
            ])];
        }

        return [$digits, null];   // the length is checked with the other rules (corrections too)
    }

    private function phoneNumber(CellValue $cell): array
    {
        $phone = PhoneNumber::normalize($cell->text());

        if ($cell->isNumeric() && ! str_starts_with($phone, '0')) {
            return [null, self::issue('phone_leading_zero', [
                'suggestion' => str_starts_with($phone, '963') ? '+' . $phone : '0' . $phone,
            ])];
        }

        return [$phone, null];
    }

    /** Excel serial numbers (in the workbook's date system, AC-17) or day/month/year text in any digit style. */
    private function date(CellValue $cell): array
    {
        if ($cell->isNumeric()) {
            $date = $this->fromSerial((float) $cell->value);

            return $date === null ? [null, self::issue('invalid_date', ['value' => $cell->display])] : [$date, null];
        }

        $text = ArabicText::digitsToAscii(trim($cell->text()));
        $text = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?.*$/', '', $text) ?? $text;   // "1990-05-01 00:00:00"

        $parts = match (true) {
            (bool) preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $text, $m) => [(int) $m[1], (int) $m[2], (int) $m[3]],
            (bool) preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $text, $m) => self::dayMonthYear((int) $m[1], (int) $m[2], (int) $m[3]),
            default => null,
        };

        if ($parts === null || ! checkdate($parts[1], $parts[2], $parts[0])) {
            return [null, self::issue('invalid_date', ['value' => $cell->display])];
        }

        return [sprintf('%04d-%02d-%02d', ...$parts), null];
    }

    /** Day first (the local convention); month first only when the first number cannot be a month. */
    private static function dayMonthYear(int $first, int $second, int $year): array
    {
        return $second > 12 && $first <= 12 ? [$year, $first, $second] : [$year, $second, $first];
    }

    private function fromSerial(float $serial): ?string
    {
        if ($serial < 1 || $serial > 2958465) {   // 2958465 = 9999-12-31
            return null;
        }

        $previous = ExcelDate::getExcelCalendar();

        try {
            ExcelDate::setExcelCalendar((int) $this->calendar);

            return DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject(floor($serial), 'UTC'))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        } finally {
            ExcelDate::setExcelCalendar($previous);
        }
    }

    private function integer(CellValue $cell): array
    {
        if ($cell->isNumeric()) {
            $number = (float) $cell->value;

            // Whole and within a sane range before casting (a huge float would wrap to a wrong integer).
            return floor($number) === $number && abs($number) <= 9999
                ? [(int) $number, null]
                : [null, self::issue('invalid_number', ['value' => $cell->display])];
        }

        $text = ArabicText::digitsToAscii(trim($cell->text()));

        return preg_match('/^\d{1,4}$/', $text) === 1
            ? [(int) $text, null]
            : [null, self::issue('invalid_number', ['value' => $cell->display])];
    }

    private function boolean(CellValue $cell): array
    {
        if (is_bool($cell->value)) {
            return [$cell->value, null];
        }

        // Numbers mean yes/no only as exactly 1 or 0 ("-1" or "2" are not answers).
        $digits = ArabicText::digitsToAscii(trim($cell->text()));

        if ($cell->isNumeric() || preg_match('/^[\-+]?\d+([.,]\d+)?$/', $digits) === 1) {
            return match ($digits) {
                '1' => [true, null],
                '0' => [false, null],
                default => [null, self::issue('invalid_boolean', ['value' => $cell->display])],
            };
        }

        $key = ArabicText::searchKey(preg_replace('/[\p{P}]+/u', ' ', $cell->text()) ?? $cell->text());
        $normalize = fn(array $words) => array_map(fn(string $word) => ArabicText::searchKey($word), $words);

        return match (true) {
            in_array($key, $normalize(self::NO), true) => [false, null],
            in_array($key, $normalize(self::YES), true) => [true, null],
            default => [null, self::issue('invalid_boolean', ['value' => $cell->display])],
        };
    }

    /** ASCII digits without spaces, dashes or dots; null when anything else is in the text. */
    private static function digitsOnly(string $text): ?string
    {
        $text = preg_replace('/[\s\-\.\x{200F}\x{200E}]/u', '', ArabicText::digitsToAscii($text)) ?? '';

        return preg_match('/^\d+$/', $text) === 1 ? $text : null;
    }

    private static function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return array{code: string, message: string} */
    private static function issue(string $code, array $params): array
    {
        return ['code' => $code, 'message' => __("imports.{$code}", $params)];
    }

    /** Whether the value of a name passes the shared rules (used by the checker too). */
    public static function nameProblem(string $name): ?string
    {
        if (preg_match(ApplicationRules::NAME_FORBIDDEN, $name) === 1) {
            return 'name_has_digits';
        }

        $length = mb_strlen($name);

        return $length < ApplicationRules::NAME_MIN || $length > ApplicationRules::NAME_MAX ? 'name_length' : null;
    }
}
