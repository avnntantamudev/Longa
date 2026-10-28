<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('mostra a página inicial com os três perfis', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('appName', 'BISCA')
            ->where('auth.user', null)
            ->has('profiles', 3)
            ->where('profiles.0.key', 'student')
            ->has('highlights', 3));
});

it('inclui o manifest da PWA e as meta tags mobile', function () {
    $this->get('/')
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('name="theme-color" content="#0B1F3A"', false)
        ->assertSee('viewport-fit=cover', false);
});
