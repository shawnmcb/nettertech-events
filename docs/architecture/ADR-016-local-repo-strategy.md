# ADR-016: Local-Repo Strategy (Dropbox-Hosted Bare Repository)

**Date:** 2026-02-16
**Status:** Accepted
**Deciders:** Human + Engineering
**Category:** Development Infrastructure
**Referenced By:** ADR-017 (Push Validation Strategy)

## Context

The NetterTech Events suite is developed by a solo engineer (with AI assistance) against a Local by Flywheel WordPress environment. Source control needs to satisfy five constraints:

1. **Multi-machine sync.** The working copy must be available across workstations without manual sneaker-net.
2. **Durable history.** A remote `git push` must land on durable storage that survives a workstation loss.
3. **No hosted CI dependency.** Quality enforcement runs locally (ADR-017 Layer 1 and Layer 2 hooks) and must not assume the presence of a hosted pipeline.
4. **Low cost and low operational overhead.** No GitHub/GitLab/Bitbucket subscription, no self-hosted forge to maintain, no runner fleet to keep updated.
5. **Privacy.** The development repository is private by default; commit messages, branch names, and work-in-progress code are not visible to third parties until the release artifact is published to WordPress.org.

Hosted forges (GitHub, GitLab, Bitbucket) satisfy durability and multi-machine sync but couple source control to a vendor, introduce a CI-platform surface area that must be configured and maintained, and either require a paid plan for private repositories or expose metadata that the project prefers to keep private.

## Decision

**Use a Dropbox-hosted bare git repository as the canonical remote.**

### Configuration

Each plugin's working copy sets its `origin` remote to a bare repository inside a Dropbox-synced folder:

```
~/<synced-folder>/<hub>/<plugin>.git
```

### Properties

- **Remote type:** Bare repository (`git init --bare`), no working tree.
- **Transport:** Local filesystem. `git push` and `git fetch` operate over the Dropbox-mounted path; Dropbox syncs the packed objects to all linked machines.
- **Durability:** Dropbox handles off-workstation durability and versioning. No additional backup required for source history.
- **Access control:** Limited to the user's Dropbox account. No third-party has repository-level access.
- **CI/CD:** None. Quality gates live entirely in local git hooks (ADR-017).

### What This Implies for Other Infrastructure

| Concern | Resolution |
|---|---|
| Pull requests / code review | Not used. Solo developer; review happens in-line during commit. |
| Issue tracking | Handled outside git (readme.txt `Stable tag` + `CHANGELOG.md`; bug IDs like `BUG-001`, `NTE-048` referenced in commit messages). |
| Build pipelines | None. Artifacts are built locally via `composer dist` and uploaded directly to WordPress.org SVN. |
| Quality gates | `.githooks/pre-commit` and `.githooks/pre-push` run locally before every commit / push (ADR-017). `composer push` chains the full validation suite before `git push`. |
| Secret scanning | `gitleaks` runs as part of `.githooks/pre-commit` against staged files only. |
| Dependency audit | `composer audit` runs as part of `composer push`. |
| Release automation | `composer dist` produces the distribution zip; WordPress.org SVN receives the release manually. |

## Alternatives Considered

### GitHub / GitLab / Bitbucket (hosted forge with CI)

- **Pros:** Industry-standard workflow. Free or low-cost private repos available. Rich CI offerings (GitHub Actions, GitLab CI).
- **Cons:** Introduces vendor dependency. Requires maintaining pipeline YAML that duplicates local hook coverage. Exposes commit metadata to the vendor even on private repos. A solo project gains little from PR review workflows. CI runners have their own cost/maintenance model (minutes, cache, runners).
- **Verdict:** Rejected. The marginal benefit of hosted CI does not justify the operational surface area for a solo, AI-assisted project whose quality gates are already enforced locally.

### Self-hosted git server (Gitea, Forgejo, GitLab CE)

- **Pros:** Full control. No vendor exposure. Modern web UI and issue tracker.
- **Cons:** Requires provisioning, patching, and backups for a server that exists solely to serve one developer's bare repo. Adds an entire class of operational concerns (SSL, auth, uptime) for no user-visible benefit.
- **Verdict:** Rejected. Strictly worse than Dropbox for this use case — Dropbox already handles durability, sync, and access.

### iCloud Drive or Google Drive in place of Dropbox

- **Pros:** Same bare-repo pattern, different sync backend.
- **Cons:** iCloud Drive's file-coalescing and on-demand download behavior has historically corrupted git pack files. Google Drive's Mac client has similar issues with many small files. Dropbox's sync engine is the only one with a durable track record for hosting git bare repos.
- **Verdict:** Rejected for compatibility. Dropbox remains the only cloud-sync service reliable enough for this pattern.

### USB/external drive with `git daemon` or rsync

- **Pros:** Fully local, no cloud dependency.
- **Cons:** Single-point-of-failure workflow. No automatic multi-machine sync. Manual backup discipline required.
- **Verdict:** Rejected. Loses the multi-machine-sync property that motivates this ADR.

## Consequences

### Positive

- **Zero hosted dependencies.** The project has no pipeline YAML, no runner minutes to budget, no platform outages to wait out.
- **Privacy by default.** Commit history, branch names, and WIP code never leave the developer's control until release.
- **Cheap and fast.** `git push` is a filesystem copy; Dropbox sync is transparent.
- **Full validation discipline stays local.** Because there is no CI safety net, `.githooks/pre-commit` and `.githooks/pre-push` must catch every regression — this forces quality gates to the earliest possible point (ADR-017).
- **Portable.** Any new machine needs only Dropbox + a clone from the synced path.

### Negative

- **No collaborator workflow.** The model does not extend gracefully to multi-developer teams; a second contributor would trigger a migration to a hosted forge.
- **No hosted search or issue tracker.** Features like GitHub code search, issues, discussions, and external contributions are unavailable.
- **Sync-race risk.** If the same bare repo is pushed from two machines faster than Dropbox can propagate, one push may produce a non-fast-forward conflict. In practice this has not occurred for this single-developer project.
- **Bus factor.** Loss of the developer's Dropbox account without a separate backup would lose the `.git/` remote. History is still recoverable from any working copy.

### Mitigations

- For bus-factor risk: periodically `git clone --mirror` the bare repo to an off-Dropbox location.
- For sync-race risk: avoid parallel pushes from multiple machines; if one occurs, resolve via standard `git pull --rebase` on the trailing machine.
- For migration flexibility: nothing in the codebase, CI config, or hooks assumes the Dropbox remote. A future migration to a hosted forge is a `git remote set-url` plus the addition of pipeline YAML — no code changes.

## Related

- `.git/config`: Remote `origin` points to Dropbox-hosted bare repo.
- `.githooks/pre-commit`, `.githooks/pre-push`: Local quality gates that compensate for absent hosted CI.
- `composer.json` `push` script: Chains validation + `git push`.
- `.distignore`: Excludes development artifacts from the distribution zip.
- ADR-017: Push validation strategy that builds on this decision.
