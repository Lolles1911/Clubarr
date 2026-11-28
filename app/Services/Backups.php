<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Backups of Clubarr's database (SQLite, the default).
 *
 * - Automatic: every night a copy in /config/backups; the newest 7 are kept.
 *   Same installation, so the same encryption key.
 * - Download: the database and the encryption key in one file, encrypted
 *   with a password the admin chooses (AES-256-GCM, key from PBKDF2-SHA256).
 *   The password is never stored; without it the file can't be read.
 *
 * Restoring puts a copy of the current database next to the backups first.
 * A backup from another installation has its secrets (server keys, 2FA
 * secrets, settings) decrypted with its own key and encrypted again with
 * this one, so no key has to be moved and nothing restarted.
 */
class Backups
{
    public const KEEP = 7;

    private const MAGIC = "CLUBARR-BACKUP-1\n";

    private const ITERATIONS = 600000;

    /** Encrypted columns: [table, column] (see the models' "encrypted" casts and SiteSettings::SECRET). */
    private const ENCRYPTED = [['media_servers', 'api_key'], ['server_accounts', 'claim_url'], ['users', 'app_authentication_secret'], ['users', 'app_authentication_recovery_codes']];

    private const REQUIRED_TABLES = ['users', 'settings', 'media_servers', 'invitations', 'migrations'];

    public static function supported(): bool
    {
        return DB::getDriverName() === 'sqlite' && self::databasePath() !== null;
    }

    public static function databasePath(): ?string
    {
        $path = (string) config('database.connections.sqlite.database');

        return $path !== '' && $path !== ':memory:' ? $path : null;
    }

    public static function directory(): string
    {
        $dir = (string) (config('clubarr.backup_path') ?: dirname((string) self::databasePath()).'/backups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** @return list<array{name: string, size: int, time: int}> the automatic backups, newest first */
    public static function list(): array
    {
        $files = glob(self::directory().'/clubarr-*.sqlite') ?: [];
        $rows = array_map(fn ($f) => ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)], $files);
        usort($rows, fn ($a, $b) => $b['time'] <=> $a['time']);

        return $rows;
    }

    /** A consistent copy of the database, made while it is in use. */
    public function snapshot(string $to): void
    {
        $this->assertSupported();
        @unlink($to);
        DB::statement('VACUUM INTO ?', [$to]);
        @chmod($to, 0600);
    }

    /** The nightly copy; older ones beyond KEEP are removed. Returns its file name. */
    public function automatic(): string
    {
        $name = 'clubarr-'.now()->format('Y-m-d-His').'.sqlite';
        $this->snapshot(self::directory().'/'.$name);
        foreach (array_slice(self::list(), self::KEEP) as $old) {
            @unlink(self::directory().'/'.$old['name']);
        }

        return $name;
    }

    /** The download: database and key, encrypted with the password. */
    public function export(string $password): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clubarr');
        try {
            $this->snapshot($tmp);
            $payload = json_encode([
                'format' => 1,
                'version' => config('clubarr.version'),
                'created_at' => now()->toIso8601String(),
                'key' => base64_encode($this->currentKey()),
                'database' => base64_encode((string) file_get_contents($tmp)),
            ], JSON_THROW_ON_ERROR);
        } finally {
            @unlink($tmp);
        }
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $cipher = openssl_encrypt((string) gzencode($payload, 6), 'aes-256-gcm', $this->derive($password, $salt), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::MAGIC.base64_encode($salt.$iv.$tag.$cipher);
    }

    /** Restores a downloaded backup file. */
    public function restoreFile(string $contents, string $password): void
    {
        $this->assertSupported();
        if (! str_starts_with($contents, self::MAGIC) || ($raw = base64_decode(substr($contents, strlen(self::MAGIC)), true)) === false || strlen($raw) < 45) {
            throw new RuntimeException(__('This is not a Clubarr backup file.'));
        }
        [$salt, $iv, $tag, $cipher] = [substr($raw, 0, 16), substr($raw, 16, 12), substr($raw, 28, 16), substr($raw, 44)];
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->derive($password, $salt), OPENSSL_RAW_DATA, $iv, $tag);
        $payload = $plain === false ? null : json_decode((string) @gzdecode($plain), true);
        if (! is_array($payload) || ($payload['format'] ?? null) !== 1) {
            throw new RuntimeException(__('Wrong password, or the file is damaged.'));
        }
        if (version_compare((string) ($payload['version'] ?? '0'), (string) config('clubarr.version'), '>')) {
            throw new RuntimeException(__('This backup is from a newer Clubarr (:version). Update Clubarr first.', ['version' => (string) $payload['version']]));
        }
        $tmp = self::directory().'/restore-'.bin2hex(random_bytes(4)).'.sqlite';
        file_put_contents($tmp, base64_decode((string) ($payload['database'] ?? ''), true) ?: '');
        $this->restoreDatabase($tmp, base64_decode((string) ($payload['key'] ?? ''), true) ?: null);
    }

    /** Restores one of the automatic backups (by its file name only). */
    public function restoreAutomatic(string $name): void
    {
        $this->assertSupported();
        $match = collect(self::list())->firstWhere('name', basename($name));
        if ($match === null) {
            throw new RuntimeException(__('That backup is not there.'));
        }
        $tmp = self::directory().'/restore-'.bin2hex(random_bytes(4)).'.sqlite';
        copy(self::directory().'/'.$match['name'], $tmp);
        $this->restoreDatabase($tmp, null);
    }

    /** Checks the copy, fits its secrets to this key, keeps the current database aside, then swaps them. */
    private function restoreDatabase(string $tmp, ?string $key): void
    {
        try {
            $pdo = $this->open($tmp);
            if ($key !== null && $key !== $this->currentKey()) {
                $this->reencrypt($pdo, new Encrypter($key, (string) config('app.cipher')));
            }
            $pdo->exec('DELETE FROM sessions'); // nobody stays signed in across a restore
            $pdo = null;
        } catch (Throwable $e) {
            @unlink($tmp);

            throw $e instanceof RuntimeException ? $e : new RuntimeException(__('This backup can\'t be used: :reason', ['reason' => class_basename($e)]));
        }
        $this->snapshot(self::directory().'/clubarr-before-restore-'.now()->format('Y-m-d-His').'.sqlite');
        DB::disconnect();
        if (! @rename($tmp, (string) self::databasePath())) {
            @unlink($tmp);

            throw new RuntimeException(__('The database could not be replaced. Nothing was changed.'));
        }
        @chmod((string) self::databasePath(), 0600);
        DB::reconnect();
        Artisan::call('migrate', ['--force' => true]); // an older backup gets this version's tables
        Cache::flush();
        app(SiteSettings::class)->forget();
    }

    private function open(string $file): PDO
    {
        if (file_get_contents($file, false, null, 0, 16) !== "SQLite format 3\0") {
            throw new RuntimeException(__('This is not a Clubarr backup file.'));
        }
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff(self::REQUIRED_TABLES, $tables) !== [] || $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException(__('This is not a Clubarr backup file.'));
        }

        return $pdo;
    }

    /** Decrypts every secret with the backup's key and encrypts it with this one; what can't be read is emptied. */
    private function reencrypt(PDO $pdo, Encrypter $from): void
    {
        $lost = 0;
        $redo = function (?string $value) use ($from, &$lost): ?string {
            if ($value === null || $value === '') {
                return $value;
            }
            try {
                return Crypt::encryptString($from->decryptString($value));
            } catch (Throwable) {
                $lost++;

                return null;
            }
        };
        foreach (self::ENCRYPTED as [$table, $column]) {
            foreach ($pdo->query("SELECT id, {$column} AS v FROM {$table} WHERE {$column} IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pdo->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ?")->execute([$redo($row['v']), $row['id']]);
            }
        }
        $secret = implode(',', array_fill(0, count(SiteSettings::SECRET), '?'));
        $select = $pdo->prepare("SELECT key, value FROM settings WHERE key IN ({$secret}) AND value IS NOT NULL AND value != ''");
        $select->execute(SiteSettings::SECRET);
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$redo($row['value']), $row['key']]);
        }
        if ($lost > 0) {
            Log::warning('Clubarr: restore: '.$lost.' secret(s) could not be read and were emptied.');
        }
    }

    private function derive(string $password, string $salt): string
    {
        return hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 32, true);
    }

    private function currentKey(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }

    private function assertSupported(): void
    {
        if (! self::supported()) {
            throw new RuntimeException(__('Backups here are for the built-in SQLite database. Back up your database with its own tools.'));
        }
    }
}
