<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Resources\CategoryResource;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CategoryTree;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Páginas Inertia de registo e perfil. Os formulários submetem à API /api/v1 (Sanctum SPA).
 */
class PageController extends Controller
{
    public function onboarding(): Response
    {
        return Inertia::render('Onboarding/Start');
    }

    public function providerOnboarding(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->providerProfile !== null, 404);

        return Inertia::render('Onboarding/Provider', [
            'categories' => CategoryResource::collection(app(CategoryTree::class)->leaves())->resolve(),
            'zones' => config('locations.zones'),
            'provinces' => config('locations.provinces'),
        ]);
    }

    public function companyOnboarding(): Response
    {
        return Inertia::render('Onboarding/Company');
    }

    public function profile(): Response
    {
        return Inertia::render('Profile/Show');
    }
}
