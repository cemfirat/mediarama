<?php

declare(strict_types=1);

namespace Mediarama\Command;

use DateTimeImmutable;
use Mediarama\Media\Application\CleanupSupersededDerivatives;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mediarama:media:cleanup-derivatives',
    description: 'Preview or safely queue and delete superseded derivative generations.',
)]
final class CleanupSupersededDerivativesCommand extends Command
{
    public function __construct(private readonly CleanupSupersededDerivatives $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'execute',
                null,
                InputOption::VALUE_NONE,
                'Queue eligible database records and process retryable storage cleanup jobs.',
            )
            ->addOption(
                'older-than-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Only generations whose newest record is older than this age are eligible.',
                (string) CleanupSupersededDerivatives::DEFAULT_RETENTION_DAYS,
            )
            ->addOption(
                'keep-versions',
                null,
                InputOption::VALUE_REQUIRED,
                'Always retain at least this many newest generations per media/kind.',
                (string) CleanupSupersededDerivatives::DEFAULT_KEEP_VERSIONS,
            )
            ->addOption(
                'generation-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of superseded generations to enqueue in this run.',
                (string) CleanupSupersededDerivatives::DEFAULT_GENERATION_LIMIT,
            )
            ->addOption(
                'storage-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of queued storage deletions to process in this run.',
                (string) CleanupSupersededDerivatives::DEFAULT_STORAGE_LIMIT,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $days = $this->positiveInt($input->getOption('older-than-days'), 'older-than-days');
            $keep = $this->positiveInt($input->getOption('keep-versions'), 'keep-versions');
            $generationLimit = $this->positiveInt($input->getOption('generation-limit'), 'generation-limit');
            $storageLimit = $this->positiveInt($input->getOption('storage-limit'), 'storage-limit');
            $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d days', $days));

            if (!$input->getOption('execute')) {
                $summary = $this->cleanup->preview($cutoff, $keep, $generationLimit);
                $output->writeln(sprintf(
                    'Preview only: %d generation(s), %d derivative object(s), %d byte(s) eligible. Re-run with --execute to mutate state.',
                    $summary->generations,
                    $summary->derivatives,
                    $summary->bytes,
                ));

                return Command::SUCCESS;
            }

            $result = $this->cleanup->run(
                $cutoff,
                $keep,
                $generationLimit,
                $storageLimit,
            );

            $output->writeln(sprintf(
                'Queued %d generation(s) / %d derivative object(s) / %d byte(s); storage deleted=%d failed=%d pending=%d.',
                $result->queued->generations,
                $result->queued->derivatives,
                $result->queued->bytes,
                $result->storageDeleted,
                $result->storageFailed,
                $result->storagePending,
            ));

            return $result->storageFailed === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\InvalidArgumentException $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');

            return Command::INVALID;
        }
    }

    private function positiveInt(mixed $value, string $option): int
    {
        if (!is_scalar($value) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $option));
        }

        return (int) $value;
    }
}
