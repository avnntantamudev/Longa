<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Testes de Feature usam a aplicação completa e uma base de dados limpa por teste.
| Testes de Unit não arrancam o Laravel.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
