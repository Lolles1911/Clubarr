<?php

namespace App\Jobs;

use App\Services\AdminAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Posts one alert to the admins' Discord webhook. The webhook address is read when sent, never queued. */
class SendAdminAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $text) {}

    public function handle(): void
    {
        $url = AdminAlerts::webhook();
        if ($url === null) {
            return;
        }
        try {
            Http::timeout(10)->withoutRedirecting()->post($url, [
                'content' => mb_substr($this->text, 0, 1900),
                'allowed_mentions' => ['parse' => []], // a name can't ping @everyone
            ])->throw();
        } catch (Throwable $e) {
            // Without the address: it holds the webhook's secret.
            Log::warning('Clubarr: admin alert not sent ('.class_basename($e).').');

            throw new \RuntimeException('Admin alert not sent.');
        }
    }
}
