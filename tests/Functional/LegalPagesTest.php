<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LegalPagesTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'mentions' => ['/mentions-legales', 'Mentions légales'];
        yield 'privacy' => ['/confidentialite', 'Politique de confidentialité'];
        yield 'terms' => ['/conditions-utilisation', "Conditions d'utilisation"];
    }

    #[DataProvider('pages')]
    public function testLegalPagesAreReadableWithoutSigningIn(string $path, string $title): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $title);
        self::assertSelectorExists('.legal-links');
    }

    public function testNoticeNamesPublisherAndHost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mentions-legales');

        self::assertSelectorTextContains('body', 'CYLAOS ICT');
        self::assertSelectorTextContains('body', '814 299 590');
        self::assertSelectorTextContains('body', 'Infomaniak');
        self::assertSelectorTextContains('body', 'support@cylaos.com');
    }

    public function testLoginPageLinksToTheLegalPages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('a[href="/mentions-legales"]'));
        self::assertCount(1, $crawler->filter('a[href="/confidentialite"]'));
    }
}
