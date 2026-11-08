<?php

namespace Tests\Unit;

use App\Support\SocialLink;
use PHPUnit\Framework\TestCase;

/** Same cases as the storefront's lib/sellerApplication/normalize.ts. */
class SocialLinkTest extends TestCase
{
    /** @dataProvider socialCases */
    public function test_social_links_are_turned_into_full_urls(string $network, ?string $typed, ?string $expected, bool $valid): void
    {
        $out = SocialLink::social($network, $typed);
        $this->assertSame($expected, $out);
        if ($out !== null) {
            $this->assertSame($valid, preg_match(SocialLink::PATTERN, $out) === 1);
        }
    }

    public static function socialCases(): array
    {
        return [
            'handle'              => ['instagram', 'flamingofashionsfax', 'https://instagram.com/flamingofashionsfax', true],
            '@handle'             => ['instagram', '@flamingofashionsfax', 'https://instagram.com/flamingofashionsfax', true],
            'full link kept'      => ['instagram', 'https://www.instagram.com/flamingofashionsfax/', 'https://www.instagram.com/flamingofashionsfax/', true],
            'link without scheme' => ['instagram', 'instagram.com/flamingofashionsfax', 'https://instagram.com/flamingofashionsfax', true],
            'handle with dots'    => ['instagram', 'flamingo.fashion.sfax', 'https://instagram.com/flamingo.fashion.sfax', true],
            'facebook handle'     => ['facebook', 'flamingofashions', 'https://facebook.com/flamingofashions', true],
            'tiktok handle'       => ['tiktok', '@flamingo', 'https://tiktok.com/@flamingo', true],
            'spaces refused'      => ['instagram', 'Flamingo Fashion', 'Flamingo Fashion', false],
            'blank → null'        => ['instagram', '   ', null, true],
        ];
    }

    public function test_website_gets_https(): void
    {
        $this->assertSame('https://monsite.tn', SocialLink::website(' monsite.tn '));
        $this->assertSame('http://monsite.tn', SocialLink::website('http://monsite.tn'));
        $this->assertNull(SocialLink::website(''));
        $this->assertSame(0, preg_match(SocialLink::PATTERN, SocialLink::website('monsite')));
    }
}
