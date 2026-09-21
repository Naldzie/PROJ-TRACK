<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;

/**
 * ============================================================================
 * SCurveSourceFinder
 * ============================================================================
 *
 * Purpose
 * -------
 * Lists the project documents an S-curve can be imported from — every
 * .xls / .xlsx / .pdf — ranked by how likely each is the S-curve, for the file
 * picker shown by every "Import from S-curve" button
 * (textfiles/scurve_import_original_baseline_plan.md, S-D4 / S-D5 / P2).
 *
 * The document TYPE only RANKS a file, it never hides one. The button used to
 * read only the newest document whose type is literally named "Schedule", so
 * renaming or removing that type made the import disappear, a second S-curve
 * (preliminary + revised) could never be chosen, and a file saved under
 * another type was invisible.
 *
 * Ranking (highest first; ties → newest upload first):
 *   +4  document type named "Schedule" (case-insensitive)
 *   +2  file name looks like an S-curve / schedule
 *   +1  spreadsheet (.xls/.xlsx — read exactly; PDFs are read from the print)
 * The same scores are used by the create wizard's picker
 * (scurve-import-core.js rankSCurveCandidates) — keep the two in step.
 *
 * Used By
 * -------
 * components/tabs/baseline-schedule.blade.php (candidates for the picker)
 */
class SCurveSourceFinder
{
    /** Extensions the S-curve readers accept. */
    public const EXTENSIONS = ['xls', 'xlsx', 'pdf'];

    /** File names that look like an S-curve / schedule. */
    public const NAME_HINT = '/s[-_\s]?curve|scurve|schedule/i';

    /**
     * ------------------------------------------------------------------------
     * Candidates for a project
     * ------------------------------------------------------------------------
     *
     * Returns a plain list, best candidate first:
     *   [['id', 'name', 'ext', 'type_name', 'uploaded_at', 'download_url',
     *     'is_schedule_type', 'name_hint', 'score'], …]
     * download_url is the role-scoped download route (PDFs are parsed in the
     * browser, so the picker needs it); null when there is no signed-in role.
     */
    public function candidatesFor(Project $project): array
    {
        $role = Auth::user()?->role;

        return $project->documents()
            ->with('documentType')
            ->get()
            ->filter(fn (Document $d) => in_array($this->extension($d), self::EXTENSIONS, true))
            ->map(function (Document $d) use ($role) {
                $ext = $this->extension($d);
                $typeName = $d->documentType?->name;
                $isSchedule = strcasecmp((string) $typeName, 'Schedule') === 0;
                $nameHint = (bool) preg_match(self::NAME_HINT, (string) $d->document_name);

                return [
                    'id'               => $d->id,
                    'name'             => $d->document_name,
                    'ext'              => $ext,
                    'type_name'        => $typeName,
                    'uploaded_at'      => $d->created_at?->format('M j, Y'),
                    'uploaded_ts'      => $d->created_at?->getTimestamp() ?? 0,
                    'download_url'     => $role ? route($role . '.documents.download', $d->id) : null,
                    'is_schedule_type' => $isSchedule,
                    'name_hint'        => $nameHint,
                    'score'            => ($isSchedule ? 4 : 0) + ($nameHint ? 2 : 0) + (in_array($ext, ['xls', 'xlsx'], true) ? 1 : 0),
                ];
            })
            ->sort(fn ($a, $b) => [$b['score'], $b['uploaded_ts'], $b['id']] <=> [$a['score'], $a['uploaded_ts'], $a['id']])
            ->values()
            ->all();
    }

    /**
     * Lower-case extension from the stored file (falls back to the name).
     */
    private function extension(Document $document): string
    {
        $source = $document->file_path ?: $document->document_name;

        return strtolower(pathinfo((string) $source, PATHINFO_EXTENSION));
    }
}
