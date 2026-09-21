# AI SLICE SELECTION — WHICH PAGES LEAVE THE BUILDING, AND WHO DECIDES
# Parent refs: textfiles/normal_doc_parser_restudy_plan.md (§45.4 raised it, §46.6 named
#                the page that makes it concrete, §48.3 + §49.9 fixed the reason string
#                and confirmed the ranking's behaviour on a real file)
#              textfiles/document_parser_ai_fallback_plan.md (Decision F1 — opt-in, quota)
#              textfiles/pilot_testing_readiness_plan.md (§6 P5 — the AI tier's real
#                availability; §4 F2 — what must not leave a PMU desktop)
#              AGENTS.md future-queue item 8
# Date: September 20, 2026
# Status: ⬛ BUILT 2026-09-20 on phase-29-ai-slice-selection — §9 carries the build,
#         §7a a finding the falsification pass produced about the code itself.
#         Probe 63/63 with 8 mutations · JS 267/267/0 · PHP 1232/0.
#         ⬛ BROWSER-CONFIRMED by the user 2026-09-20 ("confirmed working") — §9.7.
#         ⚠ §10 RETIRES THE AI-AVAILABILITY FIGURE for the normal-doc path AND shows
#         pilot P5(a) cannot be done until the two image paths are tagged apart.
#         §11 (per-file counts, whole-file sending) is ⏸ PARKED 2026-09-21 by the
#         user — pilot readiness first; see §11's PARKED note. §12 is the
#         COLD-SESSION HANDOFF — start there.
#         2026-09-21: phase-29 is MERGED into develop and PUSHED (working folder
#         moved to merge ver19.5 — see AGENTS.md).
#         The four questions were ANSWERED (§5b Decision Log, user
#         2026-09-20: per-session exclusion · per-page AND whole-file · default
#         count 3), and A9 was added while checking the third.
#         §2 carries the findings A1-A8 (A9 is in §5b), all traced in source.
#         §4 is the build in four steps with a deliberate ORDER — controls first.
#         §5 carries the questions; §5b the answers. Rule 10 still binds anything
#         not yet ordered.
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
       answers on its own schedule, so measuring the OUTCOME is not realistically
       possible either way. Judge it on the SELECTION being defensible, not on
       the answer improving.

  ── S-4. THE REVIEWER-CHOSEN COUNT ─────────────────────────────────────────  ⬜
     LAST, DELIBERATELY. Fixes A1 + A4 + A8 + A9.
     AI_SLICE_PAGES becomes a DEFAULT OF 3 (Decision 3) and a ceiling of 3 — the
     server's own limit — with a small 1/2/3 selector in the AI row.
     _ocrAiTogglePage's `shift()` cap reads the chosen count rather than the constant,
     so a reviewer can finally express three picks (A4).
     ALSO FIX A8 HERE, because this is the step that makes the promise specific: when
     buildAiSlices returns fewer slices than the selection asked for, SAY SO before
     sending ("1 of the 3 pages could not be rendered — send 2 anyway?"), rather than
     quietly sending a shorter request.
     ALSO FIX A9 HERE: a client-side total-bytes check before posting, naming the
     selector, so the 30 MB refusal cannot arrive server-side after three renders.
     ⚠ NOTE FOR THE FUTURE READER: an earlier draft of this plan argued AGAINST a
       default of 3 ("raise the ceiling, not the default"). THE USER DECIDED
       OTHERWISE on 2026-09-20 and the reasoning is in Decision 3 — context per
       landed call is worth more than a spared slot, and the exclusion controls ship in the same branch. The old caution is
       kept here only so nobody "restores" it as a bug fix.
     ⚠ THE WITHIN-BRANCH ORDER STILL HOLDS: S-4 is built LAST even though it is the
       most visible. Raising the volume before S-1 and S-2 exist would ship a window
       in which more pages leave the machine than today with no way to see or refuse
       them. The default and the controls must land together or not at all.

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
  5b. DECISION LOG — ANSWERED BY THE USER 2026-09-20
===============================================================================

  Decision 1 — EXCLUSION IS PER MODAL SESSION.
    Context: Q1. Every other AI stash dies in cancelDocumentAutofill; a durable
      exclusion would need a key, and file NAME is the only handle available.
    Options: per-session · durable keyed by file name · durable keyed by content.
    DECISION: per-session ("lets go with no").
    Rationale: it cannot be wrong about which document it is refusing. A name-keyed
      rule can refuse the wrong contract or silently forget after a re-upload.
    Consequences: window._ocrAiExclude is cleared in cancelDocumentAutofill beside
      _ocrAiPick. A reviewer who closes and reopens the popup re-excludes. If the
      pilot shows that is annoying, persistence is a NEW conversation, not a bigger
      commit (§8).

  Decision 2 — BOTH PER-PAGE AND WHOLE-FILE EXCLUSION.
    Context: Q2. The user's framing said "file"; the concrete case (§46.6) is one
      page of a contract whose other pages are exactly what the AI should read.
    DECISION: both ("both").
    Consequences: _ocrAiExclude carries two lists — { pages: [...], files: [...] }.
      Per-page is the prominent control (it sits in the AI row beside + / −);
      whole-file sits next to the file's name in the gray scan note.

  Decision 3 — ⚠ THE DEFAULT COUNT BECOMES 3, WHICH IS ALSO THE CEILING.
    Context: Q3. I had recommended keeping 2 as the default with 3 available.
    DECISION: 3 as the default ("lets go with 3 as default"). The user's call,
      taken with the exclusion controls landing in the same build.
    ⚠ WHAT IT MEANS, STATED PLAINLY BECAUSE IT IS NOT OBVIOUS: 3 is the SERVER'S
      HARD MAXIMUM (DocumentParserController:128, "Provide 1 to 3 page images").
      So the default is now the ceiling — every unattended press sends the most the
      system can send, and the selector can only ever reduce it. There is no headroom
      left for a later "send one more".
    ⚠ AND IT MAKES A PREVIOUSLY-UNREACHABLE FAILURE REACHABLE — see finding A9 below.
    Rationale recorded for the future reader: when a call lands, more context per
      call is worth more than a spared slot. ⚠ THIS RATIONALE ORIGINALLY LEANED ON
      A RETIRED FIGURE ("the tier answers ~2% of calls") — see §10, which shows
      that number never described the normal-doc path. The decision stands; only
      its supporting sentence was wrong. Exclusion (S-2) is what keeps "more pages"
      from meaning "more pages nobody vetted", which is why it ships in the same
      branch and BEFORE S-4 within it.

  A9 — ⚠ NEW FINDING, FOUND WHILE CHECKING DECISION 3. THE TOTAL-BYTES CEILING
       BECOMES REACHABLE AT THREE PAGES.
         DocumentParserController:32  MAX_SLICE_BYTES = 10 MB  (per image)
                                :35  MAX_TOTAL_BYTES = 30 MB  (per request)
                                :173 $total > MAX_TOTAL_BYTES ->
                                     "The page images exceed the total size budget.
                                      Send fewer pages."
       At two pages the worst case is 20 MB and the total ceiling is unreachable by
       construction. AT THREE IT IS EXACTLY 30 MB — the boundary. And buildAiSlices
       renders PNG (:2081, canvas.toDataURL('image/png')), which is the worst format
       for a noisy scan: a 1650x2130 scanned page compresses poorly, so three of them
       is the realistic bad case, not a contrived one.
       THE FAILURE IS SERVER-SIDE AND LATE: the reviewer waits through three page
       renders and the upload, then gets "Send fewer pages" with no control named.
       ⇒ S-4 MUST THEREFORE CARRY A CLIENT-SIDE TOTAL CHECK — measure the assembled
         slices before posting and, if they exceed the budget, say so naming the
         selector ("3 pages is 34 MB, over the 30 MB limit — send 2?") instead of
         letting the server refuse it after the cost is paid.
       ⬜ NOT CHOSEN, RECORDED AS THE LEVER IF IT BITES: rendering the AI slice as
         JPEG instead of PNG would cut size several-fold at negligible cost to a
         vision model. It changes what the model is shown, so it is a measured
         decision of its own, not a tweak to slip into S-4.

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
  7a. ONE FINDING THE BUILD PRODUCED ABOUT ITSELF (2026-09-20)
      A mutation probe refused to go red, and it was right to.
===============================================================================

  The falsification pass removed `if (ocrAiFileExcluded(r.fileName)) continue;` from
  the top of ocrAiCandidates' per-file loop and expected the refused file's pages to
  come back. THEY DID NOT. The mutation survived.

  WHY, and it is not a defect: ocrAiPageExcluded() answers true for every page of a
  refused file (it tests the files list first), so the per-page filter below already
  drops all of them, and the `if (!ordered.length) continue;` guard then skips the
  file. REMOVING THE FILE-LEVEL LINE ALONE CHANGES NO BEHAVIOUR.

  ⇒ SO THAT LINE IS NOT THE ENFORCEMENT POINT, and the source now says so in place.
    It is kept for two honest reasons — it states the intent where a reader looks for
    it, and it avoids ranking a whole file's pages only to discard the result — but a
    future reader must not mistake it for the guard.
  ⇒ THE PROBE WAS CORRECTED RATHER THAN THE CODE. Two mutations now stand in its
    place: one removing BOTH enforcement points (which does go red, proving the PAIR
    is load-bearing), and one removing only the page-level check (which goes red on
    the SELECTION gate, where a stale pick would otherwise slip a refused page
    through). A mutation that cannot fail proves nothing, and the honest response is
    to fix the assertion, not to delete it.

===============================================================================
  9. BUILT — S-1 … S-4 (2026-09-20, ordered "yes proceed")
     Branch phase-29-ai-slice-selection, off develop at 354c4a3 (+ the two docs
     commits). ONE FILE: public/js/admin/projects/document-parser.js.
     NO SERVER CHANGE, as §7 predicted — the controller already accepts 1 to 3.
===============================================================================

  9.1 SHARED STATE (both new stashes die with the popup, per Decision 1)
      AI_SLICE_PAGES  2 -> 3, and it is now BOTH the default and the ceiling,
                      because 3 is the server's own limit. The selector can only
                      ever reduce what is sent.
      aiSliceCount()  the reviewer's 1..3, clamped, with the ceiling as default.
      window._ocrAiExclude = { pages: [{fileName,pageNum}], files: [fileName] },
                      read through ocrAiExclusions / ocrAiFileExcluded /
                      ocrAiPageExcluded.
      window._ocrAiCount
      Both cleared in cancelDocumentAutofill beside _ocrAiPick.

  9.2 S-1 — THE BANNER NAMES WHAT IT WOULD SEND
      autofillAiBannerHtml() builds the offer from the SAME ocrAiSelection() the
      slicer calls, so the promise and the payload cannot drift. It reads:
        "Try AI assist? Would send 3 pages: Contract - CHS Savings.pdf p.4,
         CHS - Savings.pdf p.11, Contract - CHS Savings.pdf p.7. Nothing else
         leaves this computer."   [See or change these] [Try AI assist]
      "See or change these" opens the existing preview panel AT THE FIRST NAMED
      PAGE, and is offered only when that panel exists (a weak text-layer read has
      no scanned pages and therefore no panel — checked, not assumed).
      refreshAutofillAiBanner() repaints it whenever the selection moves, so an
      exclusion or a count change cannot leave a stale promise on screen. It
      deliberately does NOT repaint a banner carrying a finished attempt's error.

  9.3 S-2 — EXCLUSION, PER-PAGE AND PER-FILE (Decision 2)
      Per-page: a "⃠ never send this page" control in the AI row, beside + / −.
      Per-file: a "⃠ never send this file" control in the gray scan note, next to
                that file's "See & straighten" button, index-indirected like every
                other per-file control.
      ENFORCED IN TWO INDEPENDENT PLACES, on purpose:
        · ocrAiCandidates — a refused page is never ranked, so it is never suggested;
        · ocrAiSelection  — a refused page is stripped from the REVIEWER'S OWN picks,
                            so a pick made before the refusal cannot outlive it.
      ⚠ AND IT FAILS CLOSED. The exclusion test runs BEFORE the report lookup in
        ocrAiSelection, so an exclusion whose file cannot be matched to a current
        scan report still blocks — unlike _ocrAiPick, which is validated away on
        purpose. Asserted directly (§9.6), because it is the assert that separates
        this from a privacy bug wearing the costume of consistency.
      Excluding a page also removes it from the pick list in the same action, and
      the + control refuses to add a refused page back.

  9.4 S-3 — PER-FILE ALLOCATION (the A2 defect)
      ocrAiCandidates was one greedy walk whose outer and inner loops were both
      bounded by `out.length < maxN`, so the first file consumed every slot. It is
      now two passes: rank within each file exactly as before, then take ROUND-ROBIN
      by depth — the weakest page of file 1, the weakest of file 2, then the
      second-weakest of file 1.
      MEASURED: 2 slots / 2 files -> one page each (was 2+0). 3 slots / 2 files ->
      2+1. 3 slots / 3 files -> one each. A one-page file does not stall the others.
      ⭐ AND THE ACCEPTANCE CONDITION FROM §8 HOLDS, tested against the COMMITTED
        FILE rather than against my memory of it: a SINGLE-file upload produces a
        byte-identical selection to the version before this change, while the
        multi-file case provably differs. That is the whole point of the step, and
        both halves are asserted.

  9.5 S-4 — THE COUNT, AND TWO GUARDS THE COUNT MADE NECESSARY
      A 1/2/3 selector in the AI row; both send paths and the manual-pick cap read
      aiSliceCount(), so a reviewer can finally express three picks (A4 — adding a
      third used to silently drop the first).
      checkAiSlicePayload() runs before either send:
        A8  fewer slices than pages asked for -> CONFIRM, naming both numbers,
            instead of quietly sending a shorter request;
        A9  over the server's 30 MB total -> STOP, naming the control that fixes it
            ("Lower \"Send\" to fewer pages in the AI row"), instead of paying for
            three renders and an upload to be refused server-side.
      The check measures what the SERVER measures — the base64 payload with the
      data: prefix excluded — so the two cannot disagree about what 30 MB means.

  9.6 WHAT WAS MEASURED
      probe_slice.mjs (session scratchpad, never the repo): 63 checks, 63 pass,
      including EIGHT MUTATIONS — round-robin reverted to greedy, each of the two
      exclusion gates removed singly, both removed together, the count ignored, the
      size check disabled, and the banner made to stop naming pages.
      ⭐ The single-file regression is checked against `git show HEAD:` of the same
        file, following the standing rule about not stashing for a clean tree.
      Prior batch re-run and still green: probe_p456 43/43, probe_p6 19/19.
      SUITES: JS 267 / 267 / 0. PHP 1232 passed / 0 failed / 4605 assertions.
      Grep confirms no reference to ocrAiCandidates, ocrAiSelection, AI_SLICE_PAGES
      or _ocrAiExclude anywhere outside document-parser.js.
      ⚠ WHAT THE SUITES DO NOT COVER: all of it. The JS suite tests the pure core;
        every line above is in the DOM half. The probe is the strongest offline
        evidence available and it is still not a browser.

  9.7 ⬛ THE BROWSER CHECK — RUN AND CONFIRMED BY THE USER 2026-09-20
      ("confirmed working"). All six steps below were exercised against the real
      documents; the two that would have falsified the build — a refusal not taking
      effect, and the banner naming pages that differ from the ✦ row — both held.
      The steps, kept as the record of what was checked:
      1. THE BANNER NAMES PAGES. Upload a weak scan (or the CHS pair) until the
         amber "Try AI assist" offer appears. It must name file AND page for each
         page it would send, and say "Nothing else leaves this computer."
      2. "SEE OR CHANGE THESE" opens the preview panel at the first named page.
      3. REFUSE A PAGE. In the AI row press "⃠ never send this page" on a page the
         ✦ row currently lists. The ✦ must move to another page, the badge must
         read "⃠ never sent", and THE BANNER ABOVE MUST UPDATE TO MATCH.
      4. REFUSE A FILE. In the gray note press "⃠ never send this file". Every page
         of that file must leave the selection; the other file should fill the slots.
         Pressing it again must restore them.
      5. THE COUNT. Set "Send" to 1 — the banner must name one page. Set it to 3 —
         three. On a TWO-FILE upload at 3, the selection should span both files
         (that is S-3 visible on screen).
      6. IT STILL SENDS. With a small file, press Try AI assist and confirm the call
         goes as before. ⚠ If it fails, suspect the tier before suspecting this change
         (§10 — but do NOT assume a high failure rate for normal documents; that
         figure came from the S-curve path) — judge it by whether the REQUEST is made with the right
         pages, not by whether an answer comes back.
      ⚠ WHAT WOULD FALSIFY THE BUILD: 3 or 4 failing (the refusal is the point), or
        1 naming pages that differ from the ✦ row (promise and payload drifting,
        which is the one property S-1 exists to guarantee).

  9.8 KNOWINGLY LEFT ALONE
      · The JPEG option for A9 (plan §5b). Not chosen: it changes what the model is
        shown and deserves its own measurement, not a tweak smuggled into S-4.
      · Whether a never-scanned page SHOULD be the AI's first pick. The ranking's
        ORDER is untouched by this whole change; only who may be in it, how many,
        and how they are spread across files. That question sits with the client
        answer the user is taking to pilot day.
      · Persistence. Decision 1 says per-session, and §8 says a change of mind there
        is a new conversation rather than a bigger commit.

===============================================================================
  10. ⚠ THE AI-AVAILABILITY NUMBER, AND WHY P5(a) CANNOT BE DONE AS WRITTEN
      (user correction + source investigation, 2026-09-20)
===============================================================================

  10.1 FIRST, AN ERROR OF MINE, RECORDED BECAUSE IT IS INSTRUCTIVE

      I told the user that raising the page count would buy "bigger, slower failures"
      because the AI tier answers ~2% of calls. THE USER CORRECTED IT: that failure
      rate came from developing and testing the S-CURVE PDF path. For NORMAL
      DOCUMENTS they see the busy-server error only a handful of times.

      ⚠ AND THE CORRECTION WAS ALREADY ON RECORD BEFORE I SAID IT.
      pilot_testing_readiness_plan.md item P5 carries it, dated the same day, and
      ends: "§2's 'A THIRD FACT ALREADY ON RECORD' IS SUPERSEDED BY THIS ENTRY ...
      should not be quoted again in that form." I quoted it in that form anyway, and
      then repeated it FOUR TIMES in this very plan file (§4 S-3, §4 S-4, §5b
      Decision 3, §9.7 step 6). All four are corrected in place as of this entry.
      THE LESSON IS NOT "read harder". It is that a retired number with no
      countermanding note AT ITS SOURCE will keep being re-quoted — which is why
      10.4 puts the caveat in local_llm_parser_plan.md §4 where the figure lives,
      not only in the files that cite it.

  10.2 ⭐ WHAT IS NEW, AND IT IS A BLOCKER FOR P5's OWN REMEDY

      P5's fix reads: "(a) re-measure the ladder SPLIT BY PATH (normal-doc vs PDF
      S-curve) from the log rather than in aggregate".
      ⇒ THAT CANNOT BE DONE FROM TODAY'S LOG. THE LOG DOES NOT CARRY THE SPLIT.

      Traced in source 2026-09-20:
        · GeminiDocumentParserService has THREE public entry points —
            :474  parseProjectSlice()      the NORMAL-DOC image path
            :538  parseExcelSlice()        the Excel text path
            :616  parseSCurvePdfSlice()    the S-CURVE PDF image path
        · The two IMAGE paths share one sender, and it tags every call the same:
            :1933   $this->postGemini($url, $payload, $model, 'images');
            :1935   climbFallbackLadder(..., 'images');
        · logGeminiFailure (:282) records exactly that tag:
            Log::debug('E3 gemini parser call failed', ['where' => ..., 'model' =>
                        ..., 'status' => ..., 'quota' => ...])
      ⇒ SO EVERY FAILURE FROM EITHER IMAGE PATH IS LOGGED AS 'images', WITH NOTHING
        ELSE TO TELL THEM APART. The aggregate is not merely un-split; it is
        UNSPLITTABLE from the data as it stands.

  10.3 AND THE LOG IS STRUCTURALLY BIASED TOWARD THE S-CURVE PATH

      · parseSCurvePdfSlice logs a SUCCESS line — 'F-A pdf slice response keys'
        (:655). The current storage/logs/laravel.log holds 2000 of them.
      · parseExcelSlice logs 'E2 excel slice response keys' (:567) — 5281.
      · parseProjectSlice LOGS NOTHING AT ALL. Not on success, not on entry.
      ⇒ The normal-doc path is INVISIBLE in the log except as an untagged share of
        the 'images' failures. So the one path whose health the pilot depends on is
        the only one that leaves no positive trace, while the path the user says
        generated the failures leaves thousands. Any impression formed by reading
        that log will be pessimistic about normal-doc parsing BY CONSTRUCTION.
      WHOLE-LOG COUNTS at the time of writing (raw lines, NOT user-facing calls —
      postGemini retries twice and the ladder adds rungs, so one press can emit
      several): 5305 'E3 ... failed' in total, 3060 tagged 'images', 2245 'text'.
      These are NOT comparable to the 549/134 figures, which were filtered to
      `local.` over a seven-day window. Quoted only to show the shape of the bias.

  10.4 WHAT FOLLOWS, IN THE ORDER IT SHOULD BE DONE — (i)+(ii) ✅ BUILT 2026-09-21

      ✅ (i)+(ii) BUILT 2026-09-21 on `phase-31-parser-ai-log-tagging` (user order).
        · callGeminiWithImages() takes a `$where` tag; parseProjectSlice passes
          'images-normal', parseSCurvePdfSlice 'images-scurve'. Fallback rungs log
          '<tag>-fallback'. Both keep the 'images' prefix, so requestTimeout() still
          gives them the IMAGE timeout — asserted for all four tags.
        · parseProjectSlice logs 'N-A normal slice response' {images, model,
          fields} once per answered call (fields = non-null values; 0 if validation
          declined the answer). Transport failures still log only 'E3 ... failed'.
        · HOW TO COUNT AFTER THE PILOT: normal-doc answered calls = 'N-A normal
          slice response' lines; normal-doc failures = 'E3 gemini parser call
          failed' with where 'images-normal' / 'images-normal-fallback' (log LINES,
          not presses — postGemini retries twice and rungs add more, see (iii)).
          ⚠ Lines logged BEFORE 2026-09-21 are still the unsplittable 'images'.
        · Tests: +2 in GeminiDocumentParserServiceTest (per-path tags, success line)
          + 4 timeout asserts. Mutations: tag reverted to 'images', S-curve tag
          dropped, success line renamed — each turns a test red. PHP 1240 / 0.
        · No user-visible change; no browser check needed (service + log only).

      (i)  ⚠ TAG THE TWO IMAGE PATHS DIFFERENTLY. One-line-ish: pass
           'images-normal' from parseProjectSlice's sender and 'images-scurve' from
           parseSCurvePdfSlice's, or thread a caller tag into the shared sender.
           THIS IS THE PREREQUISITE FOR P5(a) — without it, the split cannot be
           measured now or in the pilot. It is also the cheapest item in this file.
           ⚠ requestTimeout() keys on str_starts_with($where, 'images') (:240), so
             any new tag MUST still start with 'images' or the image path silently
             gets the 30s TEXT timeout. Check that before choosing names.
      (ii) GIVE parseProjectSlice A SUCCESS LINE, so the normal-doc path stops being
           invisible and a rate can be computed for it at all.
      (iii)THEN re-measure, and only then quote a number — per path, with its window
           stated, counting USER-FACING CALLS rather than log lines.
      (iv) P5(b) — check whether the S-curve failures correlate with the BUHI slice
           specifically. AGENTS.md already flags this as unmeasured ("may be the
           payload (the shrunk full-page image), not only capacity"). The user's
           correction makes it MORE likely, not less: if the failures concentrate on
           the path that sends big full-page images, it is a payload problem wearing
           an availability costume.
      ⚠ A CORRECTION OWED AT THE SOURCE: local_llm_parser_plan.md §4 is where the
        134/549 figure lives and it states it as a fact about "the parser's AI tier"
        with no path qualifier. Until (iii) is done it should carry the caveat, or
        the retired number will be re-quoted a fourth time.

  10.5 WHAT SURVIVES THE CORRECTION UNCHANGED

      Two things I said did NOT depend on the availability number, and both still
      stand:
      · THE 15-SECOND IMAGE TIMEOUT WAS FITTED TO THREE IMAGES. Its own comment
        (:73-89) records the measurement — "up to 3 small PNG page slices ... real
        answers arrived in 8s and 10s". More pages is more to read and more to
        generate. Raising the count without re-measuring this risks attempts being
        killed by the E6 guard before they finish, which would look like the tier
        failing when it is our own deadline.
      · MAX_IMAGES = 3 SILENTLY TRUNCATES, at :484 and :626 (array_slice, no throw,
        no log). Whatever cap is chosen, that silence has to go or the client will
        promise pages the server quietly drops — the same promise-vs-payload defect
        S-1 and the A8 guard were built to remove, sitting one layer down.

===============================================================================
  11. ⏸ PARKED 2026-09-21 — THE PER-FILE SELECTION (the user's 2026-09-20 ask)
      NOT PLANNED IN DETAIL, NOT ORDERED, AND NOW PARKED. This is the ask,
      recorded verbatim enough that a fresh session can pick it up.
===============================================================================

  ⏸ PARKED (user, 2026-09-21): "parking the AI file include for now as additional
    feature later on as im getting sidetracked with the priority to make things
    pilot test ready."
    WHAT IS PARKED: everything in this section — per-file counts, "send this whole
      file", "send these whole files" — and the cap raise (§10.5) that rides with it.
    WHAT IS NOT PARKED: S-1..S-4 (§9) stay in the app. They are built,
      browser-confirmed, merged and pushed; parking means "no further build", not
      "remove".
    STILL A LOOSE END, UNORDERED: §10.4(i)+(ii) — tag the two image paths in the log
      and give parseProjectSlice a success line. Not part of the park; offered to the
      user 2026-09-21 as optional pre-pilot instrumentation (see pilot plan P5).
    UN-PARK CONDITION: the pilot is running (pilot plan P6 done), AND either pilot
      use shows reviewers needing more than the global 1..3 count, or the user
      re-orders it. Do not re-open on momentum. When it is re-opened, settle §11(1)
      and §11(2) below FIRST — they are decisions, not gaps.

  THE ASK: "the user can choose how many to send in a file (individually or all)
  including being able to send the whole file or files."

  WHAT EXISTS AFTER S-1..S-4, so the gap is exact:
    ✓ refuse a page · refuse a file · ONE GLOBAL count (1..3) · automatic
      round-robin spread across files
    ✗ a PER-FILE count ("2 from the contract, 5 from the proposal")
    ✗ "send this whole file"
    ✗ "send these whole files"

  ⚠ TWO THINGS TO SETTLE BEFORE DESIGNING IT, BOTH RECORDED HERE SO THEY ARE NOT
    DISCOVERED HALFWAY:

    (1) "SEND THE WHOLE FILE" REVERSES A STANDING DESIGN COMMITMENT. Decision F1 in
        document_parser_ai_fallback_plan.md is slice-only, and the banner has said
        "never the whole file" in those words since it was written. The user may
        absolutely un-choose that — but it is a decision to RECORD, not a gap to
        fill quietly. Whole-file sending also means the page-level refusal (S-2)
        needs a defined meaning inside a whole-file send: does an excluded page
        still get sent as part of "all"? IT MUST NOT, and whatever is built has to
        assert that.

    (2) THE CAP IS A SEPARATE DECISION FROM THE SELECTION UI. The selection is
        useful at ANY cap — it is how a reviewer says "all 7 pages of the contract,
        none of the annex". The cap question is §10.4/§10.5 and depends on a
        re-measured timeout, not on the availability figure this entry retires.
        THE THREE 3s, all ours, none of them a Gemini limit:
          document-parser.js            AI_SLICE_PAGES = 3
          DocumentParserController:129  count($images) > 3 -> "Provide 1 to 3"
          GeminiDocumentParserService:69 MAX_IMAGES = 3 (and it truncates SILENTLY)
        Raising the cap is a THREE-PLACE change, and one of the three fails quietly.

===============================================================================
  12. §0-STYLE HANDOFF — READ THIS FIRST IF THE SESSION IS COLD
===============================================================================

  WHERE THE WORK IS
    Branch `phase-29-ai-slice-selection`, off `develop` at 354c4a3.
    Commits: b3a0e5c (the S-1..S-4 build) + this docs entry.
    ✅ 2026-09-21: MERGED into `develop` (fast-forward) and PUSHED, in the working
    folder `merge ver19.5\PMU_System` (the old `merge ver15` folder is retired).
    `develop` carries phases 23-29 plus the teammate's Google Calendar merge;
    `main` is still far behind and nothing of the parser track is on it.
    ⏸ §11 IS PARKED (2026-09-21) — item 3 below is not next; pilot readiness is.

  WHAT IS DONE AND PROVEN
    S-1..S-4 are BUILT and BROWSER-CONFIRMED by the user ("confirmed working",
    2026-09-20) — the banner names its pages, per-page and per-file refusal work,
    the count selector works, and the per-file round-robin is visible on a
    two-file upload. Offline: probe_slice.mjs 63/63 with 8 mutations.
    Suites: JS 267/267/0 · PHP 1232 passed / 0 failed.

  WHAT IS OPEN, IN THE ORDER I WOULD DO IT
    1. §10.4(i) TAG THE TWO IMAGE PATHS IN THE LOG. Cheapest item here, and it
       UNBLOCKS pilot P5(a), which cannot be done at all today. Mind the
       str_starts_with($where, 'images') timeout selector.
    2. §10.4(ii) give parseProjectSlice a success line so its rate is computable.
    3. ⏸ PARKED 2026-09-21 — §11 THE PER-FILE SELECTION — needs a restudy and a plan
       of its own, plus the two decisions in §11(1)/(2). See §11's un-park condition.
    4. §10.4(iv) the BUHI payload hypothesis (pilot P5(b)).
    The cap raise is NOT in this list on purpose — it should follow the re-measured
    timeout (§10.5), not lead it.

  WHAT NOT TO RE-LITIGATE
    · The AI availability figure. It is retired for the normal-doc path. Do not
      quote 134/549 or "~2% of calls" as a fact about normal documents. See §10.
    · Exclusion is PER-SESSION by decision (§5b Decision 1), not an oversight.
    · The default count of 3 is the user's decision against my recommendation
      (§5b Decision 3), recorded with its reasoning so it is not "fixed" back to 2.
    · The file-level exclusion line in ocrAiCandidates is REDUNDANT and deliberately
      kept (§7a). A mutation probe proved it; do not delete it as dead code.
    · S-3's round-robin must leave SINGLE-file selection byte-identical. That is
      asserted against `git show HEAD:` in the probe, not against memory.

  HOW TO MEASURE ANY OF THIS WITHOUT A BROWSER
    document-parser.js loads in a node:vm sandbox with a `window` stub (and a
    `document` stub for anything that renders) — restudy §48.1 explains why, and
    probe_slice.mjs is the worked example. Probes live in the session scratchpad
    or C:\temp\opencode, NEVER in the repo.

===============================================================================
  END OF PLAN
===============================================================================
