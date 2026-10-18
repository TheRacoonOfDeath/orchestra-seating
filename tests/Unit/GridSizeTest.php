<?php

declare(strict_types=1);

namespace Orchestra\Seating\Tests\Unit;

use Orchestra\Seating\Seating\GridSize;
use PHPUnit\Framework\TestCase;

final class GridSizeTest extends TestCase
{
    public function testStartsWithASeatForEveryoneWiderThanDeep(): void
    {
        $expected = [0 => [1, 4], 1 => [1, 4], 12 => [3, 5], 40 => [5, 8], 80 => [8, 11]];
        foreach ($expected as $people => [$rows, $columns]) {
            $grid = GridSize::initial($people);

            $this->assertSame([$rows, $columns], [$grid->rows, $grid->columns], "$people people");
            $this->assertGreaterThanOrEqual($people, $grid->cells());
        }
    }

    public function testAddsRowsUntilEveryoneFits(): void
    {
        $grid = (new GridSize(2, 4))->fitting(11);

        $this->assertSame([3, 4], [$grid->rows, $grid->columns]);
        $this->assertSame([2, 4], [(new GridSize(2, 4))->fitting(3)->rows, 4], 'never smaller');
    }
}
