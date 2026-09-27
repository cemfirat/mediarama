<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Media\Application\CleanupSupersededDerivatives;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mediarama:media:cleanup-derivatives',
    description: 'Retire eligible derivative versions and retry queued derivative-object deletion.',
)]
final class CleanupMediaDerivativesCommand extends Command
{
    public function __construct(private readonly CleanupSupersededDerivatives $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'version-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum superseded processing versions to stage per run.',
                '100',
            )
            ->addOption(
                'delete-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum queued storage objects to attempt per run.',
                '500',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $versionLimit = filter_var(
            $input->getOption('version-limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $deleteLimit = filter_var(
            $input->getOption('delete-limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($versionLimit === false || $deleteLimit === false) {
            $output->writeln('<error>Cleanup limits must be positive integers.</error>');

            return Command::INVALID;
        }

        $report = ($this->cleanup)((int) $versionLimit, (int) $deleteLimit);

        $output->writeln(sprintf(
            'Derivative cleanup staged %d object(s), completed %d queued deletion(s), failed %d deletion(s).',
            $report->stagedObjects,
            $report->completedObjects,
            $report->failedObjects,
        ));

        return $report->failedObjects === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
