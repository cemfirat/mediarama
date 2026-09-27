<?php

declare(strict_types=1);

namespace Mediarama\Command;

use Mediarama\Platform\Application\FirstRunSetup;
use Mediarama\Platform\Application\SetupUnavailableException;
use Mediarama\Platform\Application\SetupValidationException;
use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\SetupCompletionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(
    name: 'mediarama:setup:bootstrap-admin',
    description: 'Create or recover the first administrator and complete first-run setup.',
)]
final class BootstrapAdministratorCommand extends Command
{
    public function __construct(private readonly FirstRunSetup $setup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Administrator username.')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Optional administrator email.')
            ->addOption(
                'profile',
                null,
                InputOption::VALUE_REQUIRED,
                'private_workspace, public_publishing or internal_isolated.',
                DeploymentProfile::PrivateWorkspace->value,
            )
            ->addOption(
                'password-stdin',
                null,
                InputOption::VALUE_NONE,
                'Read the administrator password from standard input instead of the interactive hidden prompt.',
            )
            ->addOption(
                'recover-inactive',
                null,
                InputOption::VALUE_NONE,
                'Deliberately allow a matching inactive existing system administrator to be reactivated.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profile = DeploymentProfile::tryFrom((string) $input->getOption('profile'));
        if ($profile === null) {
            $output->writeln('<error>Unknown deployment profile.</error>');

            return Command::INVALID;
        }

        $password = $this->readPassword($input, $output);
        if ($password === null) {
            return Command::INVALID;
        }

        try {
            $result = $this->setup->complete(
                (string) $input->getArgument('username'),
                $password,
                $this->nullableString($input->getOption('email')),
                $profile,
                SetupCompletionMethod::Cli,
                (bool) $input->getOption('recover-inactive'),
            );
        } catch (SetupValidationException|SetupUnavailableException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        if (!$result->shouldAuthenticate) {
            $output->writeln(sprintf(
                'Existing active administrator "%s" detected. Setup was marked completed without changing credentials or publication settings.',
                $result->username,
            ));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Setup completed for administrator "%s" with deployment profile "%s"%s.',
            $result->username,
            $result->profile->value,
            $result->claimedExistingIdentity ? ' using the existing administrator identity' : '',
        ));

        return Command::SUCCESS;
    }

    private function readPassword(InputInterface $input, OutputInterface $output): ?string
    {
        if ((bool) $input->getOption('password-stdin')) {
            $password = stream_get_contents(STDIN);
            if (!is_string($password)) {
                $output->writeln('<error>Unable to read password from standard input.</error>');

                return null;
            }

            return rtrim($password, "\r\n");
        }

        if (!$input->isInteractive()) {
            $output->writeln(
                '<error>Use --password-stdin for non-interactive bootstrap. Passwords are intentionally not accepted as command-line options.</error>'
            );

            return null;
        }

        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new \RuntimeException('Question helper is unavailable.');
        }

        $question = new Question('Administrator password: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $question);

        $confirmationQuestion = new Question('Confirm administrator password: ');
        $confirmationQuestion->setHidden(true);
        $confirmationQuestion->setHiddenFallback(false);
        $confirmation = $helper->ask($input, $output, $confirmationQuestion);

        if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $output->writeln('<error>Password confirmation does not match.</error>');

            return null;
        }

        return $password;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
