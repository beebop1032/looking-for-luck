<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Chi-squared goodness-of-fit test against a uniform distribution.
 *
 * The p-value is computed with the regularized incomplete gamma function
 * (series + continued fractions, cf. Numerical Recipes), no external
 * dependency required.
 */
final class ChiSquare
{
    /**
     * @param array<int,int> $observed observed counts per category
     *
     * @return array{statistic: float, degreesOfFreedom: int, pValue: float}
     */
    public function uniformGoodnessOfFit(array $observed): array
    {
        $categories = \count($observed);
        if ($categories < 2) {
            throw new \InvalidArgumentException('The test requires at least two categories.');
        }

        $total = array_sum($observed);
        if ($total <= 0) {
            throw new \InvalidArgumentException('No observations.');
        }

        $expected = $total / $categories;
        $statistic = 0.0;
        foreach ($observed as $count) {
            $statistic += (($count - $expected) ** 2) / $expected;
        }

        $df = $categories - 1;

        return [
            'statistic' => $statistic,
            'degreesOfFreedom' => $df,
            'pValue' => $this->upperTailProbability($statistic, $df),
        ];
    }

    /**
     * P(X >= statistic) for X ~ chi-squared(df): Q(df/2, x/2).
     */
    public function upperTailProbability(float $statistic, int $degreesOfFreedom): float
    {
        if ($statistic <= 0.0) {
            return 1.0;
        }

        return $this->regularizedGammaQ($degreesOfFreedom / 2.0, $statistic / 2.0);
    }

    private function regularizedGammaQ(float $a, float $x): float
    {
        if ($x < $a + 1.0) {
            return 1.0 - $this->gammaSeriesP($a, $x);
        }

        return $this->gammaContinuedFractionQ($a, $x);
    }

    private function gammaSeriesP(float $a, float $x): float
    {
        $gln = $this->logGamma($a);
        $ap = $a;
        $sum = 1.0 / $a;
        $del = $sum;

        for ($n = 0; $n < 500; ++$n) {
            ++$ap;
            $del *= $x / $ap;
            $sum += $del;
            if (abs($del) < abs($sum) * 1e-14) {
                break;
            }
        }

        return $sum * exp(-$x + $a * log($x) - $gln);
    }

    private function gammaContinuedFractionQ(float $a, float $x): float
    {
        $gln = $this->logGamma($a);
        $tiny = 1e-300;

        $b = $x + 1.0 - $a;
        $c = 1.0 / $tiny;
        $d = 1.0 / $b;
        $h = $d;

        for ($i = 1; $i <= 500; ++$i) {
            $an = -$i * ($i - $a);
            $b += 2.0;
            $d = $an * $d + $b;
            if (abs($d) < $tiny) {
                $d = $tiny;
            }
            $c = $b + $an / $c;
            if (abs($c) < $tiny) {
                $c = $tiny;
            }
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;
            if (abs($del - 1.0) < 1e-14) {
                break;
            }
        }

        return exp(-$x + $a * log($x) - $gln) * $h;
    }

    /**
     * ln Γ(x) using the Lanczos approximation.
     */
    private function logGamma(float $x): float
    {
        $coefficients = [
            76.18009172947146,
            -86.50532032941677,
            24.01409824083091,
            -1.231739572450155,
            0.1208650973866179e-2,
            -0.5395239384953e-5,
        ];

        $y = $x;
        $tmp = $x + 5.5;
        $tmp -= ($x + 0.5) * log($tmp);
        $ser = 1.000000000190015;
        foreach ($coefficients as $coefficient) {
            $ser += $coefficient / ++$y;
        }

        return -$tmp + log(2.5066282746310005 * $ser / $x);
    }
}
