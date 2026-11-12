<?php

namespace Tests\Feature;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Conventions every endpoint shares: the Arabic refusal (D-094) and filters that are applied when given (D-091). */
class RequestConventionsTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers;

    private const FORBIDDEN = 'ليست لديك صلاحية لهذا الإجراء.';

    public function test_a_refusal_without_its_own_message_says_so_in_arabic(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $nobody = StaffUserFactory::new()->create();   // signed in, with no permission

        // The can: middleware, and the middleware that takes any of several permissions (a bare abort(403)).
        $this->asStaff($nobody)->getJson('/api/v1/staff/interviews')->assertForbidden()->assertExactJson(['message' => self::FORBIDDEN])
            ->assertSee(self::FORBIDDEN, false);   // readable, not \u escapes (D-018)
        $this->asStaff($nobody)->getJson('/api/v1/staff/imports')->assertForbidden()->assertExactJson(['message' => self::FORBIDDEN]);

        Route::middleware('api')->prefix('api/test')->group(function () {
            Route::get('bare', fn() => abort(403));
            Route::get('policy', fn() => throw new AuthorizationException());
            Route::get('own', fn() => abort(403, 'خارج شبكة القاعة.'));
            Route::get('own-policy', fn() => throw new AuthorizationException('لا يصحح العلامة إلا صاحب الصلاحية.'));
        });

        $this->getJson('/api/test/bare')->assertForbidden()->assertExactJson(['message' => self::FORBIDDEN]);
        $this->getJson('/api/test/policy')->assertForbidden()->assertExactJson(['message' => self::FORBIDDEN]);
        // A refusal that says why keeps its message.
        $this->getJson('/api/test/own')->assertForbidden()->assertJsonPath('message', 'خارج شبكة القاعة.');
        $this->getJson('/api/test/own-policy')->assertForbidden()->assertJsonPath('message', 'لا يصحح العلامة إلا صاحب الصلاحية.');
    }

    public function test_a_request_without_a_valid_token_is_told_in_arabic(): void
    {
        $response = $this->getJson('/api/v1/staff/auth/me')->assertUnauthorized()->assertExactJson(['message' => 'انتهت الجلسة أو لم يُسجَّل الدخول: سجّل الدخول من جديد.']);
        $this->assertStringContainsString('انتهت الجلسة', $response->getContent());   // readable, not \u escapes (D-018)
        $this->withToken('1|not-a-token')->getJson('/api/v1/staff/interviews')->assertUnauthorized()->assertJsonPath('message', __('auth.unauthenticated'));
    }

    public function test_too_many_attempts_are_refused_in_arabic(): void
    {
        Route::middleware(['api', 'throttle:1,1'])->get('api/test/throttled', fn() => 'ok');

        $this->getJson('/api/test/throttled')->assertOk();
        $this->getJson('/api/test/throttled')->assertStatus(429)
            ->assertExactJson(['message' => 'محاولات كثيرة: انتظر قليلًا ثم أعد المحاولة.'])
            ->assertHeader('Retry-After')
            ->assertSee('محاولات كثيرة', false);   // readable, not \u escapes (D-018)
    }

    public function test_another_origin_reads_the_download_names(): void
    {
        $this->withHeaders(['Origin' => 'https://hr.example.local'])->getJson('/api/v1/ping')->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition, Retry-After');

        // Listed origins only, when the deployment names them.
        config(['cors.allowed_origins' => ['https://hr.example.local']]);
        $this->app->forgetInstance(\Fruitcake\Cors\CorsService::class);
        $this->withHeaders(['Origin' => 'https://hr.example.local'])->getJson('/api/v1/ping')->assertHeader('Access-Control-Allow-Origin', 'https://hr.example.local');
        // Another origin is not allowed: the answer names the listed one, which the browser does not match.
        $this->assertNotSame('https://elsewhere.example', $this->withHeaders(['Origin' => 'https://elsewhere.example'])->getJson('/api/v1/ping')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_filter_given_as_zero_is_applied(): void
    {
        $applied = function ($query, $value): array {
            $seen = [];
            $result = $query->whenGiven($value, function ($builder, $given) use (&$seen) {
                $seen[] = $given;
                $builder->where('id', $given);
            });
            $this->assertSame($query, $result);   // chainable, like when()

            return $seen;
        };

        // when() would skip the first three.
        foreach (['0', 0, false, '00', ' '] as $value) {
            $this->assertSame([$value], $applied(DB::table('districts'), $value), var_export($value, true));
        }

        foreach ([null, '', []] as $value) {
            $this->assertSame([], $applied(DB::table('districts'), $value), var_export($value, true));
        }

        // On Eloquent queries too.
        $eloquent = StaffUserFactory::new()->make()->newQuery();
        $this->assertInstanceOf(EloquentBuilder::class, $eloquent);
        $this->assertStringContainsString('"username" = ?', $eloquent->whenGiven('0', fn($query, $value) => $query->where('username', $value))->toSql());
        $this->assertSame(['0'], $eloquent->getBindings());
        $this->assertInstanceOf(QueryBuilder::class, DB::table('districts')->whenGiven('0', fn($query) => null));   // a callback returning nothing keeps the query
    }
}
