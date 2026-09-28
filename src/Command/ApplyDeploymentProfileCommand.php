<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\DeploymentProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mediarama:platform:deployment-profile',
    description: 'Apply a deployment profile to public publishing and search-index defaults.',
)]
final class ApplyDeploymentProfileCommand extends Command
{
    public function __construct(private readonly PlatformSettingsRepository $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'profile',
            InputArgument::REQUIRED,
            'One of: private_workspace, public_publishing, internal_isolated.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = (string) $input->getArgument('profile');

        try {
            $profile = DeploymentProfile::from($raw);
        } catch (\ValueError) {
            $output->writeln(sprintf(
                '<error>Unknown deployment profile "%s". Expected private_workspace, public_publishing or internal_isolated.</error>',
                $raw,
            ));

            return Command::INVALID;
        }

        $settings = $this->settings->applyProfile($profile);

        $output->writeln(sprintf(
            'Applied %s: public publishing=%s, search-index default=%s.',
            $settings->deploymentProfile->value,
            $settings->publicPublishingEnabled ? 'on' : 'off',
            $settings->searchIndexDefault->value,
        ));

        return Command::SUCCESS;
    }
}
