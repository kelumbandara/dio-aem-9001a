<?php

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'user_type' => $this->user_type,
            'locale' => $this->locale,
            'mfa_enabled' => $this->hasMfaEnabled(),
            'mfa_enrollment_required' => $this->requiresMfa() && ! $this->hasMfaEnabled(),
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
