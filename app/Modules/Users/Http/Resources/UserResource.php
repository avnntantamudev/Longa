<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dados do próprio utilizador autenticado (inclui o telefone).
 * Para mostrar um utilizador a terceiros usar um Resource público sem contactos.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->getRoleNames()->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            ...$this->photoUrls(),
            'status' => $this->status->value,
            'locale' => $this->locale,
            'roles' => $roles,
            'profiles' => [
                'client' => in_array(Role::Client->value, $roles, true),
                'provider' => in_array(Role::Provider->value, $roles, true),
                'company_rh' => in_array(Role::CompanyRh->value, $roles, true),
            ],
            'needs_onboarding' => $this->onboarded_at === null,
            'pin_set' => $this->pin_hash !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Sempre 200: o JsonResource responderia 201 para um utilizador criado no mesmo pedido (registo por OTP).
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }
}
