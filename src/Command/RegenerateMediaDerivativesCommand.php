<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Media\Application\RegenerateMediaDerivatives;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'mediarama:media:regenerate',
    description: 'Generate a new versioned derivative set for one image asset.',
)]
final class RegenerateMediaDerivativesCommand extends Command
{
    public function __construct(private readonly RegenerateMediaDerivatives $regenerate)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('media-id', InputArgument::REQUIRED, 'Media UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $version = ($this->regenerate)(Uuid::fromString((string) $input->getArgument('media-id')));
        } catch (\DomainException $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Image derivatives regenerated as processing version %d.',
            $version,
        ));

        return Command::SUCCESS;
    }
}
