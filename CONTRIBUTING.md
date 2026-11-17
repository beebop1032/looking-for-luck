# Contributing to Looking for Luck 🍀

Thanks for considering a contribution! This project is small, honest and
well-tested — let's keep it that way.

## Ground rules

1. **The maths stays honest.** Nothing in this project may claim, imply or
   hint that any tool improves the odds of winning. Every feature that touches
   money keeps its reality check (negative expected value, independence of
   draws). PRs that add "prediction" features will be declined.
2. **Quality gates are not optional.** CI runs the test suite (PHP 8.4+),
   PHPStan at level **max**, php-cs-fixer and `composer audit`. Run them
   locally before pushing:

   ```bash
   vendor/bin/phpunit
   vendor/bin/phpstan analyse
   vendor/bin/php-cs-fixer fix
   composer audit
   ```

3. **External data is never trusted.** Anything scraped, cached or read from
   disk is revalidated before use (see `Grid::of()` and the repositories).

## The most wanted contribution: a new lottery 🎰

The engine (`src/Lottery/`) knows no specific lottery. Adding Powerball,
Mega Millions, Eurojackpot or your national lottery takes three classes — and
the web interface, the scheduled sync, the statistics, the generator, the
budget simulator and the strategy lab then work for it with no other code.
Look at `src/Euromillions/` side by side: it is the reference implementation.

### 1. Describe the game: `LotteryGame`

Create e.g. `src/Powerball/PowerballGame.php` implementing
`App\Lottery\LotteryGame`: pool sizes, picks per grid, draw days, time and
timezone, currency, ticket price, the date of the last matrix change (if any)
and the prize tiers with their indicative average amounts. It's mostly data.

> One gotcha: `PrizeTable` computes **exact** hypergeometric probabilities
> from your matrix. Add a test asserting the official published odds (like
> `PrizeTableTest::testJackpotOdds`) — if your matrix is right, the odds
> match to the digit.

### 2. Fetch the draws: `DrawSource`

Implement `App\Lottery\DrawSource` (`all()`, `refresh()`, `firstYear()`):
return `list<Draw>`, oldest first, cached on disk. Scraping, a CSV export or
an open API are all fine, as long as:

- every parsed value goes through `Grid::of()` (it validates ranges and
  cardinalities);
- on reading pages, failures fall back to the stale cache; `refresh()`
  (the sync) throws instead, so a failed download is never reported as
  "up to date"; an empty answer never overwrites a good cache (see
  `DrawRepositoryTest`);
- the parser has a test with a small fixture of the real format.

### 3. Register it: `LotteryDefinition`

Implement `App\Lottery\LotteryDefinition` (see `EuromillionsDefinition`):
a URL slug (`powerball`), your game and draw source, a region flag, the
colour of the lottery's "stage" in the interface (a dark colour, text on it
is white), the shape of the bonus numbers (`star` or `ball`) and the official
website. Symfony picks it up automatically: the lottery
appears on the home page, at `/powerball`, and in `app:sync`.

Then, in each file of `translations/messages+intl-icu.*.yaml`, add the name
of your bonus numbers, e.g.
`"lottery.powerball.bonus": "{count, plural, one {Powerball} other {Powerballs}}"`
(without it, the interface falls back to `LotteryGame::bonusLabel()`).

**Checklist for a lottery PR:**

- [ ] `LotteryGame` + a test asserting the official jackpot odds
- [ ] `DrawSource` with on-disk cache, stale fallback and a parser test
- [ ] `LotteryDefinition` + the bonus label in the five translation files
- [ ] The README lottery table updated
- [ ] All quality gates green

The `app:euromillions:*` console commands are EuroMillions-specific; porting
them to your lottery is welcome but optional.

## Translations 🌍

Strings live in `translations/` (ICU message format, for plurals), one file
per language and per audience: `messages+intl-icu.<lang>.yaml` for the web
interface, `cli+intl-icu.<lang>.yaml` for the terminal (`--locale=<lang>`),
and `filter.<lang>.yaml` for the anti-sharing filter reasons. To add a
language: copy the English files, translate every value, add the language code
to `app.locales` in `config/services.yaml` and its name in
`App\Web\AppExtension::LANGUAGE_NAMES`. `TranslationsTest` checks that every
language defines every key with the same placeholders. Two rules: use typographic apostrophes (’), since a plain `'` is
an escape character in ICU, and keep the honest wording about odds intact.

## Other welcome contributions

- Better indicative prize averages (with a source)
- New anti-sharing filter rules (with evidence that the pattern is popular)
- Design and accessibility feedback on the web interface
- Docs, examples, bug fixes

## Workflow

1. Fork, create a feature branch.
2. Make your change with tests.
3. Ensure the quality gates pass.
4. Open a PR with a clear description — what, why, and how it was verified.

By contributing you agree your work is released under the [MIT license](LICENSE).
