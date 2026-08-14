# Execution Plan (native overlay) — Event past/timezone fix

**Wraps**: `tasks.md` with plan-decompose chunking, gates, and execution discipline.
**Gate Mode**: Soft between chunks; hard within-C1 full-suite gate after the wide-caller model change (T007); browser-by-value is the C2 milestone proof.
**Total effort**: ~3-5h.

## Chunks (expanded scope — includes column hardening + migration)

| Chunk | Tasks | Effort | Gate | Milestone |
|-------|-------|--------|------|-----------|
| 1 | T001-T002 setup + T003-T007 | ~1.5-2h | **Hard** (Tier-2 schema/data migration; structural gate T006; live-DB verify T007) | `timezone` column trustworthy: generator/save set it; migrate_to_3_14_0 backfills 'UTC'→site tz; verified on oz live DB; suite green. |
| 2 | T008-T012 | ~1.5-2h | Hard full-suite at T012 | Interpretation timezone-correct: column-based getters + rewritten comparison methods + templates + repo; DST + UTC-server unit tests green. |
| 3 | T013-T016 | ~1h | Soft; browser-by-value is the milestone | iCal verified; ambiguous-window event proven NOT-Completed in-browser then flips; gates green; 1.1.0 changelog updated. |

## Dependencies
```
C1 (column trustworthy: generator + migration) ──► C2 (interpretation: getters/methods/templates/repo) ──► C3 (iCal verify + browser proof + gates + changelog)
```
Strictly serial: C2's column-based getters require C1's migration; C3's proof requires C2.

## Gates
- **Phase 4 (now):** plan approval — hard. Includes manual cross-artifact consistency (analyze-equivalent) + `/coherence-invariants validate` (Gap-3) + `/spec-adequacy` (logged).
- **Within C1:** full `composer test` after the `Occurrence` model change (wide callers) before C2.
- **C2:** browser-by-value on oz (ambiguous-window event must NOT show Completed) is the milestone test — render and look, measure the actual rendered status.

## Key decision for the gate
**Storage/column hardening is deferred** (interpret-in-`wp_timezone()`, no data migration). The alternative — backfill the `timezone` column + change `OccurrenceGenerator` + migrate — is larger and riskier; recommended as a separate follow-up. Confirm this scope at approval.

## Artifacts
- Spec/plan/tasks/this-overlay: `specs/002-event-past-timezone/` (plugin repo, `release/1.0.x`).
- Status: per-chunk git commits + this overlay's Execution Log (no status.jsonl on the template-fusion path — FW-018 Gap 5).

## Execution Log
<!-- Append per chunk. -->
