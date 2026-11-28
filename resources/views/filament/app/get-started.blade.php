<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:.35rem" @if ($this->only !== null) data-clubarr-celebrate="joined" @endif>
        <p style="font-size:1.05rem">{{ __('Welcome! You are in. Here is how to start watching.') }}</p>
    </div>

    @foreach ($this->steps() as $step)
        <x-filament::section :heading="$step->title" :description="$step->mediaServer?->name">
            @include('filament.app.wizard-step', ['step' => $step])
        </x-filament::section>
    @endforeach

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,22rem),1fr));gap:1rem">
        @foreach ($this->accounts() as $account)
            @php($server = $account->mediaServer)
            <x-filament::section>
                <x-slot name="heading">{{ $server->name }}</x-slot>
                <x-slot name="description">{{ $server->type->label() }}</x-slot>
                <ol style="display:flex;flex-direction:column;gap:.9rem;font-size:.92rem;line-height:1.5;margin:0;padding-left:1.1rem;list-style:decimal">
                    @if ($account->status === \App\Enums\AccountStatus::Pending && $account->claim_url)
                        <li>{{ __('Choose your user name and password on :server\'s own page:', ['server' => $server->name]) }}
                            <div style="margin-top:.4rem"><x-filament::button tag="a" size="sm" :href="$account->claim_url" target="_blank" rel="noopener noreferrer">{{ __('Finish on :server', ['server' => $server->name]) }}</x-filament::button></div></li>
                    @elseif ($account->status === \App\Enums\AccountStatus::Pending)
                        <li>{{ __('Accept the invitation in Plex (plex.tv or the mail from Plex). Then the server shows up in your Plex apps.') }}</li>
                    @endif
                    @if ($server->type->apps() !== [])
                        <li>{{ __('Get the :app app:', ['app' => $server->type->label()]) }}
                            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.4rem">
                                @foreach ($server->type->apps() as [$label, $url])
                                    <x-filament::button tag="a" size="sm" color="gray" :href="$url" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-down-tray">{{ $label }}</x-filament::button>
                                @endforeach
                            </div>
                            <div style="opacity:.75;margin-top:.35rem;font-size:.85rem">{{ __('On a TV: search for ":app" in the TV\'s app store.', ['app' => $server->type->label()]) }}</div>
                        </li>
                    @endif
                    <li>
                        @if ($server->type === \App\Enums\ServerType::Plex)
                            {{ __('Sign in to the app with your own Plex account. The server shows up by itself.') }}
                        @elseif ($account->remote_username)
                            {{ __('In the app, add the server :url and sign in as :user with the password you chose.', ['url' => $server->openUrl(), 'user' => $account->remote_username]) }}
                        @else
                            {{ __('In the app, add the server :url and sign in with the user you chose.', ['url' => $server->openUrl()]) }}
                        @endif
                    </li>
                    <li>{{ __('Or watch in the browser:') }}
                        <div style="margin-top:.4rem"><x-filament::button tag="a" size="sm" :href="$server->watchUrl()" target="_blank" rel="noopener noreferrer" icon="heroicon-m-play">{{ __('Open :server', ['server' => $server->name]) }}</x-filament::button></div></li>
                </ol>
            </x-filament::section>
        @endforeach
    </div>

    @if ($this->helpText())
        <x-filament::section :heading="__('Good to know')">
            <div style="font-size:.9rem;line-height:1.6">{!! nl2br(e($this->helpText())) !!}</div>
        </x-filament::section>
    @endif

    <div><x-filament::button tag="a" size="lg" :href="\App\Filament\App\Pages\MyAccess::getUrl(panel: 'app')" icon="heroicon-m-arrow-right">{{ __('To my page') }}</x-filament::button></div>
</x-filament-panels::page>
