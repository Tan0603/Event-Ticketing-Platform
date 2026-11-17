<?php

namespace App\Actions\Tenancy;

/** Input to tenant registration. The password is already hashed: plaintext never leaves the request. */
final readonly class RegisterTenantData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $currency,
        public string $timezone,
        public int $feePercentBps,
        public int $feeFixedMinor,
        public string $adminName,
        public string $adminEmail,
        public string $passwordHash,
    ) {}
}
