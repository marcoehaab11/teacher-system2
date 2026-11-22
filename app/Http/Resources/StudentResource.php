<?php

namespace App\Http\Resources;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $viewerIsStaff = $viewer instanceof User && $viewer->isStaff();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'relation' => $this->whenPivotLoaded('parent_student', fn () => $this->pivot->relation),
            'notes' => $this->when($viewerIsStaff, $this->notes),
            'registration_data' => $this->when(
                $viewerIsStaff && $this->isPending(),
                fn (): ?array => $this->registration_data === null ? null : Arr::except($this->registration_data, 'password_hash'),
            ),
            'account' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'phone' => $this->user->phone,
                'is_active' => $this->user->is_active,
            ] : null),
            'parents' => $this->whenLoaded('parents', fn () => $this->parents->map(fn (User $parent): array => [
                'id' => $parent->id,
                'name' => $parent->name,
                'phone' => $parent->phone,
                'relation' => $parent->pivot->relation,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
