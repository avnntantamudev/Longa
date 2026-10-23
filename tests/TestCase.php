<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\TestingSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Papéis e áreas de formação existem em todos os testes com base de dados. */
    protected string $seeder = TestingSeeder::class;
}
