# ADR-0006: Symfony 8.1, PHP 8.5 and Doctrine

- Status: Accepted
- Date: 2026-09-25
- Amended: 2026-09-29

## Context

Mediarama needs a long-lived self-hostable foundation for a modular monolith with server-rendered UIkit presentation, PostgreSQL, background workers, robust authorization, CLI/import tooling, storage abstraction and testable module boundaries.

The initial architecture decision selected Symfony 7.4 LTS. During active pre-1.0 development, remaining on an older framework line would create avoidable version debt while the product architecture is still evolving.

As of 2026-09-29, PHP 8.5 is Mediarama's runtime baseline and Symfony 8.1 is the current stable Symfony line. Symfony 8.1 requires PHP 8.4 or newer. Symfony 8.2 is scheduled for November 2026 and must be reviewed separately rather than assumed.

## Decision

During active pre-1.0 development, Mediarama uses:

- **PHP 8.5** as the production/runtime baseline;
- **Symfony 8.1.x** as the application-framework baseline;
- **Twig** for server-rendered presentation;
- **Doctrine ORM 3.x / DBAL 4.x** for persistence;
- **Doctrine Migrations Bundle 4.x** for schema versioning;
- **Symfony Messenger** for commands/events/background jobs;
- **Doctrine/PostgreSQL Messenger transport initially**;
- **Flysystem 3** behind Mediarama's own storage interface;
- **Symfony Security** for authentication and authorization infrastructure.

Framework upgrades are not automatic. Each stable Symfony line is reviewed against direct and transitive dependency compatibility, deprecations, Flex recipes, Mediarama's code and the complete CI suite before adoption.

Before Mediarama 1.0, the project will explicitly define the production support/LTS policy instead of inheriting one accidentally from the development phase.

## Why the active stable Symfony line

Pre-1.0 is the least expensive phase in which to keep the framework current. Small, researched upgrades reduce the risk of a later multi-year major-version jump and expose deprecated framework coupling while the architecture is still easy to adjust.

This does not mean upgrading on release day or ignoring support windows. Compatibility and fully green CI remain hard gates.

## Persistence

Doctrine entities and repositories are infrastructure concerns. Domain behavior should not depend on Doctrine APIs where avoidable.

Use Doctrine for mapping/persistence, transactions, specialized DBAL queries and migrations. Read-heavy/search/report queries may use DBAL/query services when that is clearer and more efficient.

## Queue

Initial queue transport remains `doctrine://default`.

PostgreSQL is already required, so the first production profile does not need a mandatory extra broker. Redis, AMQP or SQS may be introduced later without changing application messages and handlers.

## Storage

Flysystem is used inside infrastructure adapters, but Mediarama code depends on its own `MediaStorage` contract rather than spreading Flysystem calls through the application.

## Frontend

Twig renders UIkit-based templates. Focused JavaScript handles upload queues, lightbox/viewer interactions and other progressive enhancements.

## Upgrade gate

A Symfony baseline change may merge only when:

1. direct and transitive constraints are understood before the version change;
2. actionable deprecations and removed APIs are addressed;
3. Flex and recipe changes are reviewed explicitly;
4. the committed lock/configuration state is reproducible;
5. the complete Mediarama branch CI is green before the PR is opened;
6. the complete PR CI is green on the exact proposed head before merge.

## Consequences

- PHP 8.5 remains the minimum runtime requirement.
- Symfony 8.1.x becomes the supported framework line for current development.
- PostgreSQL remains the database requirement.
- Doctrine Migrations Bundle moves to 4.x because the 3.7 lock state does not allow Symfony 8 HttpKernel.
- Framework/runtime maintenance becomes continuous explicit work.
- Symfony 8.2 requires a separate compatibility review.
