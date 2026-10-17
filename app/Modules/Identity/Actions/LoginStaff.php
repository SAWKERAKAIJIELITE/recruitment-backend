<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginStaff
{
    /**
     * Step 2b will add device and signature verification at the top of this method.
     *
     * @return array{token: string, user: StaffUser}
     *
     * @throws ValidationException one generic message for every failure reason
     */
    public function execute(string $username, string $password, string $deviceName = 'api'): array
    {
        $user = StaffUser::where('username', Str::lower(trim($username)))->first();

        if ($user === null) {
            Hash::make($password);   // burn comparable CPU time so "no such user" isn't faster
            $this->fail();
        }

        $passwordMatches = Hash::check($password, $user->password);

        if (!$passwordMatches || !$user->is_active) {
            $this->fail();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return [
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => $user,
        ];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'username' => [trans('auth.failed')],
        ]);
    }
}
