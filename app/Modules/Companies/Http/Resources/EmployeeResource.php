<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Resources;

use App\Modules\Companies\Models\CompanyEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyEmployee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'status' => $this->status,
            'has_longa_account' => $this->user_id !== null,
        ];
    }
}
