<?php

test('guests cannot access the departments api', function () {
    $this->getJson('/api/departments')
        ->assertUnauthorized();
});

test('department web routes require authentication', function () {
    $this->get(route('department'))
        ->assertRedirect();

    $this->get(route('department.trash'))
        ->assertRedirect();
});
