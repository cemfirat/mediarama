# Mediarama repository instructions

## Change workflow

- Never open a pull request before the current branch has completed its full CI successfully.
- Push the implementation branch first, wait for branch CI, inspect failures, and fix them on the branch.
- Create the pull request only after the latest branch head is green.
- After a pull request exists, keep its CI green before merge.
- Do not use trial-and-error commits as a substitute for reading the relevant code, issue, documentation, and upstream release notes first.
- Keep changes focused on one coherent issue or maintenance objective.
- Do not bypass, weaken, or remove tests merely to make CI pass.

## Dependency and framework maintenance

- Verify current upstream stable versions and supported runtime requirements before framework or dependency upgrades.
- Prefer small, regular upgrades over long-lived version freezes.
- Record architecture-impacting runtime/framework decisions in the relevant issue and ADR.
- Preserve Mediarama's security, privacy, migration, metadata, and media-processing invariants during upgrades.
