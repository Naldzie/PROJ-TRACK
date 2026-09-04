# PDF Parser Contract Amount Resolution Plan

## Purpose

This document records the current problem with contract amount extraction and the recommended next steps.

The immediate problem is NOT to predict a contract amount.

The immediate problem is to make the PDF autofill system reliably choose the correct contract amount when a scanned/multi-document PDF contains several monetary values.

---

# 1. Current Parser Behavior

The current parser:

1. Searches the PDF for possible values for a field.
2. Normalizes the detected values.
3. Counts how many times each value appears.
4. Uses the most frequently detected value as the winner.
5. Uses similar candidate-ranking logic for other extracted fields.

This worked in simpler documents but can fail when a document contains multiple legitimate monetary values.

---

# 2. Current Failure Case

During testing, the parser produced:

    [Parser] field: budget | winner: 92025.00 | finds: 3

Candidate table:

| Value | Normalized | Count | Score | Labeled Count |
|---|---|---:|---:|---:|
| 92025.00 | 92025.00 | 2 | 2 | 2 |
| 5049.29 | 5049.29 | 1 | 1 | 1 |
| 6495049.29 | 6495049.29 | 1 | 1 | 1 |

The actual contract amount is:

    ₱6,495,049.29

The parser incorrectly selected:

    ₱92,025

because it appeared twice.

This demonstrates that frequency alone is not sufficient evidence.

---

# 3. Current Plan From the Other Model

## Step 1 — Sharpen the Inspector First

Before changing the selection rule, improve the debug/inspection output.

Each candidate should show:

- extracted value
- normalized value
- count
- score
- labeled count
- source file
- page number if available
- short source snippet
- matched label/pattern
- preferably nearby lines above and below the matched value

Example:

    Candidate: ₱92,025
    Count: 2
    Matched label: [pattern]
    Source:
        ...
        Contract ...
        ₱92,025
        ...

Another candidate:

    Candidate: ₱6,495,049.29
    Count: 1
    Matched label: Original Contract Amount
    Source:
        Original Contract Time: 90 Calendar days
        Original Contract Amount: Php 6,495,049.29

The goal is to determine where each value actually came from before modifying the selection rule.

This is especially important because the test PDF is a scanned PDF containing multiple pieces of documentation. OCR may detect the same number multiple times without those detections being independent confirmation.

---

# 4. Determine Why ₱92,025 Appears Twice

There are two main possibilities.

## Case A — The occurrences are duplicates

If the two occurrences of ₱92,025 come from the same or nearly identical source line, repeated OCR should not allow the duplicate to overpower the real contract total.

Possible solution:

- Detect duplicate/near-duplicate source lines.
- Count duplicate occurrences only once.
- Avoid treating repeated OCR of the same document content as independent evidence.

The exact deduplication method should be determined after inspecting the debug output.

## Case B — They are genuinely different labeled values

If ₱92,025 appears in two legitimate locations, frequency can remain useful, but label/context strength should outweigh frequency when the labels indicate different meanings.

For example:

    Original Contract Amount: ₱6,495,049.29

should have much stronger evidence for the `budget` field than:

    Amount: ₱92,025

if the latter is associated with a partial amount, component, payment, deduction, etc.

---

# 5. Recommended Long-Term Change

Do NOT permanently use:

    Most frequent candidate wins.

Instead use:

    Candidate with the strongest combined evidence wins.

Frequency should remain one signal, rather than being the deciding signal.

---

# 6. Recommended Evidence Hierarchy

For the `budget` / contract amount field, consider the following signals.

## Tier 1 — Explicit field label/context

Strongest evidence.

Examples:

- Original Contract Amount
- Contract Amount
- Original Contract Price
- Contract Price
- Approved Contract Amount
- Contract Sum
- ABC

## Tier 2 — Source context

Consider:

- nearby words
- nearby lines
- page/document location
- proximity to related fields
- whether the value is inside a project information form

## Tier 3 — Monetary structure

A complete monetary figure can be more meaningful than a smaller component.

For example:

    ₱6,495,049.29

is structurally a complete contract-sized monetary value.

But this should only be a supporting signal.

Do NOT assume that the largest monetary value is automatically the contract amount.

## Tier 4 — Frequency

Keep the existing frequency mechanism.

Repeated values are useful evidence, but repetition alone should not defeat stronger contextual evidence.

## Tier 5 — Historical plausibility

Use historical contract amount vs duration as a secondary validation signal.

This is described in the separate document:

    contract_amount_duration_accidental_discovery.md

## Tier 6 — User confirmation

If evidence remains ambiguous, ask the user.

---

# 7. Recommended Automatic vs Manual Logic

The existing Choice A / Choice B concept can be retained, but made confidence-based.

## Choice A — Automatic

Automatically select a candidate when the evidence is clearly stronger.

Example:

    ₱6,495,049.29
    - explicit contract amount label
    - strong document context
    - complete monetary value
    - plausible for the detected duration

versus:

    ₱92,025
    - repeated twice
    - weaker/unknown context
    - historically unusual for the detected duration

Result:

    Automatically select ₱6,495,049.29

## Choice B — Ask the User

If two candidates have similarly strong evidence:

    Contract Amount: ₱500,000
    Contract Amount: ₱650,000

then ask:

    "I found two possible contract amounts.
     Please select the correct one."

This is safer than silently guessing.

## Warning Case

If there is only one candidate but it appears suspicious:

    Contract Amount: ₱92,025
    Duration: 90 days

the system could still autofill it but show:

    "Please verify: the extracted contract amount is unusual
     for the detected project duration."

---

# 8. Proposed Parser Pipeline

    PDF
      ↓
    Normal PDF extraction
      ↓
    OCR if scanned
      ↓
    Extract candidate values
      ↓
    Collect evidence for every candidate
        ├─ matched label
        ├─ source snippet
        ├─ page/document location
        ├─ frequency
        └─ related-field context
      ↓
    Candidate scoring
        ├─ label/context strength
        ├─ monetary structure
        ├─ frequency
        └─ historical plausibility
      ↓
    Confidence
        ├─ HIGH
        │   → automatic autofill
        │
        ├─ MEDIUM
        │   → autofill + verification warning
        │
        └─ LOW
            → ask user to choose
      ↓
    AI API / Gemini
    only as a last resort

This preserves the current architecture:

    Normal PDF parser
        ↓
    OCR for scanned documents
        ↓
    AI API only when normal extraction/OCR
    produces bad, inaccurate, or ambiguous data

The historical contract amount/duration relationship belongs in the validation/scoring layer, not the AI layer.

---

# 9. Why "Bigger Amount Wins" Should Not Be the Main Rule

A possible rule is:

    Among properly labeled amounts,
    the fuller/larger figure wins.

This could solve the current example, but it is too broad as a permanent rule.

A legitimate project could have a small contract amount.

A document can also contain:

- total contract amount
- revised contract amount
- variation order amount
- approved additional amount
- retention
- tax
- deduction
- partial payment
- material amount
- subtotal

Therefore:

    Bigger number ≠ automatically correct answer

The amount-size heuristic should only support other evidence.

---

# 10. Recommended Immediate Priority

Do not implement the historical amount/duration rule yet.

Recommended order:

1. Sharpen the inspector.
2. Inspect the source of ₱92,025.
3. Inspect the source of ₱6,495,049.29.
4. Determine whether the duplicate ₱92,025 detections are actually duplicates.
5. Improve label/context scoring.
6. Improve duplicate handling if required.
7. Implement confidence-based automatic vs manual selection.
8. Add historical amount/duration plausibility as a secondary validation signal.
9. Keep Gemini as a last resort.

---

# 11. Important Design Principle

The parser should not ask:

    "Which number appeared the most?"

It should ask:

    "Which candidate has the strongest evidence
     that it represents the requested field?"

This is the main conceptual improvement.