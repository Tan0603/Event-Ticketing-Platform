<?php

it('serves the health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('has no web routes (API only)', function () {
    $this->get('/')->assertNotFound();
});
