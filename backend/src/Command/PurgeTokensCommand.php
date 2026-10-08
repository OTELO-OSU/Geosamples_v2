<?php

namespace App\Command;

use App\Service\AuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:auth:purge', description: 'Supprime les refresh tokens expirés (à lancer par cron)')]
final class PurgeTokensCommand extends Command
{
    public function __construct(private AuthService $auth) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->auth->purgeExpired() . ' jeton(s) supprimé(s)');
        return Command::SUCCESS;
    }
}
