<?php

namespace App\Repository;

use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Site> */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Site::class); }

    public function findOneByHost(string $host): ?Site
    {
        $host = mb_strtolower($host);
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM site WHERE jsonb_exists(hostnames::jsonb, :h) LIMIT 1',
            ['h' => $host]
        );
        return $id ? $this->find($id) : null;
    }

    /** @return Site[] */
    public function findPublished(): array
    {
        return $this->findBy(['published' => true], ['title' => 'ASC']);
    }
}
