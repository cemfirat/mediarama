<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\PlatformSettings;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mediarama:platform:publication-settings',
    description: 'Change public publishing and site search-index defaults independently of the setup preset.',
)]
final class UpdatePublicationSettingsCommand extends Command
{
    public function __construct(private readonly PlatformSettingsRepository $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'public-publishing',
                null,
                InputOption::VALUE_REQUIRED,
                'Set to on or off. Omit to leave unchanged.',
            )
            ->addOption(
                'search-index-default',
                null,
                InputOption::VALUE_REQUIRED,
                'Set to index or noindex. Omit to leave unchanged.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $current = $this->settings->current();
        $publishing = $current->publicPublishingEnabled;
        $indexDefault = $current->searchIndexDefault;

        $rawPublishing = $input->getOption('public-publishing');
        if ($rawPublishing !== null) {
            $publishing = match (strtolower((string) $rawPublishing)) {
                'on', 'true', '1', 'yes' => true,
                'off', 'false', '0', 'no' => false,
                default => null,
            };

            if ($publishing === null) {
                $output->writeln('<error>--public-publishing must be on or off.</error>');

                return Command::INVALID;
            }
        }

        $rawIndex = $input->getOption('search-index-default');
        if ($rawIndex !== null) {
            try {
                $indexDefault = SearchIndexPolicy::from((string) $rawIndex);
            } catch (\ValueError) {
                $output->writeln('<error>--search-index-default must be index or noindex.</error>');

                return Command::INVALID;
            }

            if ($indexDefault === SearchIndexPolicy::Inherit) {
                $output->writeln('<error>Site search-index default cannot be inherit.</error>');

                return Command::INVALID;
            }
        }

        if ($rawPublishing === null && $rawIndex === null) {
            $output->writeln('<error>Specify at least one publication setting.</error>');

            return Command::INVALID;
        }

        $next = new PlatformSettings(
            $current->deploymentProfile,
            $publishing,
            $indexDefault,
        );
        $this->settings->save($next);

        $output->writeln(sprintf(
            'Publication settings: public publishing=%s, search-index default=%s.',
            $next->publicPublishingEnabled ? 'on' : 'off',
            $next->searchIndexDefault->value,
        ));

        return Command::SUCCESS;
    }
}
