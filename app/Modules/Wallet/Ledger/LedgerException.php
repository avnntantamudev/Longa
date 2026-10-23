<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Ledger;

use RuntimeException;

/**
 * Erro de integridade do livro-razão (transação desequilibrada, conflito de idempotência...).
 * Nunca é mostrado ao utilizador final como causa de negócio: indica um erro de programação ou um ataque.
 */
class LedgerException extends RuntimeException {}
