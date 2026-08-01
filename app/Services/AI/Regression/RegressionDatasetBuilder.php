<?php

namespace App\Services\AI\Regression;

use App\Models\Project;
use Illuminate\Support\Collection;

class RegressionDatasetBuilder
{
    /**
     * Build a regression-ready dataset.
     *
     * Regression operates on ONE representative observation
     * per calendar month (latest inspection).
     */
    public function buildDataset(Project $project): array
    {
        $records = $this->normalizeMonthlyRecords(
            $project->progressHistory()
                ->orderBy('month_label')
                ->orderBy('inspection_date')
                ->get()
        );

        $x = [];
        $y = [];

        foreach ($records->values() as $index => $record) {

            // Project Month Index
            $x[] = (float) ($index + 1);

            // Actual cumulative progress
            $y[] = (float) $record->actual_progress_percent;
        }

        return [

            /*
            |--------------------------------------------------------------------------
            | Regression Dataset
            |--------------------------------------------------------------------------
            |
            | Used By:
            | - LinearRegressionService
            |
            */

            'x' => $x,

            'y' => $y,

            /*
            |--------------------------------------------------------------------------
            | Normalized Monthly Records
            |--------------------------------------------------------------------------
            |
            | Used By:
            | - Future forecasting
            | - Baseline comparison
            | - AI prediction analysis
            |
            */

            'records' => $records,

            /*
            |--------------------------------------------------------------------------
            | Dataset Statistics
            |--------------------------------------------------------------------------
            |
            | Used By:
            | - AIPredictionService
            |
            | Contains metadata describing the generated regression dataset.
            |
            */

            'statistics' => [

                'observation_count' => $records->count(),

                'current_month_index' => count($x),

                'first_month' => optional($records->first())->inspection_date,

                'last_month' => optional($records->last())->inspection_date,

            ]

        ];
    }

    /**
     * Normalize project history.
     *
     * If multiple inspections exist in the same month,
     * keep only the latest inspection.
     */
    private function normalizeMonthlyRecords(Collection $records): Collection
    {
        return $records
            ->groupBy(function ($record) {
                return $record->month_label;
            })
            ->map(function ($monthRecords) {

                return $monthRecords
                    ->sortByDesc('inspection_date')
                    ->first();

            })
            ->sortBy('month_label')
            ->values();
    }
}