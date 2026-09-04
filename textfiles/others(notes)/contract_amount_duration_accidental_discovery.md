# Accidental Discovery: Contract Amount vs Duration

## Status

LOW PRIORITY / FUTURE ENHANCEMENT

This document records an accidental discovery made while working on PDF contract amount extraction.

The discovery is potentially useful for:

- historical validation
- anomaly detection
- project benchmarking
- historical similarity
- future duration estimation

However, it should NOT currently be used as a hard rule for selecting the correct contract amount.

---

# 1. Original Dataset

There are 37 historical projects.

The contract amounts below are expressed in ₱ thousands (`k`), followed by project duration in days.

    337 k / 22
    185 k / 30
    1537 k / 61
    603 k / 67
    314 k / 21
    1762 k / 80
    102 k / 15
    1132 k / 60
    6739 k / 130
    125 k / 15
    910 k / 52
    1034 k / 81
    4204 k / 185
    93 k / 15
    25 k / 5
    719 k / 36
    269 k / 23
    391 k / 42
    232 k / 20
    98 k / 21
    719 k / 42
    70 k / 10
    1434 k / 83
    141 k / 10
    783 k / 90
    2187 k / 180
    3363 k / 201
    69 k / 9
    414 k / 38
    191 k / 30
    500 k / 60
    572 k / 56
    43500 k / 390
    6495 k / 90
    1183 k / 55
    3257 k / 88
    6435 k / 270

---

# 2. Initial Observation

There is a clear positive relationship:

    Longer project duration
            ↓
    Generally larger contract amount

However, the relationship is not deterministic.

Projects with similar durations can have substantially different contract amounts.

Therefore, the dataset is better suited to:

    historical plausibility / benchmarking

than:

    exact contract amount prediction

---

# 3. Duration-Band Analysis

The dataset was grouped into practical duration bands.

| Duration | Projects | Average Contract | Highest Contract | Median Contract |
|---|---:|---:|---:|---:|
| ≤10 days | 4 | ₱76.3k | ₱141k | ₱69.5k |
| 11–15 days | 3 | ₱106.7k | ₱125k | ₱102k |
| 16–30 days | 7 | ₱232.3k | ₱337k | ₱232k |
| 31–45 days | 4 | ₱560.8k | ₱719k | ₱566.5k |
| 46–60 days | 5 | ₱859.4k | ₱1,183k | ₱910k |
| 61–75 days | 2 | ₱1,070k | ₱1,537k | ₱1,070k |
| 76–90 days | 6 | ₱2,460.8k | ₱6,495k | ₱1,598k |
| 91–120 days | 0 | — | — | — |
| 121–180 days | 2 | ₱4,463k | ₱6,739k | ₱4,463k |
| 181+ days | 4 | ₱14,375.5k | ₱43,500k | ₱5,319.5k |

---

# 4. Why Median Is Important

Large projects create strong outlier effects.

Examples include:

    ₱6.495M / 90 days
    ₱43.5M / 390 days

These values can significantly increase the average.

Therefore, for future historical benchmarking:

    Median

may be more representative than:

    Average

especially when the duration group contains large outliers.

---

# 5. Suggested Duration Categories

A simple future benchmark could use:

| Category | Duration |
|---|---:|
| Very short | ≤10 days |
| Half month | 11–15 days |
| ~1 month | 16–30 days |
| 1–1.5 months | 31–45 days |
| 1.5–2 months | 46–60 days |
| 2–2.5 months | 61–75 days |
| 2.5–3 months | 76–90 days |
| 3–4 months | 91–120 days |
| 4–6 months | 121–180 days |
| 6+ months | 181+ days |

There are currently no projects in the 91–120 day group.

---

# 6. Contract Amount Per Day

Another calculation was:

    Contract Amount / Duration

Examples:

| Contract | Duration | Approx. Contract Amount per Day |
|---:|---:|---:|
| ₱337k | 22 | ₱15.3k/day |
| ₱1,537k | 61 | ₱25.2k/day |
| ₱1,762k | 80 | ₱22.0k/day |
| ₱6,739k | 130 | ₱51.8k/day |
| ₱4,204k | 185 | ₱22.7k/day |
| ₱3,363k | 201 | ₱16.7k/day |
| ₱43,500k | 390 | ₱111.5k/day |

The variation is large.

Therefore:

    Contract Amount / Duration

should NOT be treated as a universal standard daily project value.

---

# 7. Approximate Mathematical Relationship

A power-type relationship was examined between contract amount and duration.

Approximate relationship:

    Contract Amount ≈ 2.31 × Duration^1.47

Approximate coefficient of determination:

    R² ≈ 0.889

This indicates a strong positive relationship within this small dataset.

The correct interpretation is:

    Projects with longer durations in this dataset
    tend to have substantially larger contract amounts.

It does NOT mean:

    Duration alone can accurately determine
    the correct contract amount.

---

# 8. Potential Use Case #1 — PDF Extraction Plausibility

This is the most directly useful potential application.

Suppose OCR extracts:

    Duration = 90 days
    Contract Amount = ₱92,025

Historical data can identify ₱92,025 as unusually small for projects around that duration.

If another candidate exists:

    ₱6,495,049.29

the historical signal can support the second candidate.

However:

    Historical plausibility must NOT override
    strong direct document evidence.

For example, if the document explicitly says:

    Original Contract Amount: ₱92,025

then the document's explicit label should be stronger than the historical benchmark.

---

# 9. Potential Use Case #2 — Extraction Anomaly Detection

If there is only one extracted candidate:

    Contract Amount = ₱92,025
    Duration = 90 days

the parser could produce:

    WARNING:
    The extracted contract amount is unusual
    compared with historical projects of similar duration.
    Please verify.

This is useful because OCR can make mistakes even when there is only one candidate.

The feature should identify:

    "unusual"

rather than claim:

    "incorrect"

---

# 10. Potential Use Case #3 — Historical Project Benchmark

The system could eventually show historical context for a project.

Example:

    Current project:
    Contract Amount = ₱1.5M
    Duration = 60 days

The system could compare it with historical projects around the same duration.

Possible output:

    "Compared with historical projects with similar durations,
     this project is relatively large."

This would be a benchmark rather than a prediction.

---

# 11. Potential Use Case #4 — Historical Similarity

This dataset could become one feature of a broader historical similarity system.

Example:

    Current project:
        Contract amount = ₱1.5M
        Duration = 60 days

Find historical projects with:

    - similar contract amount
    - similar duration
    - similar project type
    - similar scope
    - other available project characteristics

This is likely more valuable than using the contract/duration relationship by itself.

---

# 12. Potential Use Case #5 — Future Duration Estimation

With a much larger and better-labeled historical dataset, the relationship could eventually contribute to:

    Estimated project duration

using:

    Contract amount
    +
    project type
    +
    project characteristics
    +
    historical similar projects

The current 37-project dataset should not be treated as enough for a production-grade duration predictor.

---

# 13. Potential Use Case #6 — Data Quality Checking

The relationship could also be used to flag unusual records.

Examples:

    Very small contract
    +
    extremely long duration

or:

    Very large contract
    +
    extremely short duration

These should be treated as:

    "worth reviewing"

not:

    "definitely incorrect"

Construction projects can legitimately have unusual cost-duration combinations.

---

# 14. Potential Use Case #7 — Confidence Adjustment

The dataset could eventually contribute to the parser's confidence score.

Example:

    Candidate A:
        ₱92,025
        Duration = 90 days
        Historical plausibility = LOW

    Candidate B:
        ₱6,495,049.29
        Duration = 90 days
        Historical plausibility = HIGH

The historical signal can influence the final confidence.

But it should remain a secondary signal.

A useful principle is:

    Document evidence > Historical evidence

---

# 15. What This Data Should NOT Be Used For

Do not currently use it as:

    "90 days = approximately ₱X"

Do not use it as:

    "The larger amount must be correct."

Do not use it to automatically overwrite a clearly labeled document value.

Do not describe the relationship as a universal construction pricing rule.

---

# 16. Current Recommendation

## Priority: LOW

My honest recommendation is:

    Do NOT prioritize building a standalone feature
    around this dataset right now.

The current PDF extraction problem has a much more direct impact on the system.

The current priority should remain:

    1. Candidate extraction
    2. Source inspection
    3. Label/context scoring
    4. Duplicate handling
    5. Confidence-based autofill
    6. User confirmation for ambiguous cases

Only after those are stable should historical contract/duration validation be added.

---

# 17. Recommended Future Priority

Once the parser is reliable:

## First future feature

Add:

    Historical Plausibility Check

Use it as a secondary confidence signal.

This is relatively small and potentially useful.

## Later feature

Add:

    Historical Project Benchmark

Allow users to compare a project against previous projects.

## Even later

Add:

    Historical Similarity / Duration Estimation

Only when there is enough historical data and enough project attributes to make the comparison meaningful.

---

# 18. Final Architecture

Eventually, the contract amount parser could work like this:

                    Candidate Contract Amount
                              ↓
             ┌────────────────────────────────┐
             │                                │
             ↓                                ↓
       Document Evidence                Historical Evidence
             │                                │
       ├─ Label strength               ├─ Duration relationship
       ├─ Source context               ├─ Historical range
       ├─ Page/location                └─ Similar projects
       ├─ Number structure
       └─ Frequency
             │                                │
             └──────────────┬─────────────────┘
                            ↓
                    Confidence / Validation
                            ↓
                  ┌─────────┼─────────┐
                  ↓         ↓         ↓
                HIGH      MEDIUM      LOW
                  ↓         ↓         ↓
              Autofill   Warning    Ask user

The historical dataset is therefore best treated as:

    supporting evidence

rather than:

    authoritative evidence.

---

# 19. Bottom Line

The accidental discovery is useful because it gives the parser another question to ask:

    "Does this extracted number make sense
     given the detected project duration?"

It should NOT be used to ask:

    "What should the contract amount be?"

The safest future approach is:

    Document evidence first
        ↓
    Historical plausibility second
        ↓
    Confidence score
        ↓
    Automatic fill / warning / user confirmation

This keeps the discovery useful without allowing a small historical dataset to make potentially dangerous assumptions about the actual document.