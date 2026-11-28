<?php

namespace App\Services;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The words of the member mails, as the admin wrote them (Mail texts), or
 * Clubarr's own when left empty. Plain text: paragraphs split by an empty
 * line, and {name}, {site} and {date} filled in.
 */
final class MailTexts
{
    /** @return array<string, array{label: string, vars: list<string>}> */
    public static function mails(): array
    {
        return [
            'welcome' => ['label' => __('Welcome (a new member)'), 'vars' => ['name', 'site']],
            'ending_soon' => ['label' => __('Access ends soon'), 'vars' => ['name', 'site', 'date']],
            'ended' => ['label' => __('Access has ended'), 'vars' => ['name', 'site']],
        ];
    }

    /** @return list<string> the settings keys */
    public static function keys(): array
    {
        return collect(array_keys(self::mails()))->flatMap(fn (string $mail) => ['mail_text_'.$mail.'_subject', 'mail_text_'.$mail.'_body'])->all();
    }

    /**
     * Sets the subject and the paragraphs on the mail, from the admin's text or the default.
     *
     * @param  array<string, string>  $vars
     * @param  list<string>  $defaultLines
     */
    public static function apply(MailMessage $mail, string $key, array $vars, string $defaultSubject, array $defaultLines): MailMessage
    {
        $settings = app(SiteSettings::class);
        $fill = fn (string $text) => strtr($text, collect($vars)->mapWithKeys(fn ($value, $name) => ['{'.$name.'}' => (string) $value])->all());
        $subject = trim((string) $settings->get('mail_text_'.$key.'_subject'));
        $body = trim((string) $settings->get('mail_text_'.$key.'_body'));
        $mail->subject($subject !== '' ? mb_substr($fill($subject), 0, 200) : $defaultSubject);
        $lines = $body !== '' ? array_map($fill, preg_split('/\R\s*\R/', $body) ?: []) : $defaultLines;
        foreach ($lines as $line) {
            $mail->line(trim($line));
        }

        return $mail;
    }
}
