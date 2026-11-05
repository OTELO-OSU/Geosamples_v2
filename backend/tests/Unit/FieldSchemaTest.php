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
            ['key' => 'lithology', 'label' => 'Lithology', 'type' => 'select', 'options' => ['Granite', 'Sandstone'], 'required' => true],
            ['key' => 'ores', 'label' => 'Mineralization', 'type' => 'multiselect', 'options' => ['Sulfides', 'Oxides']],
            ['key' => 'ni', 'label' => 'Nickel', 'type' => 'number'],
            ['key' => 'core', 'label' => 'Core', 'type' => 'boolean'],
            ['key' => 'temp', 'label' => 'Temperature', 'type' => 'number', 'scope' => 'point'],
        ]);
    }

    public function testCoercionIsLenientButStrictOnLists(): void
    {
        [$v, $e] = $this->schema->validateValues($this->fields, [
            'lithology' => 'sandstone', 'ores' => 'sulfides; Oxides', 'ni' => '12,5', 'core' => 'yes',
        ], 'dataset');
        self::assertSame([], $e);
        self::assertSame(['lithology' => 'Sandstone', 'ores' => ['Sulfides', 'Oxides'], 'ni' => 12.5, 'core' => true], $v);
    }

    public function testErrorsAndScopes(): void
    {
        [, $e] = $this->schema->validateValues($this->fields, ['ores' => 'Gold', 'ni' => 'many', 'temp' => 'x'], 'dataset');
        self::assertArrayHasKey('properties.lithology', $e);
        self::assertArrayHasKey('properties.ores', $e);
        self::assertArrayHasKey('properties.ni', $e);
        self::assertArrayNotHasKey('properties.temp', $e, 'point fields are not validated at the dataset level');
    }

    public function testReservedAndDuplicateKeysRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->schema->normalizeDefinitions([['key' => 'latitude', 'label' => 'x'], ['key' => 'a', 'label' => 'a'], ['key' => 'a', 'label' => 'b']]);
    }
}
