<?php

namespace App\Modules\Identity\Actions;

/** Input of LoginStaff: a plain data carrier (the DTO use we discussed). */
final readonly class StaffLoginData
{
    public function __construct(
        public string $deviceId,
        public string $challengeId,
        public string $signature,
        public string $username,
        public string $password,
        public string $tokenName = 'api',
    ) {
    }
}
