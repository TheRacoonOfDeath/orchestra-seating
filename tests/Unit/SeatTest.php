<?php

declare(strict_types=1);

namespace Orchestra\Seating\Tests\Unit;

use Orchestra\Seating\Seating\Seat;
use PHPUnit\Framework\TestCase;

final class SeatTest extends TestCase
{
    public function testRowsAreNamedWithLettersFromTheFront(): void
    {
        $expected = [1 => 'A', 2 => 'B', 26 => 'Z', 27 => 'AA', 30 => 'AD', 52 => 'AZ', 53 => 'BA'];
        foreach ($expected as $row => $name) {
            $this->assertSame($name, Seat::rowName($row), "row $row");
        }
    }
}
