<?php

declare(strict_types=1);

namespace App\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every language must define every key, with valid ICU syntax and the same
 * placeholders as English.
 */
final class TranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'nl', 'es', 'de'];
    private const DIR = __DIR__.'/../../translations';

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function catalogueProvider(): iterable
    {
        foreach (['messages', 'cli'] as $domain) {
            foreach (self::LOCALES as $locale) {
                yield $domain.' '.$locale => [$domain, $locale];
            }
        }
    }

    #[DataProvider('catalogueProvider')]
    public function testMessagesMatchEnglish(string $domain, string $locale): void
    {
        $english = $this->load(\sprintf('%s+intl-icu.en.yaml', $domain));
        $messages = $this->load(\sprintf('%s+intl-icu.%s.yaml', $domain, $locale));

        self::assertSame(array_keys($english), array_keys($messages), 'Same keys as English');

        foreach ($messages as $key => $message) {
            try {
                new \MessageFormatter($locale, $message);
            } catch (\IntlException $e) {
                self::fail(\sprintf('[%s] %s is not valid ICU (%s): %s', $locale, $key, $e->getMessage(), $message));
            }
            self::assertSame($this->placeholders($english[$key]), $this->placeholders($message), \sprintf('[%s] %s placeholders', $locale, $key));
        }
    }

    #[DataProvider('localeProvider')]
    public function testEveryFilterReasonIsTranslated(string $locale): void
    {
        if ('en' === $locale) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $reasons = $this->load(\sprintf('filter.%s.yaml', $locale));
        $source = (string) file_get_contents(__DIR__.'/../../src/Lottery/PopularityFilter.php');
        preg_match_all("/\\\$reasons\\[\\] = (?:\\\\sprintf\\()?'([^']+)'/", $source, $m);
        $ids = array_map(static fn (string $id): string => str_replace('%d', '31', $id), $m[1]);

        self::assertCount(6, $ids);
        foreach ($ids as $id) {
            self::assertArrayHasKey($id, $reasons, \sprintf('[%s] missing filter reason "%s"', $locale, $id));
        }
    }

    /**
     * @return array<string, string>
     */
    private function load(string $file): array
    {
        $data = Yaml::parseFile(self::DIR.'/'.$file);
        self::assertIsArray($data);

        $messages = [];
        foreach ($data as $key => $value) {
            self::assertIsString($value);
            $messages[(string) $key] = $value;
        }
        ksort($messages);

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $message): array
    {
        // {name} or {name, plural, …}, but not plural branches such as "one {star}".
        preg_match_all('/(?<!one |other |zero |two |few |many |=0 |=1 |=2 )\{(\w+)[,}]/', $message, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }
}
