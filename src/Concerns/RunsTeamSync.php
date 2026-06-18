<?php

namespace Platform\AssetManager\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared, risk-sensitive scaffold for the team-scoped Graph sync jobs (SyncIntuneDevicesJob,
 * SyncLicensesJob). Each job keeps its own fetch + entity mapping + reconcile key; only the
 * pieces that were duplicated verbatim live here.
 *
 * NOT shared: config.sync_status maintenance stays in the device job — a single connector field
 * can't represent two independent sync streams without clobbering/cross-wiring (3.0 decision).
 */
trait RunsTeamSync
{
    /**
     * Empty-key-set-safe reconcile delete. An empty $keptKeys means "keep everything" — e.g. an
     * empty-but-successful Graph page (HTTP 200, value:[]) must NEVER delete the whole team set,
     * because whereNotIn($keyColumn, []) would match every row. $baseQuery is a factory so count
     * and delete each run a fresh query (matching the previous inline code). Returns rows removed.
     */
    protected function reconcileDelete(\Closure $baseQuery, string $keyColumn, array $keptKeys): int
    {
        if (empty($keptKeys)) {
            return 0;
        }

        $removed = $baseQuery()->whereNotIn($keyColumn, $keptKeys)->count();
        $baseQuery()->whereNotIn($keyColumn, $keptKeys)->delete();

        return $removed;
    }

    /** Mark a sync-log row failed (shared shape). Callers add any job-specific cleanup (e.g. connector status). */
    protected function markSyncLogFailed(Model $log, Carbon $startedAt, string $message): void
    {
        $log->update([
            'status'        => 'error',
            'error_message' => $message,
            'duration_ms'   => (int) $startedAt->diffInMilliseconds(now()),
            'completed_at'  => now(),
        ]);
    }

    /**
     * Reset a tenant's stuck 'started' sync-log rows to 'error' — used by failed() when Laravel kills
     * the job (timeout/uncaught) and handle()'s try/catch never ran. Tenant-scoped (per connector),
     * damit ein hängender Sync nicht die Logs der anderen Tenants desselben Teams anfasst.
     * Caller wraps this in try/catch.
     */
    protected function failStuckSyncLogs(string $logClass, int $tenantId, string $message): void
    {
        $logClass::where('tenant_id', $tenantId)
            ->where('status', 'started')
            ->update([
                'status'        => 'error',
                'error_message' => $message,
                'completed_at'  => now(),
            ]);
    }
}
