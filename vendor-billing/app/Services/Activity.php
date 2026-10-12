<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** The permanent record of who did what (the database refuses changes and deletions). */
final class Activity
{
    /** @param  array<string, mixed>  $details */
    public function log(string $action, ?Model $subject = null, array $details = [], ?int $userId = null): void
    {
        ActivityLog::query()->create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject === null ? null : strtolower(class_basename($subject)),
            'subject_id' => $subject?->getKey(),
            'details' => $details === [] ? null : $details,
        ]);
    }
}
