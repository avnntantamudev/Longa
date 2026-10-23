<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Resources;

use App\Modules\Companies\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nif' => $this->nif,
            'sector' => $this->sector,
            'municipality' => $this->municipality,
            'neighborhood' => $this->neighborhood,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status,
            'employees_count' => $this->whenCounted('employees'),
        ];
    }
}
