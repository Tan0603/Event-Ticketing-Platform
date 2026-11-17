<?php

namespace App\Actions\Auth;

use App\Exceptions\InvalidCredentialsException;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class LoginStaff
{
    /** The ability that binds a token to the tenant that issued it (defence in depth). */
    public static function tenantAbility(string $tenantId): string
    {
        return 'tenant:'.$tenantId;
    }

    /** @throws InvalidCredentialsException */
    public function execute(string $email, string $password): NewAccessToken
    {
        $user = User::query()->where('email', strtolower($email))->first();

        // Always run one hash check so an unknown email costs the same as a wrong password.
        $valid = Hash::check($password, $user->password ?? Hash::make(bin2hex(random_bytes(8))));

        if ($user === null || ! $valid) {
            throw new InvalidCredentialsException;
        }

        return $user->createToken(
            'api',
            [self::tenantAbility((string) tenant('id'))],
            now()->addMinutes((int) config('sanctum.expiration')),
        );
    }
}
