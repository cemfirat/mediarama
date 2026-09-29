<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CoppermineMigrationRunner
{
    public function __construct(
        private Connection $target,
        private CoppermineSourceKey $sourceKey,
        private CoppermineSchemaInspector $inspector,
        private CoppermineMigrationPreflight $preflight,
        private CoppermineIdentityImporter $identity,
        private CoppermineCollectionImporter $collections,
        private CoppermineMediaImporter $media,
        private CoppermineKeywordImporter $keywords,
        private CoppermineAclImporter $acl,
        private CoppermineInteractionImporter $interactions,
        private CoppermineReconciler $reconciler,
    ) {
    }

    public function run(): CoppermineMigrationResult
    {
        $inspection = $this->inspector->inspect();
        $runId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $this->target->executeStatement(
            <<<'SQL'
INSERT INTO import_runs (
    id, source_type, source_key, source_version, status, options, progress,
    started_at, created_at, updated_at
) VALUES (
    :id, 'coppermine', :source_key, :version, 'running',
    CAST(:options AS JSONB), CAST(:progress AS JSONB),
    :started_at, :created_at, :updated_at
)
SQL,
            [
                'id' => $runId->toRfc4122(),
                'source_key' => $this->sourceKey->value(),
                'version' => $inspection->detectedVersion,
                'options' => json_encode([
                    'mode' => 'coppermine-migration',
                    'source_id' => $this->sourceKey->id(),
                ], JSON_THROW_ON_ERROR),
                'progress' => json_encode(['stage' => 'inspect'], JSON_THROW_ON_ERROR),
                'started_at' => $now->format(DATE_ATOM),
                'created_at' => $now->format(DATE_ATOM),
                'updated_at' => $now->format(DATE_ATOM),
            ],
        );

        try {
            if ($inspection->warnings !== []) {
                throw new \RuntimeException(
                    'Coppermine source inspection failed: '.implode(' ', $inspection->warnings),
                );
            }

            $this->stage($runId, 'preflight');
            $preflight = $this->preflight->inspect();
            if (!$preflight->isClean()) {
                throw new \RuntimeException(
                    'Coppermine migration preflight blocked: '.implode(' ', $preflight->blockers),
                );
            }

            $this->stage($runId, 'identity.groups');
            while ($this->identity->importGroups() > 0) {
            }

            $this->stage($runId, 'identity.users');
            while ($this->identity->importUsers() > 0) {
            }

            $this->stage($runId, 'collections.categories');
            while ($this->collections->importCategories() > 0) {
            }

            $this->stage($runId, 'collections.albums');
            while ($this->collections->importAlbums() > 0) {
            }

            $this->stage($runId, 'media');
            do {
                $media = $this->media->importBatch();
                if ($media->warnings !== []) {
                    throw new \RuntimeException(
                        'Coppermine media import stopped: '.implode(' ', $media->warnings),
                    );
                }
            } while (!$media->sourceExhausted);

            $this->stage($runId, 'collections.covers');
            $this->collections->importExplicitCovers();

            $this->stage($runId, 'keywords');
            $keywords = $this->keywords->import();
            if ($keywords->unmappedPictureIds !== [] || $keywords->unmappedAlbumIds !== []) {
                throw new \RuntimeException(sprintf(
                    'Keyword migration found %d unmapped picture(s) and %d unmapped album(s).',
                    count($keywords->unmappedPictureIds),
                    count($keywords->unmappedAlbumIds),
                ));
            }

            $this->stage($runId, 'acl');
            $acl = $this->acl->import();
            if ($acl->unmappedPrincipals !== []) {
                throw new \RuntimeException(
                    'ACL migration contains unresolved principals: '.implode(', ', $acl->unmappedPrincipals),
                );
            }

            $this->stage($runId, 'interactions');
            $interactions = $this->interactions->import();
            if ($interactions->warnings !== []) {
                throw new \RuntimeException(
                    'Interaction migration contains unresolved records: '.implode(' ', $interactions->warnings),
                );
            }

            $this->stage($runId, 'reconcile');
            $reconciliation = $this->reconciler->reconcile();
            if (!$reconciliation->isClean()) {
                throw new \RuntimeException('Coppermine reconciliation did not complete cleanly.');
            }

            $this->target->executeStatement(
                <<<'SQL'
UPDATE import_runs
SET status = 'completed',
    progress = CAST(:progress AS JSONB),
    completed_at = :completed_at,
    updated_at = :updated_at
WHERE id = :id
SQL,
                [
                    'id' => $runId->toRfc4122(),
                    'progress' => json_encode(['stage' => 'completed'], JSON_THROW_ON_ERROR),
                    'completed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
            );

            return new CoppermineMigrationResult($runId, $reconciliation, $interactions);
        } catch (\Throwable $e) {
            $this->target->executeStatement(
                <<<'SQL'
UPDATE import_runs
SET status = 'failed',
    progress = CAST(:progress AS JSONB),
    updated_at = :updated_at
WHERE id = :id
SQL,
                [
                    'id' => $runId->toRfc4122(),
                    'progress' => json_encode([
                        'stage' => 'failed',
                        'error' => $e->getMessage(),
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
            );

            throw $e;
        }
    }

    private function stage(Uuid $runId, string $stage): void
    {
        $this->target->executeStatement(
            <<<'SQL'
UPDATE import_runs
SET progress = CAST(:progress AS JSONB),
    updated_at = :updated_at
WHERE id = :id
SQL,
            [
                'id' => $runId->toRfc4122(),
                'progress' => json_encode(['stage' => $stage], JSON_THROW_ON_ERROR),
                'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
        );
    }
}
