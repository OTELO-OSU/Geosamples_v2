<?php

namespace App\Tests\Unit;

use App\Service\DateParser;
use PHPUnit\Framework\TestCase;

final class DateParserTest extends TestCase
{
    public function testPartialDatesExpandToPeriodBounds(): void
    {
        self::assertSame('2021-01-01 00:00:00', DateParser::parse('2021')->format('Y-m-d H:i:s'));
        self::assertSame('2021-12-31 23:59:59', DateParser::parse('2021', true)->format('Y-m-d H:i:s'));
        self::assertSame('2024-02-29 23:59:59', DateParser::parse('2024-02', true)->format('Y-m-d H:i:s'));
    }

    public function testFrenchAndIsoFormats(): void
    {
        self::assertSame('2023-03-12', DateParser::parse('12/03/2023')->format('Y-m-d'));
        self::assertSame('2023-03-12 10:30', DateParser::parse('2023-03-12T10:30:00+00:00')->format('Y-m-d H:i'));
        self::assertSame('2023-03-15', DateParser::parse('45000')->format('Y-m-d'));
        self::assertNull(DateParser::parse(''));
    }

    public function testInvalidDateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateParser::parse('pas une date');
    }
}
