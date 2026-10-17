<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffUserTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidUsernames(): array
    {
        return [
            'only spaces' => ['     '],
            'empty' => [''],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 51)],
            'inner space' => ['two words'],
            'unsafe character' => ['bad/name'],
        ];
    }

    #[DataProvider('invalidUsernames')]
    public function test_database_rejects_invalid_usernames(string $username): void
    {
        $this->expectException(QueryException::class);

        StaffUserFactory::new()->create(['username' => $username]);
    }

    public function test_username_is_trimmed_and_lowercased(): void
    {
        $user = StaffUserFactory::new()->create(['username' => '  Sara.Ahmad  ']);

        $this->assertSame('sara.ahmad', $user->username);
    }
}
