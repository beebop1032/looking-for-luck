<?php

declare(strict_types=1);

namespace App\Web;

use App\Intl\Format;
use App\Lottery\Lottery;
use App\Lottery\LotteryRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Locale-aware formatting helpers and shared data for the web templates.
 */
final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public const LANGUAGE_NAMES = [
        'en' => 'English',
        'fr' => 'Français',
        'nl' => 'Nederlands',
        'es' => 'Español',
        'de' => 'Deutsch',
    ];

    /**
     * @param list<string> $locales
     */
    public function __construct(
        private readonly LotteryRegistry $registry,
        private readonly RequestStack $requestStack,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.locales%')]
        private readonly array $locales,
        #[Autowire('%app.repository_url%')]
        private readonly string $repositoryUrl,
        #[Autowire('%app.version%')]
        private readonly string $version,
        #[Autowire('%app.country%')]
        private readonly string $country = '',
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('integer', fn (float|int $value): string => $this->number($value, 0)),
            new TwigFilter('num', $this->number(...)),
            new TwigFilter('heat', static fn (float $deviation): string => self::heat($deviation)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('lotteries', $this->registry->all(...)),
            new TwigFunction('planned_lotteries', $this->registry->planned(...)),
            new TwigFunction('current_lottery', $this->currentLottery(...)),
            new TwigFunction('bonus_label', $this->bonusLabel(...)),
            new TwigFunction('draw_days', $this->drawDays(...)),
            new TwigFunction('gambling_notice', $this->gamblingNotice(...)),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'languages' => array_intersect_key(self::LANGUAGE_NAMES, array_flip($this->locales)),
            'repo_url' => $this->repositoryUrl,
            'app_version' => $this->version,
        ];
    }

    /**
     * @return array{country: ?string, lang: string, text: string, help: ?string, url: ?string, age: string}
     */
    public function gamblingNotice(): array
    {
        $request = $this->requestStack->getMainRequest();

        return GamblingNotice::for(
            $this->localeSwitcher->getLocale(),
            array_values($request?->getLanguages() ?? []),
            '' === $this->country ? null : $this->country,
        );
    }

    public function currentLottery(): ?Lottery
    {
        $lottery = $this->requestStack->getCurrentRequest()?->attributes->get(LotteryValueResolver::ATTRIBUTE);

        return $lottery instanceof Lottery ? $lottery : null;
    }

    /**
     * What the lottery calls its bonus numbers, translated when the catalogue
     * knows it ("lottery.<slug>.bonus"), else the game's own label.
     */
    public function bonusLabel(Lottery $lottery, int $count = 2): string
    {
        $id = 'lottery.'.$lottery->slug().'.bonus';
        if ($this->translator instanceof TranslatorBagInterface && $this->translator->getCatalogue()->has($id)) {
            return $this->translator->trans($id, ['count' => $count]);
        }

        return $lottery->game()->bonusLabel();
    }

    /**
     * The draw days in the current language, e.g. "mardi et vendredi".
     */
    public function drawDays(Lottery $lottery): string
    {
        $formatter = new \IntlDateFormatter($this->localeSwitcher->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'EEEE');
        $monday = new \DateTimeImmutable('2024-01-01', new \DateTimeZone('UTC')); // a Monday

        $names = [];
        foreach ($lottery->game()->drawDays() as $day) {
            $names[] = (string) $formatter->format($monday->modify(\sprintf('+%d days', $day - 1)));
        }

        $last = array_pop($names);

        return [] === $names ? (string) $last : implode(', ', $names).' '.$this->translator->trans('common.and').' '.$last;
    }

    /**
     * An amount in the current lottery's currency (euros outside a lottery).
     */
    public function money(float|int $amount, int $decimals = 2, bool $signed = false): string
    {
        $currency = $this->currentLottery()?->game()->currency() ?? 'EUR';

        return ($signed && $amount > 0 ? '+' : '').Format::money($amount, $decimals, $currency, $this->localeSwitcher->getLocale());
    }

    public function number(float|int $value, int $decimals = 0): string
    {
        return Format::decimal($value, $decimals, $this->localeSwitcher->getLocale());
    }

    /**
     * Cold-to-hot colour for a deviation in [-1, 1], as an HSL string.
     */
    public static function heat(float $deviation): string
    {
        $t = max(-1.0, min(1.0, $deviation));

        return $t < 0
            ? \sprintf('hsl(228 80%% %d%%)', (int) round(93 - 38 * abs($t)))
            : \sprintf('hsl(14 85%% %d%%)', (int) round(93 - 36 * $t));
    }
}
