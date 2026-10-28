<?php

declare(strict_types=1);

/*
| Regras de arquitetura do BISCA (ver docs/architecture.md).
*/

arch('não há chamadas de debug esquecidas')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('todo o código da app declara strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('controllers são finos: sem acesso direto à base de dados')
    ->expect('App\Modules\*\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB']);

arch('Form Requests estendem FormRequest')
    ->expect('App\Modules\*\Http\Requests')
    ->toExtend('Illuminate\Foundation\Http\FormRequest');

arch('API Resources estendem JsonResource')
    ->expect('App\Modules\*\Http\Resources')
    ->toExtend('Illuminate\Http\Resources\Json\JsonResource');
