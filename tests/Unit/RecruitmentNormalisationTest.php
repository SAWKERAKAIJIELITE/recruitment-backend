<?php

namespace Tests\Unit;

use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecruitmentNormalisationTest extends TestCase
{
    public static function nationalNumbers(): array
    {
        return [
            'already clean' => ['01234567890', '01234567890'],
            'arabic-indic digits' => ['٠١٢٣٤٥٦٧٨٩٠', '01234567890'],
            'extended arabic-indic digits' => ['۰۱۲۳۴۵۶۷۸۹۰', '01234567890'],
            'separators dropped' => ['012-345 678.90', '01234567890'],
            'leading zeros kept' => ['00000000001', '00000000001'],
        ];
    }

    #[DataProvider('nationalNumbers')]
    public function test_national_number(string $typed, string $expected): void
    {
        $this->assertSame($expected, NationalNumber::normalize($typed));
    }

    public static function phoneNumbers(): array
    {
        return [
            'already clean' => ['0912345678', '0912345678'],
            'arabic-indic digits and spaces' => ['٠٩١٢ ٣٤٥ ٦٧٨', '0912345678'],
            'dashes, dots and brackets' => ['(011) 222-33.44', '0112223344'],
            'international prefix kept' => ['+963 912 345 678', '+963912345678'],
            'letters are left for validation to refuse' => ['09ab', '09ab'],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function test_phone_number(string $typed, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($typed));
    }
}
