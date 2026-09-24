# BUDGET PRECEDENCE (58.3) AND AI SOURCE VALIDATION (58.4)
# Parent ref: textfiles/normal_doc_parser_restudy_plan.md §58.3, §58.3-V, §58.4
# Date: September 24, 2026
# Status: ⬜ PLANNED, NOTHING BUILT. Five decisions are owed from the user (§4).
# Branch: not cut yet. Suggested phase-47-budget-precedence (item A) and
#         phase-48-ai-source-shape (item B) — see §5 for why they are separate.

===============================================================================
  §0 — WHAT THIS IS
===============================================================================

  Two items from the restudy's next-work list, planned together because they were
  ordered together, NOT because they are related. They touch different files, need
  different proof, and carry very different risk:

    ITEM A (58.3) — budget precedence.   HIGH RISK. Changes which value fills a
                    money box on every multi-amount document in the system.
    ITEM B (58.4) — AI source shape.     LOW RISK. Closes a validation gap on one
                    field, on one path, and cannot change the rule reader at all.

  ⚠ THEY SHOULD NOT SHIP AS ONE COMMIT. If A moves a figure on a real document,
  nobody should have to work out whether B was involved.

===============================================================================
  §1 — ITEM A (58.3): DECISION 11 AS PRECEDENCE
===============================================================================

  1.1 THE ESTABLISHED FACTS (do not re-derive — §58.3-V measured these live)

      User decision 11 says the REVISED contract amount wins over the ORIGINAL.
      The pooled vote that actually fills the box does not implement it:

        #  value         count  score   patterns
        0  4204128.84      4    2.35    generic, paren-php, bare-php  <- FILLS BOX
        3  4323196.00      1    1.00    revised, generic
        4  4323196.77      1    1.00    revised, generic, bare-php

      · The revised figure is PRESENT and OUTRANKED, not absent. The item is real.
      · It is SPLIT ACROSS TWO ROWS — same pesos, different cents — so its evidence
        is halved before it competes.
      · A SCORE BUMP CANNOT FIX IT. Merge both revised rows and the score is 2.00,
        still under 2.35. Winning by weight needs more than +1.35 on a base of 1.00,
        a multiplier large enough to distort every other document.
      ➡ Therefore: PRECEDENCE, not weight.

  1.2 WHERE THE CODE IS

      public/js/admin/projects/document-parser-core.js
        collectBudgetCandidates()  :1378  — emits { value, labeled, weight, fileName,
                                            pattern, excerpt, line }. `pattern` is the
                                            named rule that matched; this is the field
                                            precedence keys on.
        voteCandidatePool()        :1613  — groups by normalized value, sums weight,
                                            highest score wins. Tie-break order today:
                                            score, then labeledCount, then digit length.
        voteCandidatePoolDebug()   :1638  — same maths plus the inspector table, and
                                            the same-line dedupe. MUST STAY IN STEP
                                            WITH voteCandidatePool or the inspector
                                            stops explaining the box.

      ⚠ voteCandidatePool IS GENERIC. It votes the ABC pool too (collectAbcCandidates,
      :1483). Budget precedence must NOT be hardcoded inside it, or it silently
      changes the ABC read on every document — and §58.3-V(f) warns that the two
      pools overlap heavily on the very document this item is about.

  1.3 ⚠ THE DESIGN TRAP NOBODY HAS NAMED YET — "REVISED WINS" IS NOT ENOUGH

      collectBudgetCandidates emits SIX labeled patterns, not two:

        total-row        "TOTAL CONTRACT AMOUNT ..."
        revised          "Revised Contract Amount ..."
        final-anchor     "the final contract amount is ..."
        discount-anchor  "after applying the 3% discount ... contract amount is ..."
        amount-is        "contract amount is ..."
        generic          "Original Contract Amount | Contract Amount | Contract Price
                          | Project Cost | Total Cost | Budget ..."

      Decision 11 is about revised-vs-ORIGINAL. But `original` is not its own pattern —
      it is folded into `generic`. And two patterns are arguably MORE final than
      revised: `final-anchor` and `discount-anchor`. The discount anchor is the BUHI
      family's 3%-discount figure, which is the contract actually signed.

      ⚠ CORRECTED 2026-09-24 AFTER TRACING WHAT THOSE TWO PATTERNS ACTUALLY SERVE.
      The above reads as if "final" meant "after any later revision". IT DOES NOT.
      Both anchors were written for ONE real document — the BUHI contract (Completion
      of Three-Storey Academic Building, Buhi Campus). That document states:

          original bid                  Php 33,596,424.16
          after applying the 3% discount Php 32,588,531.44   <- the Contract Price

      So "final Contract Amount is" there means FINAL AFTER THE BID DISCOUNT, i.e.
      the AWARD-TIME price on the signed contract. It is not a post-variation figure.
      (accuracy plan :92, :367 — the anchors exist because asMoney was grabbing 44.00
      out of "PESOS & 44/100", not because of any revision.)

      ➡ CONSEQUENCE: a REVISED amount is a LATER fact than either anchor, so revised
        belongs ABOVE both — the opposite of this section's first draft. The residual
        risk is only nominal: the phrase "the final contract amount is" COULD, in some
        document nobody has, mean post-variation. No such specimen exists, and naming
        a pattern "final" is not evidence about the world.

  1.4 ⚠ THE SECOND TRAP — PRECEDENCE PROMOTES A TIE, AND THE TIE-BREAK IS BLIND

      Filter the pool to revised candidates and the two rows tie at score 1.00.
      Today's tie-break then runs: labeledCount (both 1), then digit length —

        4323196.00 -> digits "432319600" -> 9
        4323196.77 -> digits "432319677" -> 9

      IDENTICAL. So the winner falls to Map insertion order, which is document order,
      which is an accident. §58.3-V(d) is precise about the cost: promote the wrong
      one and the box fills with a value wrong by 77 centavos that LOOKS authoritative
      — worse than today's failure, because today's wrong value is visibly different.

      PROPOSED TIE-BREAK (needs the user's agreement, §4 D-A2):
        When two candidates in the same pool share an identical INTEGER peso part and
        one ends .00 while the other has non-zero cents, prefer the NON-ZERO one.
        RATIONALE: truncation loses digits, it does not invent them. ".77" is evidence
        of a real reading; ".00" is what a lost-cents read produces. Scoped to the
        same-pesos collision only, so an honestly round amount elsewhere is untouched.

  1.5 THE BUILD, IF ORDERED

      Step A1  Add a tier rank to the budget candidates only.
               A small pure helper in core, e.g. budgetPatternTier(pattern) -> int,
               with the order the user chooses in D-A1. Unlabeled fallbacks
               (paren-php, net-total, p-prefix, bare-php) sit in the lowest tier.
      Step A2  Give voteCandidatePool an OPTIONAL tier accessor so the budget caller
               opts in and the ABC caller is untouched by construction:
                 voteCandidatePool(cands, { tierOf })
               Vote only within the highest non-empty tier. Default stays today's
               behaviour exactly when no tierOf is passed.
      Step A3  Mirror it in voteCandidatePoolDebug, and show the tier in the inspector
               row so _parserVoteTables('budget') explains the new winner.
      Step A4  The cents tie-break (D-A2), applied inside the vote's tie resolution.
      Step A5  Fixtures: the §58.3-V table as a literal fixture; a revised-vs-final
               document; a revised-vs-original document; the cents collision; and an
               ABC pool fixture proving the ABC read did NOT move.

  1.6 PROOF, AND THE HONEST LIMIT ON IT

      ✅ The JS suite already covers this path — collectBudgetCandidates and the
         pooled budget have real line-array fixtures (document-parser-core.test.mjs
         has several asserting pooled.budget === '6495049.29'). Gross regressions
         would be caught.
      ⚠ BUT THE SUITE IS SYNTHETIC LINES, NOT THE CORPUS. §58.3 requires re-measuring
         real multi-amount documents, and THERE IS NO OFFLINE HARNESS FOR THAT. The
         §58.3-V table came from the user running window._parserVoteTables('budget')
         in the browser. Nothing on disk stores the read lines of a normal document —
         C:\temp\opencode has Excel/PDF S-curve corpora only.
      ➡ So real-document proof costs either (a) browser runs by the user on each
         multi-amount document, or (b) building a line-capture step first. That is
         decision D-A3, and it should be settled BEFORE the build, not after.

  1.7 MUTATIONS TO RUN (each must go red)
        M-A1  tierOf ignored (fall back to plain score)     -> revised tests red
        M-A2  tier order flattened to one tier              -> revised tests red
        M-A3  cents tie-break removed                       -> collision test red
        M-A4  tier applied to the ABC pool too              -> ABC no-move test red

===============================================================================
  §2 — ITEM B (58.4): THE AI's budgetSource BYPASSES THE SHAPE VALIDATOR
===============================================================================

  2.1 THE FACTS

      Two paths can fill budgetSource, and they are validated very differently.

      RULE PATH — document-parser-core.js :1065 passes the value through
      asSourceName() (:360), which enforces SEVEN rules:
        length 2..60 · name punctuation only (no '=', '|', '_', '*', '<', '>', '#',
        '@', '"', ':') · opens on letter/digit/bracket and closes on
        letter/digit/bracket/'.'/'%' · at least two consecutive letters · at most six
        words · does not open on a stop word · under 25% punctuation.
      Its own comment: "An accepted misread invites a junk row nobody unmakes."

      AI PATH — GeminiDocumentParserService::validateParsed() :2180 applies
      normalizeText($v, 2, 100), and normalizeText (:2202) does exactly one thing:
      trim, then check mb_strlen is within bounds. NO SHAPE TEST AT ALL.

      ⚠ NOTE THE LENGTH DISAGREEMENT TOO: the rule path caps at 60, the AI path at
      100. So the AI may also submit a value 40 characters longer than the rule
      reader would ever accept.

  2.2 ⚠ THE HONEST LIMIT — THIS IS NOT A CURE FOR §56.1

      It would NOT have prevented the defect that made the gap visible.
      "Camarines Sur Polytechnic Colleges" passes asSourceName cleanly: 34 characters,
      four words, no debris punctuation. This item closes an inconsistency on
      principle. It should be sold as that and nothing more.

  2.3 WHERE TO PUT THE CHECK — TWO OPTIONS, AND A RECOMMENDATION

      OPTION 1 — PHP, in validateParsed().
        + Server-side, so any future caller of the endpoint inherits it.
        − Requires a PHP PORT of asSourceName, i.e. the same rule in two languages.
          The project already carries one such pair (rowSignature, pinned by
          tests/fixtures/scurve_row_signature_cases.json read by BOTH suites), so the
          cost is known and it is not small: seven rules that must never drift.

      OPTION 2 — the client merge, through core.  ⭐ RECOMMENDED
        + ONE implementation. asSourceName already exists and is already exported
          from core (:2343), so there is nothing to port and nothing to keep in sync.
        + Core IS reached by the JS suite, so it is provable.
        + It puts BOTH paths through the IDENTICAL rule at the IDENTICAL place, which
          is literally the goal — the item is about the two paths disagreeing.
        − Not a server-side guarantee. Acceptable here: the server stores nothing,
          the value goes to a preview for a human to approve, and the permanent junk
          record the comment warns about is created at Apply, which is client-side.

  2.4 ⚠ THE REAL QUESTION IS NOT WHERE — IT IS WHAT HAPPENS TO A REFUSED VALUE

      Three behaviours, and this needs the user (D-B2):
        (i)   Drop it silently. Simple, and loses the fact that the AI said something.
        (ii)  Drop it from the field but NOTE what the AI read. This is the Q3
              contractor pattern already in the system: a value that cannot be
              trusted into a field gets "a loud note naming what the document said".
        (iii) Show it in the field, flagged. ⚠ This resembles decision D-44-2
              ("an unmatched Category / Budget Source is shown, flagged") BUT D-44-2
              is about a value that is well-formed and merely not in the database.
              A value that FAILS THE SHAPE TEST is debris, and showing debris in a
              field invites someone to accept it. Do not read D-44-2 as covering this.

      RECOMMENDED: (ii).

  2.5 THE BUILD, IF ORDERED
      Step B1  Apply asSourceName to the AI's budgetSource at the merge point, via
               core so the suite reaches it.
      Step B2  The refused-value behaviour chosen in D-B2.
      Step B3  Fixtures: a clean AI source passes untouched; a 100-character clause is
               refused; a pipe-bearing table row is refused; a stop-word opener is
               refused; and the rule path is unchanged.
      Step B4  OPTIONAL, and worth raising separately: align the PHP length cap from
               100 to 60 so the two paths at least agree on length even if the shape
               test stays client-side. One-line change, own test.

  2.6 MUTATIONS
        M-B1  the shape call removed        -> the debris fixtures go green (red test)
        M-B2  refusal made silent           -> the note fixture red (if D-B2 = ii)

===============================================================================
  §3 — WHAT THIS PLAN DOES NOT TOUCH
===============================================================================

  · §58.1 / the OCR pipe — ON HOLD by decision D-44-5. Not in scope, and §58.3-V(f)
    warns the ABC pool overlaps this document; item A must show the ABC did not move.
  · §58.2 cross-field veto — a separate item, not planned here.
  · The slice-cap question (§45.4) — user-owned, unrelated.

===============================================================================
  §4 — DECISIONS OWED FROM THE USER (nothing is built until these are answered)
===============================================================================

  D-A1  PRECEDENCE ORDER for the budget pool. REVISED, highest first (see the
        correction in §1.3 — the anchors carry an AWARD-TIME figure, not a later one):
          1. revised                         (a later fact than any award-time price)
          2. final-anchor, discount-anchor   (the signed price, after a bid discount)
          3. total-row, amount-is, generic   (generic contains "original")
          4. unlabeled fallbacks
        ⚠ USER'S FRAMING, 2026-09-24: "the revised amount will only be important for a
        finished project right?" — ANSWER: not only finished. A contract amount is
        revised by a VARIATION / CHANGE ORDER during implementation, so an ONGOING
        project can carry one. What IS true is the useful half of the instinct: a
        document stating BOTH figures is a LATER-STAGE document (variation order,
        revised NOA, completion report, final billing), and at project creation you
        would normally hold only the original. The most likely way the PMU meets both
        is BACK-ENCODING an existing project into the system during the pilot — and
        there the revised figure is right whether the project is finished or running.
        ➡ So the trigger is "a revision exists", not "the project is finished", and
          nothing in this item should key on project status. (The parser fills the
          create-project form and has no project status to key on anyway.)
        ⓘ NOTED, OUT OF SCOPE: Project has ONE money field per concept
          (approved_budget, abc) — no original/revised pair — so the system cannot
          record both even if the document states both. Picking one is forced. Whether
          a contract-revision history is worth having is a separate product question.

  D-A2  THE CENTS TIE-BREAK. Same pesos, one ends .00, the other .77 — prefer the
        non-zero cents? (§1.4)

  D-A3  HOW ITEM A GETS REAL-DOCUMENT PROOF: browser runs by you on each multi-amount
        document, or build a line-capture harness first? (§1.6)

  D-B1  WHERE the shape check goes — client merge via core (recommended), or a PHP
        port in validateParsed? (§2.3)

  D-B2  WHAT HAPPENS to a refused AI source — silent drop, or drop with a note naming
        what the AI read (recommended)? (§2.4)

===============================================================================
  §5 — SUGGESTED BUILD ORDER
===============================================================================

  ITEM B FIRST, then item A. Three reasons:
    1. B is low risk and self-contained; A can move a number on a real document.
    2. B needs no corpus measurement, so it cannot be blocked by D-A3.
    3. A's proof depends on a decision (D-A3) that may itself require building a
       capture harness — so starting with A risks stalling on tooling.

  Separate branches, phase-48-ai-source-shape then phase-47-budget-precedence, or
  renumber to taste — the names matter less than not mixing them in one commit (§0).

===============================================================================
  END OF PLAN
===============================================================================
