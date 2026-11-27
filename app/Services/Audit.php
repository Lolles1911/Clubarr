<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Writes the audit log. Never throws: a failed log line must not stop the action. */
class Audit
{
    /** @param array<string, mixed> $meta never secrets */
    public static function log(string $action, ?Model $subject = null, array $meta = [], ?int $actorId = null): void
    {
        try {
            AuditLog::create([
                'actor_id' => $actorId ?? Auth::id(),
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'ip' => app()->runningInConsole() ? null : request()->ip(),
                'meta' => $meta ?: null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
