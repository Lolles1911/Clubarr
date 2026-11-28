<?php

namespace App\Filament\Admin\Resources\ApiKeys\Pages;

use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Audit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ManageApiKeys extends ManageRecords
{
    protected static string $resource = ApiKeyResource::class;

    /** A key just made: only on the page load right after (private: never in Livewire's state). */
    private ?string $newKey = null;

    public function mount(): void
    {
        parent::mount();
        $flashed = session('clubarr.api_key');
        $this->newKey = is_string($flashed) ? $flashed : null;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New key'))
            ->using(function (array $data) {
                $user = Auth::user();
                [$key, $token] = ApiKey::issue((string) $data['name'], (bool) ($data['can_invite'] ?? false), $user instanceof User ? $user : null);
                Audit::log('api.key_created', $key, ['name' => $key->name, 'can_invite' => $key->can_invite]);
                session()->flash('clubarr.api_key', $token);

                return $key;
            })
            ->successNotification(null)
            ->successRedirectUrl(fn () => ApiKeyResource::getUrl('index'))];
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->newKey === null) {
            return null;
        }

        return new HtmlString('<div style="margin-top:1rem;padding:1rem 1.1rem;border-radius:.75rem;border:1px solid color-mix(in oklab,var(--primary-500) 45%,transparent);background:color-mix(in oklab,var(--primary-500) 10%,transparent)">'
            .'<strong>'.e(__('Here is the new key')).'</strong>'
            .'<p style="font-size:.85rem;opacity:.8;margin:.4rem 0 .6rem">'.e(__('Copy it now: it is shown only this once. Send it as "Authorization: Bearer <key>".')).'</p>'
            .'<code style="display:block;user-select:all;word-break:break-all;padding:.5rem .7rem;border-radius:.5rem;background:rgba(0,0,0,.25)">'.e($this->newKey).'</code></div>');
    }
}
