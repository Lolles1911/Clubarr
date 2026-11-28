<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TranslationsTest extends TestCase
{
    /** @return array<string, true> every __('...') text in app/ and resources/views */
    private static function texts(): array
    {
        $texts = [];
        foreach (['app', 'resources/views'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$dir)) as $file) {
                if (str_ends_with((string) $file, '.php') && preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents((string) $file), $m)) {
                    foreach ($m[1] as $text) {
                        $texts[stripslashes($text)] = true;
                    }
                }
            }
        }

        return $texts;
    }

    /**
     * New texts don't need a Danish line (they show in English until someone
     * adds one), but the lines that are there must still fit their text.
     */
    public function test_danish_translations_keep_their_placeholders(): void
    {
        $danish = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/da.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty(self::texts());
        foreach ($danish as $english => $translation) {
            preg_match_all('/:[a-z_]+/', $english, $a);
            preg_match_all('/:[a-z_]+/', $translation, $b);
            sort($a[0]);
            sort($b[0]);
            $this->assertSame($a[0], $b[0], 'placeholders differ: '.$english);
        }
    }
}
