<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Recruitment\Support\NominationNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class NominationNumberTest extends TestCase
{
    use RefreshDatabase;

    private NominationNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new NominationNumberGenerator();
        Carbon::setTestNow('2026-05-01 10:00:00');
    }

    public function test_default_format_is_the_year_followed_by_six_digits(): void
    {
        $this->assertSame('2026000001', $this->generator->next());
        $this->assertSame('2026000002', $this->generator->next());
    }

    public function test_the_counter_restarts_each_year(): void
    {
        $this->assertSame('2026000001', $this->generator->next());

        Carbon::setTestNow('2027-01-02 09:00:00');

        $this->assertSame('2027000001', $this->generator->next());
        $this->assertSame('2027000002', $this->generator->next());
    }

    public function test_the_format_is_configurable(): void
    {
        config(['recruitment.nomination_number.format' => 'N-{YY}-{SEQ}', 'recruitment.nomination_number.sequence_digits' => 4]);

        $this->assertSame('N-26-0001', $this->generator->next());
    }

    public function test_a_rolled_back_transaction_gives_its_number_back(): void
    {
        try {
            DB::transaction(function () {
                $this->generator->next();

                throw new RuntimeException('creating the application failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('2026000001', $this->generator->next());   // no gap
    }
}
