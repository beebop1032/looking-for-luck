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

The engine (`src/Lottery/`) is lottery-agnostic. Adding Powerball, Mega
Millions, Eurojackpot or your national lottery takes three steps:

### 1. Implement `LotteryGame`

Create `src/Powerball/PowerballGame.php` implementing
`App\Lottery\LotteryGame`: pool sizes, picks per grid, draws per week, slip
columns, ticket price, and the prize tiers with their indicative average
amounts. See `src/Euromillions/EuromillionsGame.php` — it's ~100 lines, mostly
data.

> One gotcha: `PrizeTable` computes **exact** hypergeometric probabilities
> from your matrix. Add a test asserting the official published odds (like
> `PrizeTableTest::testJackpotOdds`) — if your matrix is right, the odds
> match to the digit.

### 2. Provide a draw source

Create a `DrawRepository` for your lottery that returns `list<Draw>` (oldest
first) and caches to `var/<lottery>/`. Scraping or an open API — either is
fine, as long as every parsed value goes through `Grid::of()` (which
validates ranges and cardinalities) and failures fall back to a stale cache.

### 3. Wire the commands

Copy the five thin commands in `src/Command/`, point them at your game and
repository, and register the namespace (e.g. `app:powerball:*`). That's it —
the generator, anti-sharing filter, prize maths, strategy lab and Monte Carlo
simulator work unchanged.

**Checklist for a lottery PR:**

- [ ] `LotteryGame` implementation + test asserting the official odds
- [ ] Draw source with on-disk cache and stale fallback
- [ ] Commands wired (`stats`, `predict`, `lab`, `budget`, `grids`)
- [ ] A line in the README roadmap table
- [ ] All quality gates green

## Other welcome contributions

- Better indicative prize averages (with a source)
- New anti-sharing filter rules (with evidence that the pattern is popular)
- Translations of command output
- Docs, examples, bug fixes

## Workflow

1. Fork, create a feature branch.
2. Make your change with tests.
3. Ensure the quality gates pass.
4. Open a PR with a clear description — what, why, and how it was verified.

By contributing you agree your work is released under the [MIT license](LICENSE).
