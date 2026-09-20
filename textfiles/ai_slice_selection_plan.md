# AI SLICE SELECTION — WHICH PAGES LEAVE THE BUILDING, AND WHO DECIDES
# Parent refs: textfiles/normal_doc_parser_restudy_plan.md (§45.4 raised it, §46.6 named
#                the page that makes it concrete, §48.3 + §49.9 fixed the reason string
#                and confirmed the ranking's behaviour on a real file)
#              textfiles/document_parser_ai_fallback_plan.md (Decision F1 — opt-in, quota)
#              textfiles/pilot_testing_readiness_plan.md (§6 P5 — the AI tier's real
#                availability; §4 F2 — what must not leave a PMU desktop)
#              AGENTS.md future-queue item 8
# Date: September 20, 2026
# Status: 🔄 PLAN ONLY — READ-ONLY RESTUDY DONE, NOTHING BUILT, NOTHING ORDERED.
#         §2 carries EIGHT verified findings (A1-A8), all traced in source today.
#         §4 is the proposed build in four steps with a deliberate ORDER.
#         §5 carries the four questions the user owns. Rule 10: nothing proceeds
#         without an explicit order.
# Branch on build: phase-29-ai-slice-selection (Rule 12).

===============================================================================
  1. WHY THIS PLAN EXISTS
===============================================================================

  THE TRIGGER IS NO LONGER HYPOTHETICAL. On 2026-09-20 the user's own screenshot of
  the CHS pair showed the AI-assist row reading:

      ✦ AI assist would read:  Contract - CHS Savings.pdf p.4   Contract - CHS Savings.pdf p.7

  That contract is SEVEN pages, so p.7 is its last page — the ACKNOWLEDGMENT. Per
  restudy §46.6 it carries both parties' government ID numbers ("ID No. Admin 182",
  a CTC number), the notary's COMMISSION, ROLL NO., IBP #, MCLE # and PTR #, a street
  address, and both signatures.

  ⇒ SO AS THE SYSTEM STANDS TODAY, pressing "Try AI assist" on that pair sends a
    notarised page of personal identifiers to a third-party model. Nothing is
    malfunctioning: the ranking picked it because it is genuinely one of the sparsest
    pages in the set, which is true and is exactly what the ranking was asked to find.
    The defect is not the ranking. It is that NOBODY IS ASKED, and there is no way to
    say no to one page.

  THE USER'S OWN FRAMING (restudy §45.4, 2026-09-20): a reviewer-chosen COUNT,
  PER-FILE ALLOCATION, and FILE EXCLUSION. This plan takes that, checks it against the
  code, and finds that one of the three (allocation) is a live defect rather than a
  nicety, and that a fourth thing — INFORMED CONSENT — is missing and is cheaper than
  any of them.

  ⚠ WHAT THIS PLAN IS NOT. It does not decide whether page 7 may be sent. The user
  will ask the CLIENT that on pilot day (§46.6). This plan builds the machinery to
  honour whatever answer comes back; it does not pre-empt the answer.

===============================================================================
  2. WHAT IS TRUE TODAY — EIGHT FINDINGS, ALL TRACED IN SOURCE 2026-09-20
===============================================================================

  A1 — THE COUNT OF 2 IS A CLIENT CONSTANT, AND ONE SERVER SLOT IS UNUSED.
       public/js/admin/projects/document-parser.js:490   const AI_SLICE_PAGES = 2;
       app/Http/Controllers/DocumentParserController.php:128
           count($images) > 3  ->  'Provide 1 to 3 page images.'
           :32  MAX_SLICE_BYTES = 10485760   (10 MB per image)
           :35  MAX_TOTAL_BYTES = 31457280   (30 MB per request)
       The server accepts three; the client sends two. The count of 2 is justified
       NOWHERE in the record — Decision F1 in the AI fallback plan is about OPT-IN and
       QUOTA ("Clean files never call AI"), which argues for "only on request", not
       for "only two pages".

  A2 — ⚠ THE RANKING IS PER-FILE AND GREEDY, NOT GLOBAL. THE FIRST FILE EATS THE
       WHOLE BUDGET. This is the "per-file allocation" gap, and it is a real defect.
       ocrAiCandidates (:1560):
           for (let ri = 0; ri < reports.length && out.length < maxN; ri++) {
               ...
               for (let k = 0; k < ordered.length && out.length < maxN; k++) out.push(...)
           }
       Both loops are bounded by the SAME out.length < maxN. So file 1 takes as many
       slots as it wants before file 2 is looked at even once. With maxN = 2 and two
       files, THE SECOND FILE CAN NEVER BE REPRESENTED unless file 1 has fewer than
       two scanned pages.
       ⭐ CONFIRMED LIVE, not inferred: the gray note lists "Contract - CHS Savings.pdf"
         first and "CHS - Savings.pdf" second; the AI row picked p.4 and p.7 OF THE
         FIRST FILE, and the 12-page second file got nothing — no matter how weak its
         pages were. A reviewer uploading a contract plus a proposal gets an AI
         opinion informed by one of them.

  A3 — ⚠ THE CONSENT SURFACE AND THE DISCLOSURE SURFACE ARE DIFFERENT SCREENS.
       The button the reviewer actually presses is in the preview modal's amber banner
       (showAutofillPreview, :2699):
           "Parsing looks uncertain ... Try AI assist? Sends at most 2 confusing pages
            (never the whole file)."   [Try AI assist]
       IT NAMES NO PAGES. The list of what would be sent lives in #ocrPreviewAi
       (:1893), inside the "See & straighten" panel, which is opened from a separate
       control in the gray scan note. A reviewer who never opens that panel — which is
       the normal path — consents to "2 confusing pages" without being able to learn
       that one of them is the notarised ID page.
       ⇒ THIS IS THE CHEAPEST FIX IN THE PLAN AND PROBABLY THE MOST VALUABLE. It turns
         a blind consent into an informed one without changing what is sent at all.

  A4 — THE OVERRIDE EXISTS, BUT IT IS CAPPED BY THE SAME CONSTANT.
       _ocrAiTogglePage (:1755) seeds from the ranking, then:
           while (list.length > AI_SLICE_PAGES) list.shift();
       So a reviewer cannot choose three pages even though the server would accept
       three, and adding a third silently DROPS THE OLDEST CHOICE. The "+ this page /
       − this page" control is real and works; it just cannot express a count.

  A5 — THERE IS NO EXCLUSION CONCEPT ANYWHERE. window._ocrAiPick is opt-IN only. There
       is no way to say "never send this page" or "never send this file", and nothing
       in the ranking, the selection or the slicer can express a refusal. The only way
       to stop page 7 going today is to pick two other pages by hand FIRST — i.e. to
       know in advance that it was chosen.

  A6 — PICKS ARE VALIDATED AGAINST THE CURRENT REPORTS, AND EXCLUSION MUST BE TOO —
       BUT WITH THE OPPOSITE FAILURE MODE. ocrAiSelection (:1734) drops any pick whose
       file is gone or whose page is no longer in the scanned ledger, so a stale choice
       cannot smuggle in a page that no longer exists. That is the right behaviour for
       an opt-IN list: it fails CLOSED (sends less).
       ⚠ An exclusion list validated the same way would fail OPEN — drop the exclusion,
         send the page. An exclusion whose file cannot be matched must be KEPT, not
         discarded. Naming this now because it is exactly the kind of symmetry that
         looks like tidiness and is a privacy bug.

  A7 — ALL AI SELECTION STATE IS PER MODAL SESSION. cancelDocumentAutofill (:101) does
       window._ocrAiPick = null, alongside the rotations, the photo-read cache and the
       scan reports. So any exclusion built on the same stash dies when the create-
       project popup closes. That may be correct — see the open question in §5.

  A8 — "AT MOST 2" CAN SILENTLY BECOME 1, AND NOBODY IS TOLD. buildAiSlices (:2047)
       `continue`s past a candidate whose PDF will not open, whose page number is out
       of range, whose rendered viewport exceeds 4000 px, or whose canvas will not
       serialise. _tryAiAssist (:2319) only reacts when the result is EMPTY ("No page
       images available"). One-of-two goes with no message at all. Minor today,
       load-bearing the moment the reviewer is told a specific count and a specific
       page list — the promise has to match the payload.

===============================================================================
  3. WHAT THE PROBLEM ACTUALLY IS, STATED ONCE
===============================================================================

  Three separate things have been travelling under one heading, and they want
  different fixes:

    (i)   SAFETY      — some pages must never leave the desktop. Needs EXCLUSION.
                        Nothing exists today (A5).
    (ii)  HONESTY     — the person pressing the button cannot see what it sends.
                        Needs the banner to NAME the pages (A3). Nothing sent changes.
    (iii) QUALITY     — the AI is shown the wrong pages: one file monopolises the
                        budget (A2), and the budget is smaller than the server allows
                        for no recorded reason (A1, A4).

  ⚠ AND THE ORDER MATTERS FOR A REASON THAT IS NOT AESTHETIC. (iii) RAISES THE VOLUME
    OF WHAT IS SENT. Doing it before (i) and (ii) means shipping a change that sends
    MORE pages to a third party while the reviewer still cannot see or refuse them.
    Controls first, then volume.

===============================================================================
  4. THE PROPOSED BUILD — FOUR STEPS, IN THIS ORDER
     ⬜ NONE ORDERED. Each needs an explicit go (Rule 10).
===============================================================================

  ── S-1. THE BANNER NAMES WHAT IT WOULD SEND ───────────────────────────────  ⬜
     THE SMALLEST CHANGE AND THE BIGGEST HONESTY GAIN. Fixes A3.
     The amber "Try AI assist" banner (:2699) currently promises a COUNT. It should
     state the FILES AND PAGES, computed from the same ocrAiSelection() the slicer
     will use — so what is promised and what is sent cannot drift (the property
     buildAiSlices was already given in 2026-09-14).
       "Try AI assist? Would send 2 pages: Contract - CHS Savings.pdf p.4 and p.7.
        Nothing else leaves this computer.  [See or change these]  [Try AI assist]"
     "See or change these" opens the existing preview panel at the first chosen page,
     which is where the +/− control already lives. No new selection UI is needed for
     this step.
     RISK: the banner is rendered inside showAutofillPreview before the panel exists;
     ocrAiSelection reads window stashes only, so it is callable there — VERIFY, do
     not assume.

  ── S-2. EXCLUSION — "NEVER SEND THIS" ─────────────────────────────────────  ⬜
     THE PROTECTION THE USER ASKED FOR. Fixes A5.
     NEW STASH: window._ocrAiExclude = { pages: [{fileName, pageNum}], files: [fileName] }
     HONOURED IN TWO PLACES, NOT ONE:
       · ocrAiCandidates — an excluded page is never ranked, so it cannot be suggested;
       · ocrAiSelection  — an excluded page is stripped from the REVIEWER'S OWN picks
                           too, so a stale pick made before the exclusion cannot carry
                           it through. Belt and braces, because these are the pages
                           where a second path is worth the duplication.
     ⚠ FAILS CLOSED (A6): an exclusion whose file cannot be matched to a current report
       is KEPT and still filters by name. The opposite is a privacy bug wearing the
       costume of consistency.
     UI: the preview panel's AI row grows a third control beside "+ this page /
     − this page" — "⃠ never send this page", and a per-file "⃠ never send this file"
     next to the file's name in the gray note. An excluded page shows a distinct mark
     in the page strip (not the ✦), so the state is visible without hunting.
     ⚠ THE RANKING MUST NOT SILENTLY BACKFILL A SENSITIVE PAGE'S SLOT WITHOUT SAYING SO.
       When an exclusion removes a page, the next candidate takes its place — which is
       correct, but the banner (S-1) must re-render so the reviewer sees the new list.

  ── S-3. PER-FILE ALLOCATION ───────────────────────────────────────────────  ⬜
     A CORRECTNESS FIX, NOT A PREFERENCE. Fixes A2.
     Replace the greedy file-order walk with a ROUND-ROBIN BY RANK: rank each file's
     pages as today, then take the weakest page of file 1, the weakest of file 2, …
     and only then second-weakest of file 1. With 2 slots and 2 files each file is
     represented; with 3 slots and 2 files it is 2+1 to the file with more scanned
     pages (tie broken by file order, so the result stays stable across runs —
     stability is already a stated property of this ranking, rule 4 at :1595).
     ⚠ THIS CHANGES WHAT THE AI SEES ON EVERY MULTI-FILE UPLOAD. It is the one step
       here with a quality outcome nobody can predict from the code, and the AI tier
       answers ~2% of calls (local_llm_parser_plan.md §4), so measuring the OUTCOME is
       not realistically possible. Judge it on the SELECTION being defensible, not on
       the answer improving.

  ── S-4. THE REVIEWER-CHOSEN COUNT ─────────────────────────────────────────  ⬜
     LAST, DELIBERATELY. Fixes A1 + A4.
     AI_SLICE_PAGES becomes a default (2) and a ceiling (3, the server's own limit),
     with a small 1/2/3 selector in the AI row. _ocrAiTogglePage's `shift()` cap reads
     the chosen count rather than the constant.
     ALSO FIX A8 HERE, because this is the step that makes the promise specific: when
     buildAiSlices returns fewer slices than the selection asked for, SAY SO before
     sending ("1 of the 2 pages could not be rendered — send 1 anyway?"), rather than
     quietly sending a shorter request.
     ⚠ DO NOT RAISE THE DEFAULT TO 3. Raise the CEILING. The default is what an
       unattended reviewer sends, and F1's "nothing leaves unless asked" spirit argues
       for the smallest default that works.

===============================================================================
  5. THE FOUR QUESTIONS THE USER OWNS (Rule 10 — do not decide these alone)
===============================================================================

  Q1. SHOULD AN EXCLUSION OUTLIVE THE MODAL SESSION? Today every AI stash dies in
      cancelDocumentAutofill (A7). Per-session exclusion is simple and has no storage
      or naming problem. "Never send page 7 of this contract, ever" needs a durable
      key — and file NAME is the only handle we have, which is weak (two different
      contracts can share a name, and a re-upload under a new name loses the rule).
      RECOMMENDATION: per-session for now. It matches how every other AI stash behaves
      and it is the version that cannot be wrong about which document it is refusing.

  Q2. PER-PAGE EXCLUSION, WHOLE-FILE EXCLUSION, OR BOTH? The user said "file
      EXCLUSION"; the concrete case (§46.6) is a PAGE. Both are cheap, but they are
      different affordances and only one may be wanted.
      RECOMMENDATION: both, with per-page as the prominent one — the notarial page is
      one page of a contract whose other pages are exactly what the AI should read.

  Q3. DOES THE DEFAULT COUNT STAY AT 2? See S-4. My recommendation is yes, with 3
      available. Changing the default changes what every unattended press sends.

  Q4. ⏸ THE CLIENT QUESTION, NOT MINE AND NOT YOURS TO GUESS — may PMU procurement
      documents' notarial pages go to a third-party model at all? The user is asking
      the client on PILOT DAY (§46.6). If the answer is no, S-2 is the mechanism that
      honours it; if the answer is "we do not care", S-2 costs nothing and S-1 is still
      worth having. NOTHING IN THIS PLAN DEPENDS ON THE ANSWER, which is why it can be
      built before the answer arrives.

===============================================================================
  6. HOW EACH STEP WOULD BE PROVEN
===============================================================================

  OFFLINE (the harness from restudy §48.1 — document-parser.js loads in a node:vm
  sandbox with a window stub; probes live in the session scratchpad, NEVER in the repo):
    S-1  the banner string is built from ocrAiSelection, so a fixture with a known
         ranking must produce a banner naming exactly those pages; change the ranking,
         the banner must follow.
    S-2  an excluded page never appears in ocrAiCandidates NOR in ocrAiSelection when
         it is also in _ocrAiPick; an exclusion whose file is missing from the reports
         still filters (the fail-closed assert); a file exclusion removes every page of
         that file and nothing of any other.
    S-3  two fixture files with known per-page char counts must produce one page from
         each at maxN=2, and the SAME result twice (stability); a single-file upload
         must be byte-identical to today's answer.
    S-4  the count flows to _ocrAiTogglePage's cap and to buildAiSlices; 4 is refused;
         the short-slice warning fires when a candidate cannot render.
    EVERY STEP: a mutation per assert, per standing practice — a test that passes with
    the rule removed has proven nothing.
    ⚠ AND A BACKWARD-COMPAT ASSERT ON EACH: a scan report or pick list captured before
      the step exists must behave exactly as it does today. This caught nothing in the
      P-4/5/6 batch but it is the assert that would catch the worst kind of regression.

  BROWSER (standing rule — browser-proven beats suite-green for anything that renders,
  and all four of these render):
    S-1  the banner names the same pages the ✦ row does, and "See or change these"
         opens the panel at the right page.
    S-2  excluding the AI's own suggestion visibly moves the ✦ to another page and the
         banner updates; the excluded page carries its mark in the strip.
    S-3  a two-file upload shows one ✦ per file.
    S-4  choosing 3 sends 3; choosing 1 sends 1; the +/− control respects the choice.

===============================================================================
  7. FILES THIS WOULD TOUCH
===============================================================================

  public/js/admin/projects/document-parser.js   ALL FOUR STEPS. Specifically
      :490   AI_SLICE_PAGES                  (S-4)
      :1560  ocrAiCandidates                 (S-2 filter, S-3 round-robin)
      :1734  ocrAiSelection                  (S-2 filter, fail-closed)
      :1755  _ocrAiTogglePage                (S-4 cap)
      :1893  the preview panel's AI row      (S-2 + S-4 controls)
      :2047  buildAiSlices                   (S-4 short-slice honesty, A8)
      :2699  the amber AI banner             (S-1)
      :101   cancelDocumentAutofill          (S-2 stash teardown)
  NO SERVER CHANGE IS NEEDED for any of this — the controller already accepts 1 to 3
  and already enforces the byte caps. Worth stating plainly so nobody widens the API
  on the assumption that a client-side count needs it.
  NO PHP, NO BLADE, NO CSS expected; the AI row and banner are built in JS.

===============================================================================
  8. WHAT WOULD MAKE ME STOP AND COME BACK
===============================================================================

  · If S-1 shows the banner cannot call ocrAiSelection at render time (a stash not yet
    populated), the promise/payload guarantee is gone and S-1 needs a different shape —
    report before working around it.
  · If S-3's round-robin changes a SINGLE-file upload's selection at all, the change is
    wrong: one file has nothing to round-robin with and must be untouched.
  · If exclusion turns out to need persistence to be useful in the pilot (Q1 answered
    the other way), S-2 grows a storage decision and that is a new conversation, not a
    bigger commit.

===============================================================================
  END OF PLAN
===============================================================================
