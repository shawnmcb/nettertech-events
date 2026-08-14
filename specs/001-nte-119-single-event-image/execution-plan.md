# Execution Plan (native overlay) — NTE-119

**Wraps**: `tasks.md` (SpecKit-shaped) with plan-decompose chunking, gates, and execution discipline.
**Gate Mode**: Soft between chunks (operator default) — with two hard exceptions: the Phase-4 plan approval (below) and the US3 schema migration (Tier-2 + structural-change gate).
**Total effort**: ~7-10h.

## Chunks (chunking rules applied over the task list)

| Chunk | Contains (task IDs) | Story | Steps | Effort | Gate | Milestone |
|-------|--------------------|-------|-------|--------|------|-----------|
| 1 | T001-T002 (setup) + T003-T005 | US1 | 5 | ~1.5h | Soft | **MVP:** clip bug fixed — configured ratio governs the single image (browser-verified by value). |
| 2 | T006-T012 | US2 | 7 | ~2.5h | Soft | Event-page aspect-ratio setting live; single page can differ from the global default. |
| 3 | T013-T023 | US3 | 11 | ~4h | **Hard (Tier-2 schema + structural-change gate after T014)** | Per-event vertical anchor; schema 3.12→3.13; crop respects per-event top/center/bottom. |
| 4 | T024-T026 | Polish | 3 | ~1h | **Hard (Tier-3 release tag)** | Gates green; version 1.0.4 + changelog; FW-018 harvest filed. |

## Dependencies
```
C1 (US1) ──► C2 (US2) ──► C3 (US3) ──► C4 (Polish)
```
Strictly serial: C2/C3 consume C1's fixed `.nte-single-event__image`/`__img` rule (the `--nte-image-ratio-single` and `--nte-single-image-anchor-y` vars). Not a `--team` candidate (single repo, shared `single.css`, < 40h floor).

## Gates
- **Phase 4 (now):** plan approval — hard. Includes cross-artifact consistency (analyze-equivalent) + `/coherence-invariants validate` (Gap-3 closure) + `/spec-adequacy` (already PROCEED).
- **Within C3:** full `composer test` immediately after T014 (schema/migration) before T016+.
- **Per chunk:** browser-by-value verification is the milestone test (measure the visible container + screenshot — never the inner `<img>`).
- **C4:** version bump + release tag with operator (Tier-3).

## Artifacts
- Spec/plan/tasks/this-overlay: `specs/001-nte-119-single-event-image/` (in the plugin repo, on `release/1.0.x`).
- Status sidecar (execution): `.claude/plans/…` is bypassed on the SpecKit path; step records will use this overlay's chunk checkpoints + the plugin's git commits per chunk. *(FW-018 harvest: the SpecKit path has no `status.jsonl` equivalent — plan-decompose's compaction-survival mechanism isn't wired into `specs/NNN-/`. Candidate enhancement.)*
