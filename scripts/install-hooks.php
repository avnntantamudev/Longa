<?php

declare(strict_types=1);

// Ativa os git hooks do projeto (.githooks). Corre automaticamente após "composer install".
// Silencioso fora de um repositório git (ex.: imagem de produção).

if (! is_dir(__DIR__.'/../.git')) {
    exit(0);
}

exec('git config core.hooksPath .githooks 2>&1', $output, $status);

echo $status === 0 ? "Git hooks ativados (.githooks).\n" : "Aviso: não foi possível ativar os git hooks.\n";
