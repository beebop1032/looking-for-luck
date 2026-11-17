<?php

declare(strict_types=1);

namespace App\Web;

/**
 * The responsible-gambling warning shown on every page and under every
 * command banner.
 *
 * Where a country prescribes a wording, it is quoted verbatim and never
 * translated; elsewhere a plain warning in the interface language is shown.
 * The country comes from LFL_COUNTRY when set, else from the region of the
 * user's language (fr-BE → Belgium, fr-FR → France).
 *
 * - FR: arrêté du 4 mars 2026 (message on the home page of online services).
 * - BE: arrêté royal du 27 février 2023 (default prevention message, age).
 */
final class GamblingNotice
{
    public const AGE = '18+';

    /**
     * Official wordings, keyed "country.language".
     */
    private const LEGAL = [
        'fr.fr' => [
            'text' => 'Les jeux d’argent et de hasard peuvent être dangereux : pertes d’argent, conflits familiaux, addiction…',
            'help' => null,
            'url' => null,
        ],
        'be.fr' => ['text' => 'Le jeu peut rendre dépendant. Arrêtez-vous à temps!', 'help' => null, 'url' => null],
        'be.nl' => ['text' => 'Gokken kan verslavend zijn. Stop op tijd!', 'help' => null, 'url' => null],
    ];

    /**
     * The language of the official wording when none matches the interface.
     */
    private const LEGAL_DEFAULT = ['fr' => 'fr', 'be' => 'fr'];

    private const GENERIC = [
        'en' => 'Gambling can be addictive and you can lose money. Set a budget, play for fun and stop in time.',
        'fr' => 'Les jeux d’argent peuvent rendre dépendant et faire perdre de l’argent. Fixez-vous un budget, jouez pour le plaisir et arrêtez-vous à temps.',
        'nl' => 'Gokken kan verslavend zijn en je kunt geld verliezen. Stel een budget in, speel voor je plezier en stop op tijd.',
        'es' => 'El juego puede crear adicción y hacerte perder dinero. Fija un presupuesto, juega por diversión y para a tiempo.',
        'de' => 'Glücksspiel kann süchtig machen und Geld kosten. Setz dir ein Budget, spiel zum Spaß und hör rechtzeitig auf.',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<string> $languages preferred languages, best first (e.g. fr_BE, fr)
     *
     * @return array{country: ?string, lang: string, text: string, help: ?string, url: ?string, age: string}
     */
    public static function for(string $locale, array $languages = [], ?string $country = null): array
    {
        $language = strtolower(substr($locale, 0, 2));
        $country = self::country($country, $languages);

        if (null !== $country && isset(self::LEGAL_DEFAULT[$country])) {
            $lang = isset(self::LEGAL[$country.'.'.$language]) ? $language : self::LEGAL_DEFAULT[$country];
            $legal = self::LEGAL[$country.'.'.$lang] ?? null;
            if (null !== $legal) {
                return ['country' => $country, 'lang' => $lang, 'text' => $legal['text'], 'help' => $legal['help'], 'url' => $legal['url'], 'age' => self::AGE];
            }
        }

        $lang = isset(self::GENERIC[$language]) ? $language : 'en';

        return [
            'country' => $country,
            'lang' => $lang,
            'text' => self::GENERIC[$lang],
            'help' => null,
            'url' => null,
            'age' => self::AGE,
        ];
    }

    /**
     * The notice for the command line: LFL_COUNTRY, else the region of
     * LFL_LOCALE, LC_ALL, LC_MESSAGES or LANG (e.g. fr_BE.UTF-8).
     *
     * @return array{country: ?string, lang: string, text: string, help: ?string, url: ?string, age: string}
     */
    public static function forConsole(string $locale): array
    {
        $languages = [];
        foreach (['LFL_LOCALE', 'LC_ALL', 'LC_MESSAGES', 'LANG'] as $name) {
            $value = self::env($name);
            if (null !== $value) {
                $languages[] = $value;
            }
        }

        return self::for($locale, $languages, self::env('LFL_COUNTRY'));
    }

    /**
     * An environment variable, including those loaded from .env files (which
     * Symfony puts in $_SERVER and $_ENV, not in getenv()).
     */
    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param list<string> $languages
     */
    private static function country(?string $configured, array $languages): ?string
    {
        if (null !== $configured && 1 === preg_match('/^[a-z]{2}$/i', trim($configured))) {
            return strtolower(trim($configured));
        }
        foreach ($languages as $language) {
            if (1 === preg_match('/^[a-z]{2,3}[_-]([a-z]{2})(?:[._@]|$)/i', $language, $match)) {
                return strtolower($match[1]);
            }
        }

        return null;
    }
}
