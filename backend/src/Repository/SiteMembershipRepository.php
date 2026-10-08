<?php

namespace App\Repository;

use App\Entity\Site;
use App\Entity\SiteMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SiteMembership> */
class SiteMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, SiteMembership::class); }

    /** @return SiteMembership[] */
    public function findForSite(Site $site): array
    {
        return $this->createQueryBuilder('m')->join('m.user', 'u')->addSelect('u')
            ->where('m.site = :s')->setParameter('s', $site)
            ->orderBy('m.status', 'DESC')->addOrderBy('u.lastname', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return SiteMembership[] responsables actifs (destinataires des notifications) */
    public function findManagers(Site $site): array
    {
        return $this->findBy(['site' => $site, 'role' => SiteMembership::ROLE_MANAGER, 'status' => SiteMembership::STATUS_ACTIVE]);
    }
}
