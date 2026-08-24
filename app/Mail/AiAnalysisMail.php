<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\AiSolution;
use App\Models\EmailTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AiAnalysisMail extends Mailable
{
    use Queueable, SerializesModels;

    public Project $project;
    public ?AiSolution $aiSolution;
    public EmailTracking $tracking;
    public string $plannedProgress;
    public string $actualProgress;
    public string $varianceLabel;
    public string $varianceColor;

    public function __construct(Project $project, ?AiSolution $aiSolution, EmailTracking $tracking)
    {
        $this->project = $project;
        $this->aiSolution = $aiSolution;
        $this->tracking = $tracking;

        $progress = $project->getProgressPercentage();
        $lastBaseline = $project->baselineSchedules()->latest('planned_date')->first();
        $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 0;
        $actual = $project->status === 'Completed' ? 100 : (float) $progress;
        $variance = $actual - $planned;

        $this->plannedProgress = number_format($planned, 1) . '%';
        $this->actualProgress = number_format($actual, 1) . '%';

        if ($variance >= 0) {
            $this->varianceLabel = 'On Track';
            $this->varianceColor = '#10b981';
        } elseif ($variance >= -15) {
            $this->varianceLabel = 'At Risk';
            $this->varianceColor = '#f59e0b';
        } else {
            $this->varianceLabel = 'Critical';
            $this->varianceColor = '#ef4444';
        }
    }

    public function build()
    {
        $trackingUrl = route('tracking.open', ['token' => $this->tracking->tracking_token]);

        return $this->subject($this->tracking->subject ?: ('AI Project Status Report — ' . $this->project->title))
                    ->with(['trackingUrl' => $trackingUrl])
                    ->view('emails.ai-analysis');
    }
}
