<?php

it('redirects the root url to the login page', function (): void {
    $this->get('/')->assertRedirect('/login');
});
