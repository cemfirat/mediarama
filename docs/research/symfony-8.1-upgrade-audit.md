# Symfony 8.1 upgrade compatibility audit

Date: 2026-09-29  
Tracking: #144, #145  
Baseline: `9ae9865f69d91ae886ae3f21c20a43ecd8c5c377`

## Target

Upgrade Mediarama from Symfony 7.4 to the current stable Symfony 8.1 line without weakening existing security, privacy, migration, media-processing or CI guarantees.

PHP already requires **^8.5**, which is above Symfony 8.1's PHP 8.4 minimum.

## Direct dependency inventory

Direct Symfony requirements in `composer.json`:

- asset
- console
- doctrine-messenger
- dotenv
- framework-bundle
- messenger
- process
- rate-limiter
- runtime
- security-bundle
- twig-bundle
- uid
- validator
- yaml
- browser-kit (dev)
- css-selector (dev)
- phpunit-bridge (dev)

Symfony Flex remains on its independent 2.x line.

Other relevant direct dependencies are DoctrineBundle 3.x, Doctrine Migrations Bundle, Doctrine ORM 3.x, Flysystem 3.x, the AWS S3 adapter and PHPUnit 12.x.

## Confirmed compatibility findings

### Doctrine

Current lock state:

- DoctrineBundle 3.3.2 allows Symfony 8 components;
- Doctrine ORM 3.7.2 allows Symfony 8 where it integrates with Symfony;
- Doctrine Migrations Bundle 3.7.1 is the single non-Symfony framework blocker found in the current lock because it requires `symfony/http-kernel ^5.4 || ^6.0 || ^7.0`.

Doctrine Migrations Bundle 4.x supports Symfony 8 and matches Mediarama's existing PHP 8.5 / DoctrineBundle 3 / ORM 3 / DBAL 4 baseline.

The 4.0 BC review found no Mediarama usage of the removed container-aware migration APIs:

- no `ContainerAwareInterface`;
- no `ContainerAwareMigrationFactory`;
- no project code extending or referencing DoctrineMigrationsBundle internals.

### Flysystem and PHPUnit

Flysystem and the AWS S3 adapter do not introduce a Symfony-framework constraint that blocks Symfony 8 in the current lock.

PHPUnit 12 is already compatible with the PHP baseline. BrowserKit, CSS Selector and PHPUnit Bridge move with the Symfony framework line.

## Symfony 7.4 -> 8.x application API audit

Confirmed project searches found no use of these removed or legacy patterns:

- `AbstractBrowser::useHtml5Parser()`;
- legacy Guard authenticators;
- `AbstractController::getDoctrine()`;
- `ContainerAwareInterface`;
- `Bundle::registerCommands()` overrides;
- direct `ConstraintValidatorInterface` implementations;
- old `TaggedIterator` / `TaggedLocator` attributes;
- custom access-decision or voter interfaces affected by Symfony 8 signature changes;
- custom Messenger serializer or recoverable-exception interfaces.

Two Security changes are required and are included on this branch:

1. `UserInterface::eraseCredentials()` was removed in Symfony 8. Mediarama's method is empty and can be removed. `SecurityUser::__serialize()` already controls the serialized password-hash representation.
2. `UserCheckerInterface::checkPostAuth()` has an optional `TokenInterface` argument in Symfony 8. `ActiveUserChecker` is updated to the new signature.

No explicit `security.erase_credentials` configuration is present.

## Flex and recipe review

The current `symfony.lock` records Symfony 7.4-era recipes. Dependency resolution on this branch captures both the resolved lockfiles and the complete post-Flex working-tree patch. Recipe/configuration changes must be reviewed before the temporary resolver mode is removed.

## Resolution strategy

To avoid hand-editing `composer.lock`:

1. change root constraints only on this isolated branch;
2. let GitHub Actions resolve `symfony/*` plus Doctrine Migrations Bundle with `--with-all-dependencies`;
3. run the complete Mediarama suite against that resolved tree;
4. export the generated `composer.lock`, `symfony.lock` and full Flex/config patch as a workflow artifact;
5. review and commit the exact generated state;
6. remove the temporary resolver path so CI returns to plain reproducible `composer install`;
7. require a fully green branch CI on that exact committed tree;
8. only then open a pull request.

The resolver path is branch-local scaffolding and must not remain in the final merge.


## Resolved dependency state

The researched resolver run completed successfully against the real Symfony 8 dependency graph:

- Symfony FrameworkBundle and core packages resolved to the current 8.1 patch line, including **8.1.8** where published;
- Doctrine Migrations Bundle resolved to **4.0.1**;
- Doctrine DBAL remained on **4.5.0**;
- DoctrineBundle remained on **3.3.2**;
- Doctrine ORM remained on **3.7.2**.

Resolver CI run `36610770776` passed the complete Mediarama suite. Its post-Composer `git status` showed only `composer.lock` changed: Symfony Flex did not modify project configuration or recipes.

The generated dependency state was then committed in `d073a7c699aa8c73f65f10221e71a3e06852ce0b`, all temporary resolver logic was removed, and normal reproducible CI run `36611385318` passed using plain `composer validate --strict` plus `composer install`.

## Symfony 8.1 request-input behavior

HttpFoundation 8.1 `InputBag::getInt()` and `getBoolean()` throw an `UnexpectedValueException` for malformed scalar values.

Mediarama therefore keeps request parsing explicit:

- authoritative search/form integers and booleans are normalized to Mediarama's `InvalidArgumentException` 400 paths;
- non-authoritative UI status and pagination hints fall back to explicit defaults;
- public and authenticated search HTTP coverage proves malformed pagination does not become a 500 response;
- unit coverage pins strict and tolerant parsing semantics independently from future HttpFoundation changes.

CI also compiles the debug container and requires Symfony's own no-deprecations result before the branch can be considered green.
