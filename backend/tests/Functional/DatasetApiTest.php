<?php

namespace App\Tests\Functional;

use App\Entity\Dataset;

final class DatasetApiTest extends ApiTestCase
{
    private function payload(array $over = []): array
    {
        return array_replace([
            'title' => 'Jeu de test', 'keywords' => ['test'], 'properties' => ['lithology' => 'Granite'],
            'points' => [['name' => 'P1', 'longitude' => 6.18, 'latitude' => 48.69, 'elevation' => 210, 'observedAt' => '2024-05-17', 'properties' => ['temp' => '12,4']]],
        ], $over);
    }

    public function testPublicSeesOnlyPublishedDatasets(): void
    {
        $this->seedDataset('Publié', Dataset::STATUS_PUBLISHED, 6.1, 48.7);
        $this->seedDataset('Brouillon', Dataset::STATUS_DRAFT, 6.2, 48.6);
        $list = $this->api('GET', '/api/sites/demo/datasets');
        self::assertSame(1, $list['total']);
        self::assertSame('Publié', $list['items'][0]['title']);
        $map = $this->api('GET', '/api/sites/demo/map?zoom=8');
        self::assertSame('points', $map['mode']);
        self::assertCount(1, $map['points']);
    }

    public function testSiteResolvedByHostname(): void
    {
        $this->client->request('GET', '/api/context', [], [], ['HTTP_HOST' => 'demo.test']);
        $ctx = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('demo', $ctx['site']['slug']);
        self::assertTrue($ctx['boundByHost']);
    }

    public function testContributorSubmitsAndManagerPublishes(): void
    {
        $token = $this->login('user@test.fr');
        $d = $this->api('POST', '/api/sites/demo/datasets', $this->payload(['submit' => true]), $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('submitted', $d['status']);
        self::assertSame(12.4, $d['points'][0]['properties']['temp']);

        $this->api('POST', "/api/sites/demo/datasets/{$d['uuid']}/status", ['action' => 'publish'], $token);
        self::assertResponseStatusCodeSame(403);

        $manager = $this->login('resp@test.fr');
        $r = $this->api('POST', "/api/sites/demo/datasets/{$d['uuid']}/status", ['action' => 'publish'], $manager);
        self::assertSame('published', $r['status']);

        // Un contributeur qui modifie un jeu publié le renvoie en validation
        $r = $this->api('PUT', "/api/sites/demo/datasets/{$d['uuid']}", $this->payload(['title' => 'Modifié']), $token);
        self::assertSame('submitted', $r['status']);
    }

    public function testValidationErrorsAreDetailed(): void
    {
        $token = $this->login('user@test.fr');
        $r = $this->api('POST', '/api/sites/demo/datasets', $this->payload(['title' => '', 'properties' => [], 'points' => [['longitude' => 300, 'latitude' => 10]]]), $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('title', $r['details']);
        self::assertArrayHasKey('properties.lithology', $r['details']);
        self::assertArrayHasKey('points[0].longitude', $r['details']);
    }

    public function testNonMemberCannotDepositNorManage(): void
    {
        $token = $this->login('autre@test.fr');
        $this->api('POST', '/api/sites/demo/datasets', $this->payload(), $token);
        self::assertResponseStatusCodeSame(403);
        $this->api('PUT', '/api/sites/demo', ['title' => 'Piraté'], $token);
        self::assertResponseStatusCodeSame(403);
        $this->api('GET', '/api/admin/users', null, $token);
        self::assertResponseStatusCodeSame(403);
        // … mais peut demander à contribuer
        $m = $this->api('POST', '/api/sites/demo/join', ['message' => 'Bonjour'], $token);
        self::assertSame('pending', $m['status']);
    }

    public function testFiltersAndFacets(): void
    {
        $this->seedDataset('A', Dataset::STATUS_PUBLISHED, 6.1, 48.7, 'Granite');
        $this->seedDataset('B', Dataset::STATUS_PUBLISHED, 7.1, 47.7, 'Grès');
        self::assertSame(1, $this->api('GET', '/api/sites/demo/datasets?f%5Blithology%5D=Gr%C3%A8s')['total']);
        self::assertSame(1, $this->api('GET', '/api/sites/demo/datasets?bbox=6,48,6.5,49')['total']);
        self::assertSame(0, $this->api('GET', '/api/sites/demo/datasets?from=2021')['total']);
        $f = $this->api('GET', '/api/sites/demo/facets');
        self::assertSame(2, $f['datasets']);
        self::assertCount(2, $f['fields']['lithology']['values']);
    }

    public function testBulkImportIsAllOrNothingAndSupportsUpdate(): void
    {
        $token = $this->login('user@test.fr');
        $rows = [
            ['dataset_ref' => 'S1', 'title' => 'Station 1', 'keywords' => 'eau', 'lithology' => 'Granite', 'longitude' => '6.1', 'latitude' => '48.7', 'observed_at' => '2023-01', 'temp' => '4'],
            ['dataset_ref' => 'S1', 'longitude' => '6.1', 'latitude' => '48.7', 'observed_at' => '2023-02', 'temp' => '5'],
            ['title' => 'Mauvais', 'keywords' => 'x', 'lithology' => 'Marbre', 'longitude' => '6', 'latitude' => '48'],
        ];
        $p = $this->api('POST', '/api/sites/demo/import/preview', ['rows' => $rows], $token);
        self::assertSame(1, $p['valid']);
        self::assertSame(1, $p['invalid']);
        $this->api('POST', '/api/sites/demo/import/commit', ['rows' => $rows], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM dataset'));

        $res = $this->api('POST', '/api/sites/demo/import/commit', ['rows' => \array_slice($rows, 0, 2)], $token);
        self::assertCount(1, $res['created']);
        $uuid = $res['created'][0];
        $exp = $this->api('GET', "/api/sites/demo/export?ids=$uuid", null, $token);
        self::assertCount(2, $exp['rows']);
        self::assertSame($uuid, $exp['rows'][0]['dataset_id']);

        $exp['rows'][0]['title'] = 'Station 1 corrigée';
        $res = $this->api('POST', '/api/sites/demo/import/commit', ['rows' => $exp['rows']], $token);
        self::assertSame([$uuid], $res['updated']);
        $d = $this->api('GET', "/api/sites/demo/datasets/$uuid", null, $token);
        self::assertSame('Station 1 corrigée', $d['title']);
        self::assertCount(2, $d['points']);
    }
}
