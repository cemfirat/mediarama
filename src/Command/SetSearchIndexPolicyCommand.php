<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Platform\Application\SearchIndexPolicyRepository;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'mediarama:search-index:set',
    description: 'Set the search-engine index policy of a Collection or MediaAsset.',
)]
final class SetSearchIndexPolicyCommand extends Command
{
    public function __construct(private readonly SearchIndexPolicyRepository $policies)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'resource',
                InputArgument::REQUIRED,
                'Resource type: collection or media.',
            )
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'Resource UUID.',
            )
            ->addArgument(
                'policy',
                InputArgument::REQUIRED,
                'One of: inherit, index, noindex.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resource = strtolower((string) $input->getArgument('resource'));

        if (!in_array($resource, ['collection', 'media'], true)) {
            $output->writeln('<error>Resource must be collection or media.</error>');

            return Command::INVALID;
        }

        try {
            $id = Uuid::fromString((string) $input->getArgument('id'));
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>Resource id must be a valid UUID.</error>');

            return Command::INVALID;
        }

        try {
            $policy = SearchIndexPolicy::from((string) $input->getArgument('policy'));
        } catch (\ValueError) {
            $output->writeln('<error>Policy must be inherit, index or noindex.</error>');

            return Command::INVALID;
        }

        try {
            if ($resource === 'collection') {
                $this->policies->setCollectionPolicy($id, $policy);
            } else {
                $this->policies->setMediaPolicy($id, $policy);
            }
        } catch (\DomainException $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Set %s %s search-index policy to %s.',
            $resource,
            $id->toRfc4122(),
            $policy->value,
        ));

        return Command::SUCCESS;
    }
}
