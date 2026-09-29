<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Import\Coppermine\CoppermineMigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mediarama:import:coppermine',
    description: 'Run the complete staged Coppermine migration and reconciliation.',
)]
final class CoppermineImportCommand extends Command
{
    public function __construct(private readonly CoppermineMigrationRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->runner->run();
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln('Import run: '.$result->runId->toRfc4122());
        $output->writeln('Source pictures: '.$result->reconciliation->sourcePictures);
        $output->writeln('Mapped pictures: '.$result->reconciliation->mappedPictures);
        $output->writeln('Target media: '.$result->reconciliation->targetMedia);
        $output->writeln('Collection links: '.$result->reconciliation->collectionLinks);
        $output->writeln('Comments imported: '.$result->interactions->commentsImported);
        $output->writeln('Favorites imported: '.$result->interactions->favoritesImported);
        $output->writeln('Individual ratings imported: '.$result->interactions->ratingsImported);
        $output->writeln('Migration completed and reconciled.');

        return Command::SUCCESS;
    }
}
