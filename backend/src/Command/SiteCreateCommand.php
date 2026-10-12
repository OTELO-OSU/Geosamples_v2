<?php

namespace App\Command;

use App\Entity\Site;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée des sites vides (titre = slug) pour préparer la reprise des données.
 * Les réglages (titre, thème, carte, champs...) se complètent ensuite dans l'administration.
 *
 *   php bin/console app:site:create acev athabasca mobised
 */
#[AsCommand(name: 'app:site:create', description: 'Crée un ou plusieurs sites à partir de leurs slugs')]
final class SiteCreateCommand extends Command
{
    public function __construct(
        private SiteRepository $sites,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slugs', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Slug(s) des sites à créer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;
        $failed = false;

        foreach ((array) $input->getArgument('slugs') as $raw) {
            $slug = mb_strtolower(trim((string) $raw));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) {
                $io->warning("$raw : identifiant invalide (minuscules, chiffres, tirets) — ignoré");
                $failed = true;
                continue;
            }
            if ($this->sites->findOneBy(['slug' => $slug])) {
                $io->writeln("$slug : existe déjà");
                continue;
            }
            $this->em->persist(new Site($slug, $slug));
            $io->writeln("$slug : créé");
            $created++;
        }
        $this->em->flush();
        $io->success("$created site(s) créé(s)");

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}