<?php

declare(strict_types=1);

namespace Orchestra\Seating\Tests\Unit;

use Orchestra\Seating\Export\SeatingPdf;
use PHPUnit\Framework\TestCase;

final class SeatingPdfTest extends TestCase
{
    public function testSeatsShrinkSoThatTheGridFitsOnOnePage(): void
    {
        $this->assertSame(28.0, SeatingPdf::seatSize(3, 5), 'small grids keep the largest seats');
        $this->assertSame(24.1, SeatingPdf::seatSize(4, 11), 'wide grids fit the page width');
        $this->assertSame(16.9, SeatingPdf::seatSize(8, 11), 'deep grids fit the page height');
        $this->assertSame(10.0, SeatingPdf::seatSize(30, 30), 'seats stay readable');
    }
}
