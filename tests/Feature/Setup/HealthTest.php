<?php

it('serves the health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('has no web routes (API only)', function () {
    $this->get('/')->assertNotFound();
});

it('never runs against the development database', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.connections.pgsql.database'))->toBe('ticketing_test');
});
