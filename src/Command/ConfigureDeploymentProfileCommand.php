<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Platform\Application\ApplyDeploymentProfile;
use Mediarama\Platform\Domain\DeploymentProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mediarama:setup:deployment-profile',
    description: 'Apply safe Mediarama public-publishing and search-index defaults.',
)]
final class ConfigureDeploymentProfileCommand extends Command
{
    public function __construct(private readonly ApplyDeploymentProfile $apply)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'profile',
            InputArgument::OPTIONAL,
            'private_workspace, public_publishing or internal_isolated',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $value = $input->getArgument('profile');

        if ($value === null) {
            if (!$input->isInteractive()) {
                $io->error('A deployment profile is required in non-interactive mode.');

                return Command::INVALID;
            }

            $value = $io->choice(
                'Choose the Mediarama deployment profile',
                [
                    DeploymentProfile::PrivateWorkspace->value,
                    DeploymentProfile::PublicPublishing->value,
                    DeploymentProfile::InternalIsolated->value,
                ],
                DeploymentProfile::PrivateWorkspace->value,
            );
        }

        if (!is_string($value)) {
            $io->error('Invalid deployment profile.');

            return Command::INVALID;
        }

        $profile = DeploymentProfile::tryFrom($value);
        if ($profile === null) {
            $io->error(sprintf(
                'Unknown deployment profile "%s". Expected private_workspace, public_publishing or internal_isolated.',
                $value,
            ));

            return Command::INVALID;
        }

        $settings = ($this->apply)($profile);

        $io->success(sprintf(
            'Applied %s: public publishing %s, search indexing default %s.',
            $profile->value,
            $settings->publicPublishingEnabled ? 'enabled' : 'disabled',
            $settings->searchIndexDefault->value,
        ));

        if ($profile === DeploymentProfile::InternalIsolated) {
            $io->note('This profile does not configure a firewall, VPN or network isolation.');
        }

        return Command::SUCCESS;
    }
}
