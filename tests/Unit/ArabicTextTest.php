<?php

namespace Tests\Unit;

use App\Support\ArabicText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArabicTextTest extends TestCase
{
    public static function searchKeys(): array
    {
        return [
            'alef variants merge' => ['أحمد', 'احمد'],
            'harakat removed' => ['مُحَمَّد', 'محمد'],
            'ta marbuta and ha merge' => ['فاطمة', 'فاطمه'],
            'alef maqsura and ya merge' => ['عيسى', 'عيسي'],
            'whitespace collapses' => ["  أحمد   علي  ", 'احمد علي'],
            'latin lowercased' => ['Ahmad ALI', 'ahmad ali'],
        ];
    }

    #[DataProvider('searchKeys')]
    public function test_search_key(string $typed, string $expected): void
    {
        $this->assertSame($expected, ArabicText::searchKey($typed));
    }

    public function test_two_spellings_of_a_name_share_one_key(): void
    {
        $this->assertSame(ArabicText::searchKey('أحمد'), ArabicText::searchKey('احمد'));
        $this->assertSame(ArabicText::searchKey('فاطمة'), ArabicText::searchKey('فاطمه'));
    }

    public function test_digits_become_ascii(): void
    {
        $this->assertSame('0123456789', ArabicText::digitsToAscii('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('0123456789', ArabicText::digitsToAscii('۰۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('0912abc', ArabicText::digitsToAscii('٠٩١٢abc'));
    }
}
