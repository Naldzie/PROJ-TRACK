<?php 

    namespace App\Services\AI\Regression;

    class LinearRegressionService
    {
        /**
         * Analyze a regression dataset.
         *
         * @param array $x Project Month Index
         * @param array $y Actual Cumulative Progress
         *
         * @return array
         */

        public function analyzeDataset(array $x, array $y): array
    {
        $this->validateDataset($x, $y);

        $meanX = $this->calculateMean($x);
        $meanY = $this->calculateMean($y);

        $slope = $this->calculateSlope(
            $x,
            $y,
            $meanX,
            $meanY
        );

        $intercept = $this->calculateIntercept(
            $meanX,
            $meanY,
            $slope
        );

        $rSquared = $this->calculateRSquared(
            $x,
            $y,
            $meanY,
            $slope,
            $intercept
        );

        return [

            'observation_count' => count($x),

            'mean_x' => $meanX,

            'mean_y' => $meanY,

            'slope' => $slope,

            'intercept' => $intercept,

            'equation' => $this->formatEquation(
                $slope,
                $intercept
            ),

            'r_squared' => $rSquared,

            'fit_quality' => $this->classifyFit($rSquared),
        ];
    }

        /**
         * Predict cumulative progress for a future Project Month Index.
         *
         * Uses the regression equation:
         *
         * y = mx + b
         */
        public function predict(
            float $monthIndex,
            float $slope,
            float $intercept
        ): float {

            return ($slope * $monthIndex) + $intercept;
        }

        /**
         * Predict using an existing regression analysis.
         */
        public function predictUsingAnalysis(
            array $analysis,
            float $monthIndex
        ): float {

            return $this->predict(
                $monthIndex,
                $analysis['slope'],
                $analysis['intercept']
            );
        }

         /**
         * Calculate the arithmetic mean.
         */

         private function calculateMean(array $values): float
         {
            return array_sum($values) / count($values);
         }

         /**
         * Calculate the regression slope (m).
         *
         * Formula:
         *
         *      Σ((x - x̄)(y - ȳ))
         * m = --------------------
         *        Σ((x - x̄)^2)
         */
        private function calculateSlope(
            array $x,
            array $y,
            float $meanX,
            float $meanY
        ): float {

            $numerator = 0.0;
            $denominator = 0.0;

            foreach ($x as $index => $xValue) {

                $numerator +=
                    ($xValue - $meanX)
                    *
                    ($y[$index] - $meanY);

                $denominator +=
                    pow($xValue - $meanX, 2);
            }

            if ($denominator == 0) {
                throw new \RuntimeException(
                    'Unable to calculate slope because all X values are identical.'
                );
            }

            return $numerator / $denominator;
        }

        /**
         * Calculate the regression intercept (b).
         *
         * Formula:
         *
         * b = ȳ - (m × x̄)
         */
        private function calculateIntercept(
            float $meanX,
            float $meanY,
            float $slope
        ): float {

            return $meanY - ($slope * $meanX);
        }

        /**
         * Calculate the coefficient of determination (R²).
         *
         * R² = 1 - (SSres / SStot)
         */
        private function calculateRSquared(
            array $x,
            array $y,
            float $meanY,
            float $slope,
            float $intercept
        ): float {

            $ssResidual = 0.0;

            $ssTotal = 0.0;

            foreach ($x as $index => $xValue) {

                $predicted =
                    ($slope * $xValue)
                    + $intercept;

                $ssResidual +=
                    pow(
                        $y[$index] - $predicted,
                        2
                    );

                $ssTotal +=
                    pow(
                        $y[$index] - $meanY,
                        2
                    );
            }

            if ($ssTotal == 0) {
                return 1.0;
            }

            return 1 - ($ssResidual / $ssTotal);
        }

         /**
         * Validate regression dataset.
         */
        private function validateDataset(array $x, array $y): void
        {
            if (count($x) !== count($y)) {
                throw new \InvalidArgumentException(
                'X and Y datasets must contain the same number of observations.'
                );
            }

            if (count($x) < 2) {
                 throw new \InvalidArgumentException(
                'Linear regression requires at least two observations.'
            );
            }
        }

        /**
         * Format the regression equation for display.
         */
        private function formatEquation(
            float $slope,
            float $intercept
        ): string {

            $operator = $intercept >= 0 ? '+' : '-';

            return sprintf(
                'y = %.4fx %s %.4f',
                $slope,
                $operator,
                abs($intercept)
            );
        }

        /**
         * Classify the regression quality based on R².
         */
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