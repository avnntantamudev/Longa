<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Concerns;

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Páginas e recursos do backoffice só para o admin (dinheiro, definições, relatórios): o operador não os vê.
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }
}
