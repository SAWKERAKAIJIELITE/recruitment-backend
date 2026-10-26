<?php

namespace Tests\Unit;

use App\Modules\Imports\Support\HeaderMatcher;
use Tests\TestCase;

/** Needs the framework for config/imports.php, but not the database. */
class HeaderMatcherTest extends TestCase
{
    public function test_spelling_variants_and_punctuation_are_ignored(): void
    {
        $this->assertSame(HeaderMatcher::normalize('الحالة الصحية/بالتفصيل'), HeaderMatcher::normalize('الحالة الصحيه - بالتفصيل'));
        $this->assertSame(HeaderMatcher::normalize('اسم الأم'), HeaderMatcher::normalize('اسم الام:'));
    }

    public function test_an_exact_header_beats_one_that_only_contains_an_alias(): void
    {
        // "التفرغ" is an alias of full_commitment; "التفرغ الكامل" matches it exactly and wins the field.
        $match = HeaderMatcher::match(['A' => 'التفرغ', 'B' => 'التفرغ الكامل']);

        $this->assertSame('B', $match['fields']['full_commitment']);
        $this->assertSame(['A'], $match['ignored']);
    }

    public function test_similar_headers_go_to_their_own_fields(): void
    {
        $match = HeaderMatcher::match([
            'K' => 'الحالة الاجتماعية',
            'L' => 'الحالة الصحية/بالتفصيل',
            'M' => 'محافظة الإقامة',
            'N' => 'مكان الإقامة الحالية',
            'S' => 'العمل لدى جهة حكومية أخرى',
            'B' => 'الجهة المرشحة',
        ]);

        $this->assertEquals(   // same pairs, any order
            ['marital_status' => 'K', 'health' => 'L', 'residence_district' => 'M', 'residence_place' => 'N', 'other_government_entity' => 'S', 'nominating_entity' => 'B'],
            array_intersect_key($match['fields'], array_flip(['marital_status', 'health', 'residence_district', 'residence_place', 'other_government_entity', 'nominating_entity'])),
        );
    }

    public function test_unknown_and_empty_headers(): void
    {
        $match = HeaderMatcher::match(['A' => 'م', 'B' => '   ', 'C' => 'رقم الاختبار']);

        $this->assertSame([], $match['fields']);
        $this->assertSame(['A', 'C'], $match['ignored']);
    }
}
