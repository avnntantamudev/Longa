<?php

declare(strict_types=1);

namespace App\Support\Api;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * OpenAPI: as rotas com o middleware "idempotent" exigem o cabeçalho Idempotency-Key.
 */
class IdempotencyHeaderDocumentation implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array('idempotent', $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $schema = (new StringType)->pattern('^[A-Za-z0-9_-]{8,100}$');

        $operation->addParameters([
            Parameter::make('Idempotency-Key', 'header')
                ->required(true)
                ->setSchema(Schema::fromType($schema))
                ->description('Chave única por ação (8–100 caracteres: letras, números, "-" e "_"). Repetir o mesmo pedido com a mesma chave devolve a resposta guardada (24 h, cabeçalho `Idempotent-Replayed: true`); a mesma chave com outro corpo dá 422 `idempotency_key_reused`; um pedido ainda em curso dá 409 `request_in_progress`.')
                ->example('3f1c9a2e-7b4d-4e8a-9c1f-2a6b8d0e4f13'),
        ]);
    }
}
