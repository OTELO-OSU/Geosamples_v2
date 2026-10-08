<?php

namespace App\Tests\Unit;

use App\Exception\ApiException;
use App\Service\FieldSchema;
use PHPUnit\Framework\TestCase;

final class FieldSchemaTest extends TestCase
{
    private FieldSchema $schema;
    private array $fields;

    protected function setUp(): void
    {
        $this->schema = new FieldSchema();
        $this->fields = $this->schema->normalizeDefinitions([
            ['key' => 'lithology', 'label' => 'Lithologie', 'type' => 'select', 'options' => ['Granite', 'Grès'], 'required' => true],
            ['key' => 'ores', 'label' => 'Minéralisation', 'type' => 'multiselect', 'options' => ['Sulfures', 'Oxydes']],
            ['key' => 'ni', 'label' => 'Nickel', 'type' => 'number'],
            ['key' => 'core', 'label' => 'Carotte', 'type' => 'boolean'],
            ['key' => 'temp', 'label' => 'Température', 'type' => 'number', 'scope' => 'point'],
        ]);
    }

    public function testCoercionIsLenientButStrictOnLists(): void
    {
        [$v, $e] = $this->schema->validateValues($this->fields, [
            'lithology' => 'gres', 'ores' => 'sulfures; Oxydes', 'ni' => '12,5', 'core' => 'oui',
        ], 'dataset');
        self::assertSame([], $e);
        self::assertSame(['lithology' => 'Grès', 'ores' => ['Sulfures', 'Oxydes'], 'ni' => 12.5, 'core' => true], $v);
    }

    public function testErrorsAndScopes(): void
    {
        [, $e] = $this->schema->validateValues($this->fields, ['ores' => 'Or', 'ni' => 'beaucoup', 'temp' => 'x'], 'dataset');
        self::assertArrayHasKey('properties.lithology', $e);
        self::assertArrayHasKey('properties.ores', $e);
        self::assertArrayHasKey('properties.ni', $e);
        self::assertArrayNotHasKey('properties.temp', $e, 'les champs « point » ne sont pas contrôlés au niveau du jeu');
    }

    public function testReservedAndDuplicateKeysRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->schema->normalizeDefinitions([['key' => 'latitude', 'label' => 'x'], ['key' => 'a', 'label' => 'a'], ['key' => 'a', 'label' => 'b']]);
    }
}
