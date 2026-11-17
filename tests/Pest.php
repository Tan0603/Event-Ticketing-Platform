<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Central database is refreshed per test. Tenant databases are handled by the
// CreatesTenants helper (added with provisioning) and dropped after each test.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
