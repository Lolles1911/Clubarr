<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

/** Uses the mail settings from the admin (Settings → Mail) instead of the .env fallback. */
class SiteMail
{
    public function apply(): void
    {
        $s = app(SiteSettings::class);
        if ($s->get('mail_enabled') !== '1' || blank($s->get('mail_host'))) {
            return;
        }
        $encryption = $s->get('mail_encryption');
        config([
            'mail.default' => 'clubarr',
            'mail.mailers.clubarr' => [
                'transport' => 'smtp',
                'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => $s->get('mail_host'),
                'port' => (int) $s->get('mail_port'),
                'username' => $s->get('mail_username'),
                'password' => $s->get('mail_password'),
                'timeout' => 15,
                'require_tls' => $encryption === 'tls',
            ],
            'mail.from.address' => $s->get('mail_from_address'),
            'mail.from.name' => $s->get('mail_from_name') ?: $s->get('site_name'),
        ]);
        Mail::purge('clubarr');
    }
}
