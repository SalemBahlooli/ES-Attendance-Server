<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The public shape of a user in the API. Never expose secrets here. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'is_active' => (bool) $this->is_active,
            'two_factor' => $this->hasTwoFactor(),
            'avatar_url' => $this->avatarUrl(),
            'groups' => $this->whenLoaded('groups', fn () => $this->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
