<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, User::class); }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if ($user instanceof User) {
            $user->setPassword($newHashedPassword);
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /** @return User[] */
    public function search(?string $q, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.lastname', 'ASC')->addOrderBy('u.email', 'ASC')
            ->setMaxResults($limit)->setFirstResult($offset);
        if ($q) {
            $qb->andWhere('LOWER(u.email) LIKE :q OR LOWER(u.firstname) LIKE :q OR LOWER(u.lastname) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        return $qb->getQuery()->getResult();
    }
}
