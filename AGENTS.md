# Mediarama agent instructions

## Authority and existing decisions

Read the dated [README Project status](README.md#project-status), the [product roadmap #17](https://github.com/cemfirat/mediarama/issues/17), the active issue and applicable [architecture decisions](docs/architecture/target-architecture.md) before implementation. Reconcile current branches, PRs, exact source commits and CI evidence first; preserve concurrent work.

The [shared project-steering standard](https://github.com/cemfirat/repository-governance/blob/main/standards/project-steering.md) supplements these local rules. The product owner decides goals, priorities, budget and functional/visible acceptance. The Technical Lead owns implementation, independent review, verification and evidence maintenance. An implementer cannot approve their own change without an independent second review.

Preserve the accepted clean implementation, PostgreSQL and PHP/Symfony/Doctrine decisions in ADR-0001/0002/0006. Follow [runtime and dependency maintenance](docs/architecture/runtime-dependency-maintenance.md) for upgrades. Keep immutable originals, source/current/provenance separation, explicit authorization, privacy and deliberate publication intact. Do not turn optional AI into an automatic publisher or a separate domain model.

## Verification and integration

- Keep one coherent issue or maintenance objective per change.
- Run the relevant local checks and review the final diff before remote CI. For documentation, verify affected links and factual claims against the repository and current issue/PR evidence.
- Preserve the existing [Copilot workflow rules](.github/copilot-instructions.md): full successful branch CI on the exact final head before opening a PR; full independent PR CI before merge. No dummy checks, test removal or unexamined reruns.
- Inspect automatic release/deployment effects before merge and verify that the merged Git tree equals the tested candidate.
- The current publication and installation stop remains in force. Source or documentation integration does not authorize either action.

## Evidence and handoff

The existing README Project status is the dated overview; the active issue holds detailed acceptance and test evidence. Update both when a completed work block or failed gate changes their claims. Distinguish prepared, implemented, verified, integrated, published, installed and accepted. A successful CI run is not proof of a host installation or owner acceptance.

The real/anonymized Coppermine source and usage-permission gate in [#19](https://github.com/cemfirat/mediarama/issues/19) remains mandatory for the exit audit. Preserve draft PR #12; synthetic fixtures and unrelated features cannot close that gate. Raw source/customer material and secrets must not be committed.

The Metadata Workspace keeps source snapshot JSON and immutable originals read-only. Its owner-only current-value workflows already include descriptive editing, typed source revert, relational keywords and previewed atomic keyword batches through PR #157–160. Read the [current workspace contract](docs/architecture/metadata-workspace.md) and #91 before extending it; do not rebuild these completed workflows. Reusable templates, broader safely writable fields, further field-specific batch work and metadata search/filter UX remain open. Keep later domain profiles, healthcare research and Hybrid Collections in their existing roadmap positions; do not claim support or create new priorities from their presence in the backlog.

Record what changed, the exact commits and tests, remaining blockers and the next permitted action so another session can continue from the repository. Keep frozen release candidates separate from later documentation commits and record any intervening evidence in their active issue.
