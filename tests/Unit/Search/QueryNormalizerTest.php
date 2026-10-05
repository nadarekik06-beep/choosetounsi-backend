<?php

namespace Tests\Unit\Search;

use App\Services\Search\QueryNormalizer;
use App\Services\Search\Synonyms;
use Tests\TestCase;

class QueryNormalizerTest extends TestCase
{
    /** @dataProvider cases */
    public function test_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, (new QueryNormalizer)->normalize($input));
    }

    public function cases(): array
    {
        return [
            'accents and case'        => ['Robe Soirée', 'robe soiree'],
            'ligature'                => ['Cœur', 'coeur'],
            'punctuation splits'      => ["T-Shirt, l'été!", 't shirt l ete'],
            'derja digits kept'       => ['3sal  9ahwa', '3sal 9ahwa'],
            'alef forms + ta marbuta' => ['أحذية', 'احذيه'],
            'alef maqsura'            => ['مستشفى', 'مستشفي'],
            'diacritics and tatweel'  => ['سَبّـــاط', 'سباط'],
            'article stripped'        => ['الهاتف', 'هاتف'],
            'short word keeps al'     => ['الف', 'الف'],
            'wal prefix'              => ['والعسل', 'عسل'],
            'arabic digits'           => ['٥٠٠ غرام', '500 غرام'],
            'html removed'            => ['<b>Casque</b> audio', 'casque audio'],
            'empty'                   => ['  ', ''],
        ];
    }

    public function test_synonym_file_is_normalized_and_multi_way(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'syn');
        file_put_contents($file, "# comment\nshoes, Sabbat, صبّاط\n\nhoney, Miel\nalone\n");
        config(['search.synonyms_path' => $file]);

        $groups = app(Synonyms::class)->groups();
        unlink($file);

        $this->assertSame([['shoes', 'sabbat', 'صباط'], ['honey', 'miel']], $groups, 'normalized, 1-word lines dropped');
    }

    public function test_shipped_synonym_file_parses(): void
    {
        $groups = app(Synonyms::class)->groups();
        $this->assertGreaterThan(50, count($groups));
        $this->assertContains('sabbat', array_merge(...$groups));
    }
}
