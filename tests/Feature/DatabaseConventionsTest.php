<?php

namespace Tests\Feature;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConventionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_session_runs_in_utc(): void
    {
        $this->assertSame('UTC', DB::selectOne("select current_setting('TimeZone') as tz")->tz);
    }

    public function test_stored_timestamps_agree_with_the_database_clock(): void
    {
        StaffUserFactory::new()->create();

        $diff = DB::selectOne('select abs(extract(epoch from (now() - created_at))) as diff from staff_users')->diff;

        // A time-zone mix-up would show up as thousands of seconds, not a few.
        $this->assertLessThan(60, (float) $diff);
    }
}
