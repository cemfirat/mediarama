# Runtime and dependency maintenance

Status: Active  
Tracking: #145

This document is the authoritative maintenance policy for Mediarama's development runtime and direct dependency baseline. Executable version constraints remain in the package manifests, and CI defines the runtime that is actually exercised on every branch and pull request.

## Current development baseline

- PHP: minimum **8.5**, tested in CI on **8.5**
- Symfony: **8.1.x**
- Doctrine ORM: **3.x**
- Doctrine DBAL: **4.x**
- Doctrine Migrations Bundle: **4.x**
- Node.js for frontend build CI: **24**
- Primary database: PostgreSQL

ADR-0006 records the framework-selection rationale. This document owns the ongoing maintenance process after that architectural choice.

## Policy

During active pre-1.0 development, Mediarama should prefer small, researched upgrades over infrequent multi-year jumps.

A dependency being available is not by itself a reason to adopt it. Framework, runtime and important direct dependency upgrades require compatibility review and complete project verification before merge.

Mediarama currently follows the stable Symfony line when:

1. the selected PHP baseline supports it;
2. direct and transitive dependencies are compatible;
3. relevant framework deprecations and removed APIs have been reviewed;
4. Flex recipe/configuration changes have been inspected;
5. the complete Mediarama CI passes on the exact proposed tree.

The production support/LTS policy for Mediarama 1.0 must be decided explicitly before release freeze. Pre-1.0 maintenance policy must not silently become a long-term support promise.

## Sources of truth

The maintenance baseline is intentionally split by responsibility:

- this document: support and maintenance policy;
- `composer.json`: PHP, Symfony and PHP-library constraints;
- `package.json`: frontend dependency constraints;
- committed lockfiles: resolved dependency state;
- `.github/workflows/ci.yml`: runtime actually tested by the full suite;
- ADR-0006: architectural rationale for the framework/runtime choice.

When the supported baseline changes, these sources must be updated together so they do not contradict one another.

## Upgrade workflow

Framework/runtime and significant dependency upgrades should use this sequence:

1. open or update a focused GitHub issue;
2. review upstream release notes, removed APIs, deprecations and dependency support matrices;
3. make the change on an isolated branch;
4. update manifests and lockfiles reproducibly;
5. inspect Flex/configuration or other generated changes rather than accepting them blindly;
6. clear actionable deprecations introduced by the upgrade;
7. push the final candidate branch and require the complete branch CI to pass;
8. open the pull request only after the branch head is green;
9. require the exact final PR head to be green before merge;
10. update this document whenever the supported runtime/framework baseline changes.

Unrelated dependency upgrades should not be bundled into framework migrations merely for convenience.

## Dependabot policy

Mediarama enables Dependabot version updates for Composer and npm through `.github/dependabot.yml`.

Routine version checks run weekly. Low-risk minor/patch updates are grouped by subsystem to reduce pull-request noise:

- Symfony;
- Doctrine;
- Flysystem;
- PHPUnit;
- UIkit frontend runtime;
- LESS frontend tooling.

Major updates and unmatched dependencies remain separate proposals. Grouping is only a review/noise policy; it does not authorize merging.

Routine groups apply to version updates. Security update pull requests remain visible separately rather than being hidden inside the weekly maintenance groups.

Dependabot pull requests:

- are never auto-merged by project policy;
- must satisfy the same CI requirements as human-authored dependency changes;
- must keep lockfile changes reproducible and reviewable;
- should be split or closed when a grouped update makes failures difficult to isolate;
- should not raise the minimum PHP or framework support baseline without an explicit issue and documentation update.

## PHP maintenance

The minimum supported PHP version is currently 8.5, and CI tests the production baseline on PHP 8.5.

Before changing that baseline:

- review PHP release/deprecation notes;
- verify all required extensions and runtime tools;
- verify Symfony and direct dependencies support the target version;
- document deployment impact for self-hosted users;
- decide whether compatibility with more than one PHP line is intentional.

If multiple PHP lines become supported, CI should test each deliberately supported line instead of relying on an unverified Composer constraint.

## Review triggers

Revisit this policy when:

- Symfony publishes a new stable minor or major line;
- PHP publishes a new stable line relevant to Mediarama;
- Doctrine, Flysystem, PHPUnit or another important direct dependency changes its PHP/Symfony support matrix;
- a security advisory requires an urgent dependency change;
- Mediarama approaches beta, RC or stable release.

## Release-policy gate

Before the Mediarama 1.0 release freeze, define and document:

- whether 1.x follows stable Symfony continuously or stabilizes on an LTS line;
- supported PHP/Symfony combinations and their support duration;
- upgrade expectations for self-hosted installations;
- how security-only upgrades are handled when they must move faster than routine dependency maintenance.
