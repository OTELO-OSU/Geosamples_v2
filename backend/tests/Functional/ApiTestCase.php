<?php

namespace App\Tests\Functional;

use App\Entity\Dataset;
use App\Entity\SamplingPoint;
use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Base de test : schéma recréé, un site, trois comptes (admin, responsable, utilisateur). */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected Site $site;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        static::getContainer()->get('cache.rate_limiter')->clear();

        $hasher = static::getContainer()->get('security.user_password_hasher');
        $mk = function (string $email, bool $admin = false) use ($hasher) {
            $u = (new User($email))->setFirstname('T')->setLastname($email)->setAdmin($admin);
            $u->setPassword($hasher->hashPassword($u, 'motdepasse-test'));
            $this->em->persist($u);
            return $u;
        };
        $mk('admin@test.fr', true);
        $resp = $mk('resp@test.fr');
        $user = $mk('user@test.fr');
        $mk('autre@test.fr');

        $this->site = (new Site('demo', 'Démo'))->setHostnames(['demo.test'])->setFields([
            ['key' => 'lithology', 'label' => 'Lithologie', 'type' => 'select', 'options' => ['Granite', 'Grès'], 'required' => true, 'filter' => 'select', 'scope' => 'dataset'],
            ['key' => 'temp', 'label' => 'Température', 'type' => 'number', 'filter' => 'range', 'scope' => 'point'],
        ]);
        $this->em->persist($this->site);
        $this->em->persist(new SiteMembership($this->site, $resp, SiteMembership::ROLE_MANAGER));
        $this->em->persist(new SiteMembership($this->site, $user, SiteMembership::ROLE_CONTRIBUTOR));
        $this->em->flush();
    }

    protected function seedDataset(string $title, string $status, float $lon, float $lat, string $lith = 'Granite'): Dataset
    {
        $d = (new Dataset($this->site, null))->setTitle($title)->setStatus($status)->setProperties(['lithology' => $lith]);
        $d->addPoint((new SamplingPoint($lon, $lat))->setElevation(300)->setObservedAt(new \DateTimeImmutable('2020-06-01')));
        $this->em->persist($d);
        $this->em->flush();
        return $d;
    }

    protected function login(string $email): string
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => $email, 'password' => 'motdepasse-test']));
        self::assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    protected function api(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body) : null);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

}
