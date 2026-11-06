# 🍀 Looking for Luck

**The honest lottery toolkit.** Statistics, cryptographically secure grid
generation, strategy backtesting and Monte Carlo budget simulation — for
people who enjoy playing the lottery *and* want to understand exactly what
they're playing with.

[![CI](https://github.com/beebop1032/looking-for-luck/actions/workflows/ci.yml/badge.svg)](https://github.com/beebop1032/looking-for-luck/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.4-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-8-000000?logo=symfony)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)
![License](https://img.shields.io/badge/license-MIT-green)

> **The honest part:** no tool in this project improves your odds of winning.
> Every draw is independent, the expected value of a ticket is negative, and
> our own chi-squared test confirms the draws are compatible with pure
> randomness. What this toolkit *does* do: generate unbiased grids that you're
> less likely to have to **share** a jackpot with, show you what any
> "hot numbers" strategy is really worth, and tell you what your hobby costs
> over 10 years. Play for fun, never for profit.

---

## ✨ Features

🎲 **Grid generator** — grids drawn with PHP's CSPRNG (`random_int()`), then
passed through an **anti-sharing filter** that rejects combinations crowds
love to play: all-birthday numbers (≤ 31), arithmetic sequences, visual
patterns on the paper slip (columns, rows, diagonals), shared multiples, and
combinations that already won. Your odds don't change — your expected share of
a jackpot does.

📊 **Statistics** — frequencies, overdue numbers, most frequent pairs over the
**full draw history (since 2004)**, plus a **chi-squared test** explained in
plain language that shows whether the observed distribution is compatible with
pure randomness. (Spoiler: it is.)

🧪 **Strategy lab** — pick a strategy (*hot*, *cold*, *overdue*, *random*) and
replay it over two decades of real draws — using only information available
before each draw — against **10,000 simulated random players**. See its
winnings, losses, and exactly where it lands in the distribution.

💸 **Budget simulator** — exact per-tier probabilities (hypergeometric, down
to the official 1 in 139,838,160), configurable ticket price and jackpot, and
a Monte Carlo simulation of N years of play: median outcome, percentiles,
expected loss, probability of ending in profit.

🎟️ **My grids** — save the grids you actually play and check them
automatically against every new draw.

🤖 **AI assistant (optional)** — drop an Anthropic API key in `.env.local`
and chat with Claude about *your* data: it receives a factual summary
(statistics, chi-squared verdict, prize maths, your saved grids) and discusses
it honestly — it will kindly refuse to "predict" anything. No key, no problem:
everything else works without it.

## 🚀 Quick start

```bash
git clone https://github.com/beebop1032/looking-for-luck.git
cd looking-for-luck
composer install
```

Or with Docker (no PHP needed):

```bash
docker compose run --rm app app:euromillions:predict 5
```

```bash
# Generate 5 grids (CSPRNG + anti-sharing filter)
bin/console app:euromillions:predict 5

# Full-history statistics with the chi-squared test
bin/console app:euromillions:stats

# Backtest "hot numbers" against 10,000 random players
bin/console app:euromillions:lab hot

# What does 10 years of playing actually cost?
bin/console app:euromillions:budget --years=10

# Save your grids and check them after every draw
bin/console app:euromillions:grids add "1 9 23 34 48 + 3 7"
bin/console app:euromillions:grids check

# Optional: chat with an AI about your data (needs ANTHROPIC_API_KEY)
bin/console app:euromillions:ask "Why does number 44 come up so often?"
bin/console app:euromillions:ask            # interactive chat
```

The draw history is downloaded once and cached under `var/euromillions/`
(6-hour TTL for the current year, permanent for past years), with a graceful
fallback to a stale cache when offline.

## 🧱 Architecture: one engine, many lotteries

The engine is **lottery-agnostic**: everything generic lives in
`src/Lottery/` and only knows a game through the `LotteryGame` interface
(pool sizes, picks, prize tiers, slip layout). EuroMillions is the first
implementation.

```
src/
├── Lottery/                  # generic engine — no EuroMillions knowledge
│   ├── LotteryGame.php        # the interface a lottery implements
│   ├── PrizeTier.php          # one winning tier (matches + average prize)
│   ├── Grid.php               # validated value object (main + bonus numbers)
│   ├── Draw.php               # a dated historical draw
│   ├── GridGenerator.php      # CSPRNG sampling
│   ├── PopularityFilter.php   # the anti-sharing rules
│   ├── PrizeTable.php         # exact hypergeometric tier probabilities
│   ├── Statistics.php         # frequencies, gaps, pairs
│   ├── ChiSquare.php          # χ² test, p-value via incomplete gamma (no deps)
│   ├── StrategyLab.php        # historical replay vs random cohort
│   ├── BudgetSimulator.php    # Monte Carlo trajectories
│   ├── WinSampler.php         # fast binomial sampling via geometric skips
│   └── Percentile.php         # shared percentile maths
├── Euromillions/              # the EuroMillions adapter
│   ├── EuromillionsGame.php   # 5/50 + 2/12 matrix, 13 prize tiers
│   ├── DrawRepository.php     # full-history fetcher + on-disk cache
│   ├── GridStore.php          # local "my grids" storage
│   └── SavedGrid.php          # a saved grid with bookkeeping dates
└── Command/                   # 5 thin console commands
```

**Adding a lottery** (Powerball, Mega Millions, Eurojackpot…) means
implementing `LotteryGame` (a matrix + a prize table) and a draw source.
The generator, filter, prize maths, lab and simulator come for free.
Contributions welcome:

- 🇪🇺 **EuroMillions** — done
- 🇺🇸 **Powerball** / **Mega Millions** — planned
- 🇪🇺 **Eurojackpot** — planned
- 🇬🇧 **UK Lotto**, 🇫🇷 **Loto**, 🇧🇪 **Belgian Lotto**… — open to PRs

## ✅ Quality

```bash
vendor/bin/phpunit            # test suite
vendor/bin/phpstan analyse    # static analysis, level max
vendor/bin/php-cs-fixer fix   # coding standards (@Symfony + risky)
```

CI runs the test suite on PHP 8.4 / 8.5, PHPStan at level **max**,
php-cs-fixer, `composer audit` and a Docker image build on every push and
pull request. The tests
cover the chi-squared quantiles, the official odds, the sum of all outcome
probabilities (= 1), every popularity-filter rule, and generator validity.

## 🔒 Security posture

- Symfony 8, `composer audit` clean in CI.
- HTTP through the HttpClient component: TLS verified, timeouts, capped
  redirects, single hard-coded host.
- Every piece of external data (scraped HTML, JSON caches, grid file) is
  revalidated before use; all writes are atomic.
- Real grids come from `random_int()` (CSPRNG) — simulations use a fast PRNG,
  clearly separated.

## 🤝 Contributing

Issues and PRs are welcome — especially new lottery adapters, better prize
data, and translations. **[CONTRIBUTING.md](CONTRIBUTING.md) has a 3-step
guide to adding a lottery.** Keep the tone of the project: the maths stays
honest, and every screen that touches money keeps its reality check.

## ⚠️ Responsible play

Gambling involves risk: debt, isolation, addiction. If playing stops being
fun, help exists — among others: [BeGambleAware](https://www.begambleaware.org) (UK),
[Joueurs Info Service](https://www.joueurs-info-service.fr) (FR),
[SOS Jeux](https://www.joueurssosjeux.be) (BE), [NCPG](https://www.ncpgambling.org) (US).

## 📄 License

[MIT](LICENSE) — do whatever you want, just keep the notice.
