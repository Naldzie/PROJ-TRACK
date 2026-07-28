<?php

namespace App\Services\AI\Regression;

class PolynomialRegressionService
{
    public function analyzeDataset(array $x, array $y): array
    {
        $this->validateDataset($x, $y);

        $coefficients = $this->solveSystem($x, $y);

        [$a, $b, $c, $d] = $coefficients;

        $meanY = $this->calculateMean($y);

        $rSquared = $this->calculateRSquared(
            $x,
            $y,
            $meanY,
            $a,
            $b,
            $c,
            $d
        );

        return [

            'observation_count' => count($x),

            'coefficients' => [$a, $b, $c, $d],

            'a' => $a,

            'b' => $b,

            'c' => $c,

            'd' => $d,

            'equation' => $this->formatEquation(
                $a,
                $b,
                $c,
                $d
            ),

            'r_squared' => $rSquared,

            'fit_quality' => $this->classifyFit($rSquared),
        ];
    }

    public function predict(
        float $monthIndex,
        float $a,
        float $b,
        float $c,
        float $d
    ): float {

        return ($a * $monthIndex * $monthIndex * $monthIndex)
             + ($b * $monthIndex * $monthIndex)
             + ($c * $monthIndex)
             + $d;
    }

    public function predictUsingAnalysis(
        array $analysis,
        float $monthIndex
    ): float {

        return $this->predict(
            $monthIndex,
            $analysis['a'],
            $analysis['b'],
            $analysis['c'],
            $analysis['d']
        );
    }

    private function solveSystem(array $x, array $y): array
    {
        $matrix = $this->buildNormalEquations($x, $y);

        return $this->gaussianElimination($matrix);
    }

    private function buildNormalEquations(array $x, array $y): array
    {
        $n = count($x);

        $sumX  = 0.0;
        $sumX2 = 0.0;
        $sumX3 = 0.0;
        $sumX4 = 0.0;
        $sumX5 = 0.0;
        $sumX6 = 0.0;
        $sumY  = 0.0;
        $sumXY   = 0.0;
        $sumX2Y  = 0.0;
        $sumX3Y  = 0.0;

        foreach ($x as $i => $xi) {
            $yi = $y[$i];

            $x2 = $xi * $xi;
            $x3 = $x2 * $xi;
            $x4 = $x3 * $xi;
            $x5 = $x4 * $xi;
            $x6 = $x5 * $xi;

            $sumX  += $xi;
            $sumX2 += $x2;
            $sumX3 += $x3;
            $sumX4 += $x4;
            $sumX5 += $x5;
            $sumX6 += $x6;
            $sumY  += $yi;
            $sumXY  += $xi  * $yi;
            $sumX2Y += $x2  * $yi;
            $sumX3Y += $x3  * $yi;
        }

        /*
        |--------------------------------------------------------------------------
        | Augmented Matrix [A | b]
        |--------------------------------------------------------------------------
        |
        | Each row: [Σx⁶, Σx⁵, Σx⁴, Σx³ | Σx³y]    (row 0 — a)
        |           [Σx⁵, Σx⁴, Σx³, Σx² | Σx²y]    (row 1 — b)
        |           [Σx⁴, Σx³, Σx², Σx  | Σxy ]    (row 2 — c)
        |           [Σx³, Σx², Σx , n   | Σy  ]    (row 3 — d)
        |
        */

        return [
            [$sumX6, $sumX5, $sumX4, $sumX3, $sumX3Y],
            [$sumX5, $sumX4, $sumX3, $sumX2, $sumX2Y],
            [$sumX4, $sumX3, $sumX2, $sumX,  $sumXY ],
            [$sumX3, $sumX2, $sumX,  $n,     $sumY  ],
        ];
    }

    private function gaussianElimination(array $matrix): array
    {
        $n = count($matrix);

        for ($col = 0; $col < $n - 1; $col++) {

            $maxRow = $col;

            $maxVal = abs($matrix[$col][$col]);

            for ($row = $col + 1; $row < $n; $row++) {
                $val = abs($matrix[$row][$col]);

                if ($val > $maxVal) {
                    $maxVal = $val;

                    $maxRow = $row;
                }
            }

            if ($maxRow !== $col) {
                [$matrix[$col], $matrix[$maxRow]] =
                    [$matrix[$maxRow], $matrix[$col]];
            }

            if (abs($matrix[$col][$col]) < 1.0e-12) {
                throw new \RuntimeException(
                    'Unable to solve polynomial regression: '
                    . 'the normal equation matrix is singular.'
                );
            }

            for ($row = $col + 1; $row < $n; $row++) {
                $factor =
                    $matrix[$row][$col] / $matrix[$col][$col];

                for ($j = $col; $j <= $n; $j++) {
                    $matrix[$row][$j] -=
                        $factor * $matrix[$col][$j];
                }
            }
        }

        $solution = array_fill(0, $n, 0.0);

        for ($row = $n - 1; $row >= 0; $row--) {
            $sum = $matrix[$row][$n];

            for ($j = $row + 1; $j < $n; $j++) {
                $sum -= $matrix[$row][$j] * $solution[$j];
            }

            if (abs($matrix[$row][$row]) < 1.0e-12) {
                throw new \RuntimeException(
                    'Unable to solve polynomial regression: '
                    . 'zero pivot during back substitution.'
                );
            }

            $solution[$row] =
                $sum / $matrix[$row][$row];
        }

        return $solution;
    }

    private function calculateMean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    private function calculateRSquared(
        array $x,
        array $y,
        float $meanY,
        float $a,
        float $b,
        float $c,
        float $d
    ): float {

        $ssResidual = 0.0;

        $ssTotal = 0.0;

        foreach ($x as $i => $xi) {
            $predicted =
                $this->predict($xi, $a, $b, $c, $d);

            $ssResidual +=
                pow($y[$i] - $predicted, 2);

            $ssTotal +=
                pow($y[$i] - $meanY, 2);
        }

        if ($ssTotal == 0) {
            return 1.0;
        }

        return 1 - ($ssResidual / $ssTotal);
    }

    private function validateDataset(array $x, array $y): void
    {
        if (count($x) !== count($y)) {
            throw new \InvalidArgumentException(
                'X and Y datasets must contain the same number of observations.'
            );
        }

        if (count($x) < 4) {
            throw new \InvalidArgumentException(
                'Polynomial regression (degree 3) requires at least four observations.'
            );
        }
    }

    private function formatEquation(
        float $a,
        float $b,
        float $c,
        float $d
    ): string {

        $equation = sprintf('y = %.4fx³', $a);

        $equation .= sprintf(
            ' %s %.4fx²',
            $b >= 0 ? '+' : '-',
            abs($b)
        );

        $equation .= sprintf(
            ' %s %.4fx',
            $c >= 0 ? '+' : '-',
            abs($c)
        );

        $equation .= sprintf(
            ' %s %.4f',
            $d >= 0 ? '+' : '-',
            abs($d)
        );

        return $equation;
    }

    private function classifyFit(float $rSquared): string
    {
        if ($rSquared >= 0.90) {
            return 'Excellent';
        }

        if ($rSquared >= 0.80) {
            return 'Very Good';
        }

        if ($rSquared >= 0.70) {
            return 'Good';
        }

        if ($rSquared >= 0.50) {
            return 'Moderate';
        }

        return 'Weak';
    }
}
