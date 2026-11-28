<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One step of the wizard: before joining (on the invitation page) or after
 * (Get started, and Help). The text is Markdown written by an admin; raw
 * HTML is escaped and unsafe links are dropped.
 *
 * @property int $id
 * @property int|null $media_server_id
 * @property int|null $wizard_bundle_id
 * @property string $phase before|after
 * @property string $title
 * @property string|null $body
 * @property string|null $button_label
 * @property string|null $button_url
 * @property int $position
 */
class WizardStep extends Model
{
    public const PHASES = ['before', 'after'];

    protected $fillable = ['media_server_id', 'wizard_bundle_id', 'phase', 'title', 'body', 'button_label', 'button_url', 'position'];

    /** @return BelongsTo<MediaServer, $this> */
    public function mediaServer(): BelongsTo
    {
        return $this->belongsTo(MediaServer::class);
    }

    /** @return BelongsTo<WizardBundle, $this> */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(WizardBundle::class, 'wizard_bundle_id');
    }

    public function html(): HtmlString
    {
        return new HtmlString(Str::markdown((string) $this->body, ['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]));
    }

    /** The button's address, only when it is http(s). */
    public function safeButtonUrl(): ?string
    {
        $url = trim((string) $this->button_url);

        return preg_match('~^https?://[^\s<>"]+$~i', $url) === 1 ? $url : null;
    }
}
