<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Import\Coppermine\CoppermineMigrationPreflight;
use Mediarama\Import\Coppermine\CoppermineSchemaInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mediarama:import:coppermine:inspect',
    description: 'Inspect a Coppermine source without changing source or target data.',
)]
final class CoppermineInspectCommand extends Command
{
    public function __construct(
        private readonly CoppermineSchemaInspector $inspector,
        private readonly CoppermineMigrationPreflight $preflight,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->inspector->inspect();

        $output->writeln(sprintf('Source: %s', $report->source));
        $output->writeln(sprintf('Detected: %s', $report->detectedVersion));

        foreach ($report->counts as $entity => $count) {
            $output->writeln(sprintf('%s: %d', $entity, $count));
        }

        foreach ($report->warnings as $warning) {
            $output->writeln('<comment>Warning: '.$warning.'</comment>');
        }

        if ($report->warnings !== []) {
            return Command::INVALID;
        }

        $preflight = $this->preflight->inspect();
        foreach ($preflight->blockers as $blocker) {
            $output->writeln('<error>Preflight blocker: '.$blocker.'</error>');
        }

        return $preflight->isClean() ? Command::SUCCESS : Command::INVALID;
    }
}
