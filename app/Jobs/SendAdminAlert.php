<?php

namespace App\Jobs;

use App\Services\AlertChannels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Sends one alert to one channel. Addresses and tokens are read when sent, never queued. */
class SendAdminAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $text, public string $channel = 'discord') {}

    public function handle(): void
    {
        if (! in_array($this->channel, AlertChannels::configured(), true)) {
            return;
        }
        try {
            AlertChannels::send($this->channel, $this->text);
        } catch (RuntimeException $e) {
            Log::warning('Clubarr: admin alert not sent: '.$e->getMessage());

            throw $e;
        }
    }
}
