<?php

namespace App\Command;

use App\Entity\SiteMembership;
use App\Entity\User;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Crée ou met à jour un compte (amorçage du premier administrateur)')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private UserRepository $users,
        private SiteRepository $sites,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Mot de passe local (sinon : compte fédéré uniquement)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Administrateur global')
            ->addOption('firstname', null, InputOption::VALUE_REQUIRED, '', '')
            ->addOption('lastname', null, InputOption::VALUE_REQUIRED, '', '')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Slug du site sur lequel donner un rôle')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'responsable|utilisateur', SiteMembership::ROLE_CONTRIBUTOR);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $user = $this->users->findOneByEmail($email) ?? new User($email);
        if ($input->getOption('firstname')) { $user->setFirstname($input->getOption('firstname')); }
        if ($input->getOption('lastname')) { $user->setLastname($input->getOption('lastname')); }
        if ($input->getOption('admin')) { $user->setAdmin(true); }
        if ($pwd = $input->getOption('password')) {
            $user->setPassword($this->hasher->hashPassword($user, $pwd));
        }
        $this->em->persist($user);
        if ($slug = $input->getOption('site')) {
            $site = $this->sites->findOneBy(['slug' => $slug]);
            if (!$site) {
                $io->error("Site $slug introuvable");
                return Command::FAILURE;
            }
            $role = $input->getOption('role');
            $m = $user->getId() ? $user->getMembership($site) : null;
            if ($m) {
                $m->setRole($role)->setStatus(SiteMembership::STATUS_ACTIVE);
            } else {
                $this->em->persist(new SiteMembership($site, $user, $role));
            }
        }
        $this->em->flush();
        $io->success("Compte $email enregistré" . ($user->isAdmin() ? ' (administrateur)' : ''));
        return Command::SUCCESS;
    }
}
