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
 * Create empty sites (title = slug) to prepare for data migration.
 * Configure settings (title, theme, map, fields, etc.) later in administration.
 *
 *   php bin/console app:site:create acev athabasca mobised
 */
#[AsCommand(name: 'app:site:create', description: 'Create one or more sites from their slugs')]
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
        $this->addArgument('slugs', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Slug(s) of the sites to create');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;
        $failed = false;

        foreach ((array) $input->getArgument('slugs') as $raw) {
            $slug = mb_strtolower(trim((string) $raw));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) {
                $io->warning("$raw: invalid identifier (lowercase letters, digits, hyphens) — ignored");
                $failed = true;
                continue;
            }
            if ($this->sites->findOneBy(['slug' => $slug])) {
                $io->writeln("$slug: already exists");
                continue;
            }
            $this->em->persist(new Site($slug, $slug));
            $io->writeln("$slug: created");
            $created++;
        }
        $this->em->flush();
        $io->success("$created site(s) created");

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}