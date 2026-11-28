<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Apply;
use App\Filament\App\Pages\ClaimAccount;
use App\Filament\App\Pages\Help;
use App\Filament\App\Pages\InviteFriend;
use App\Filament\App\Pages\Join;
use App\Filament\App\Pages\JoinWaitlist;
use App\Filament\App\Pages\MyAccess;
use App\Filament\App\Pages\MyStats;
use App\Filament\App\Pages\Setup;
use App\Filament\Pages\Auth\Profile;
use App\Http\Controllers\DiscordLinkController;
use App\Http\Controllers\MediaImageController;
use App\Http\Controllers\PlexReturnController;
use App\Http\Controllers\SingleSignOnController;
use App\Http\Middleware\ShowLandingPage;
use App\Models\User;
use App\Services\Applications;
use App\Services\Branding;
use App\Services\MemberInvites;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

/**
 * The members' side, at the site's root: sign-in, My access, profile (with
 * optional two-factor sign-in), plus the public join and setup pages. There
 * is no open registration: accounts are made by redeeming an invitation.
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->default()
            ->id('app')
            // On the sign-in page: the way to apply, when it is open.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => new HtmlString(
                (Applications::enabled() ? '<p style="text-align:center;font-size:.875rem;margin-top:1rem;opacity:.8">'.e(__('No account yet?')).' <a href="'.e(route('filament.app.apply')).'" style="text-decoration:underline">'.e(__('Apply to join')).'</a></p>' : '')
                // The password for watching is not the one for this page; say where to change it.
                .'<details style="margin-top:1rem;font-size:.85rem;opacity:.85"><summary style="cursor:pointer;text-align:center">'.e(__('Forgot your password for watching?')).'</summary>'
                .'<p style="margin-top:.5rem;line-height:1.5">'.e(__('Sign in here first, then press "New password" by the server on your page. Forgot this password too? Use "Forgot password?" above.')).'</p></details>'))
            ->path('')
            // The public front page at "/", for visitors, when it is on.
            ->middleware([ShowLandingPage::class])
            ->profile(Profile::class, isSimple: false)
            // A new email is only used once a link mailed to it is opened (when mail is on; see Profile).
            ->emailChangeVerification()
            // My page (the profile) in the menu too, not only behind the avatar.
            ->navigationItems([
                NavigationItem::make('my-page')->label(fn () => __('My page'))->icon('heroicon-o-user-circle')->sort(90)
                    ->url(fn () => Filament::getPanel('app')->getProfileUrl())
                    ->isActiveWhen(fn () => request()->routeIs('filament.app.auth.profile')),
            ])
            ->userMenuItems([
                'profile' => fn (Action $action) => $action->label(__('My page')),
                'stats' => Action::make('stats')->label(fn () => __('My statistics'))->icon('heroicon-o-chart-bar')->url(fn () => MyStats::getUrl(panel: 'app'))
                    ->visible(fn () => MyStats::canAccess()),
                'invite' => Action::make('invite')->label(fn () => __('Invite a friend'))->icon('heroicon-o-user-plus')->url(fn () => InviteFriend::getUrl(panel: 'app'))
                    ->visible(fn () => ($u = auth()->user()) instanceof User && MemberInvites::allowed($u)),
                'help' => Action::make('help')->label(fn () => __('Help'))->icon('heroicon-o-lifebuoy')->url(fn () => Help::getUrl(panel: 'app')),
                'admin' => Action::make('admin')->label(fn () => __('Admin'))->icon('heroicon-o-shield-check')->url(fn () => url('/admin'))
                    ->visible(fn () => (bool) auth()->user()?->is_admin),
            ])
            ->pages([MyAccess::class])
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->routes(function () {
                Route::get('/join/{code?}', Join::class)->name('join')->where('code', '[A-Za-z0-9\-]{1,80}');
                Route::get('/setup', Setup::class)->name('setup');
                Route::get('/waitlist', JoinWaitlist::class)->name('waitlist');
                Route::get('/apply', Apply::class)->name('apply');
                Route::get('/sso/redirect', [SingleSignOnController::class, 'redirect'])->name('sso.redirect');
                Route::get('/sso/callback', [SingleSignOnController::class, 'callback'])->name('sso.callback');
                Route::get('/plex/return', PlexReturnController::class)->name('plex.return');
                Route::get('/join-posters/{key}', [MediaImageController::class, 'joinPoster'])->name('join-poster');
                Route::get('/brand/logo', function () {
                    $logo = Branding::logo();
                    abort_if($logo === null, 404);

                    return response($logo['body'], 200, ['Content-Type' => $logo['type'], 'Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff']);
                })->name('logo');
                Route::get('/claim/{code}', ClaimAccount::class)->name('claim')->where('code', '[A-Za-z0-9\-]{1,80}');
            })
            ->authenticatedRoutes(function () {
                Route::get('/posters/{key}', [MediaImageController::class, 'poster'])->name('poster');
                Route::get('/posters/s/{key}', [MediaImageController::class, 'serverPoster'])->name('server-poster');
                Route::get('/tmdb/{file}', [MediaImageController::class, 'tmdb'])->name('tmdb');
                Route::get('/discord/connect', [DiscordLinkController::class, 'connect'])->name('discord.connect');
                Route::get('/discord/callback', [DiscordLinkController::class, 'callback'])->name('discord.callback');
                Route::post('/discord/disconnect', [DiscordLinkController::class, 'disconnect'])->name('discord.disconnect');
            })
            ->topNavigation();
    }
}
