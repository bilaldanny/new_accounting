<?php

test('guests cannot access the roles api', function () {
    $this->getJson('/api/roles')
        ->assertUnauthorized();
});

test('role web routes require authentication', function () {
    $this->get(route('role'))
        ->assertRedirect();

    $this->get(route('role.trash'))
        ->assertRedirect();
});
