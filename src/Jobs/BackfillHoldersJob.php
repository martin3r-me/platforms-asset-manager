<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Services\HolderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackfillHoldersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(
        public readonly int $teamId
    ) {}

    public function handle(HolderService $service): void
    {
        $created = $service->backfillForTeam($this->teamId);
        Log::info('AssetManager: Traeger-Backfill', [
            'team_id' => $this->teamId,
            'created' => $created,
        ]);
    }
}
