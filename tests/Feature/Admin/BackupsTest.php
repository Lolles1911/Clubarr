<?php

namespace Tests\Feature\Admin;

use App\Enums\ServerType;
use App\Models\MediaServer;
use App\Models\User;
use App\Services\Backups;
use App\Services\SiteSettings;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** Backups need a database file, so these tests use one in a temporary folder. */
class BackupsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/clubarr-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        touch($this->dir.'/clubarr.sqlite');
        config(['database.connections.sqlite.database' => $this->dir.'/clubarr.sqlite', 'clubarr.backup_path' => $this->dir.'/backups']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function useKey(string $raw): void
    {
        config(['app.key' => 'base64:'.base64_encode($raw)]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    public function test_a_downloaded_backup_needs_its_password_and_moves_to_another_key(): void
    {
        $this->useKey(str_repeat('a', 32));
        $server = MediaServer::create(['type' => ServerType::Jellyfin, 'name' => 'Home', 'url' => 'http://jf.local', 'api_key' => 'server-key-123']);
        app(SiteSettings::class)->set(['seerr_api_key' => 'seerr-secret', 'site_name' => 'Waffles']);
        User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'x']);

        $file = app(Backups::class)->export('correct horse battery');
        $this->assertStringNotContainsString('server-key-123', $file);
        $this->assertStringNotContainsString('Anna', $file);

        // Another installation: other key, other data.
        $this->useKey(str_repeat('b', 32));
        MediaServer::query()->delete();
        app(SiteSettings::class)->set(['site_name' => 'Other']);

        try {
            app(Backups::class)->restoreFile($file, 'wrong password!');
            $this->fail('A wrong password must not restore.');
        } catch (RuntimeException $e) {
            $this->assertSame('Wrong password, or the file is damaged.', $e->getMessage());
        }
        $this->assertSame('Other', app(SiteSettings::class)->get('site_name'));

        app(Backups::class)->restoreFile($file, 'correct horse battery');

        $this->assertSame('Waffles', app(SiteSettings::class)->get('site_name'));
        $this->assertSame('server-key-123', MediaServer::sole()->api_key); // readable with this key
        $this->assertSame('seerr-secret', app(SiteSettings::class)->get('seerr_api_key'));
        $this->assertTrue(User::where('email', 'anna@example.com')->exists());
        $this->assertNotEmpty(glob($this->dir.'/backups/clubarr-before-restore-*.sqlite'));
        $this->assertNotEquals($server->getRawOriginal('api_key'), DB::table('media_servers')->value('api_key'));
    }

    public function test_automatic_backups_keep_the_newest_seven_and_restore_by_name_only(): void
    {
        foreach (range(1, 9) as $i) {
            $name = app(Backups::class)->automatic();
            touch($this->dir.'/backups/'.$name, time() - (100 - $i));
            $this->travel(1)->seconds();
        }
        $this->assertCount(Backups::KEEP, Backups::list());

        $this->expectException(RuntimeException::class);
        app(Backups::class)->restoreAutomatic('../clubarr.sqlite');
    }

    public function test_a_file_that_is_not_a_backup_is_refused_and_nothing_changes(): void
    {
        app(SiteSettings::class)->set(['site_name' => 'Kept']);
        foreach (['hello', "CLUBARR-BACKUP-1\n".base64_encode(random_bytes(80))] as $junk) {
            try {
                app(Backups::class)->restoreFile($junk, 'whatever-password');
                $this->fail('Junk must not restore.');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame('Kept', app(SiteSettings::class)->get('site_name'));
        $this->assertInstanceOf(Encrypter::class, app('encrypter'));
    }
}
