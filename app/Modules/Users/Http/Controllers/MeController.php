<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;

/**
 * @tags Utilizador
 */
class MeController extends Controller
{
    /**
     * Utilizador autenticado.
     */
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
