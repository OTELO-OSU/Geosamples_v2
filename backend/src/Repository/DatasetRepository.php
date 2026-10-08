<?php

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Dataset> */
class DatasetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Dataset::class); }

    public function findOneInSite(Site $site, string $uuid): ?Dataset
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }
        return $this->findOneBy(['site' => $site, 'uuid' => Uuid::fromString($uuid)]);
    }

    /** @param int[] $ids @return Dataset[] */
    public function findByIdsOrdered(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = $this->createQueryBuilder('d')
            ->leftJoin('d.owner', 'o')->addSelect('o')
            ->where('d.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r->getId()] = $r;
        }
        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }
}
