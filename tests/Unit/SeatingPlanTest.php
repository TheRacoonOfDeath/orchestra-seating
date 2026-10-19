<?php

declare(strict_types=1);

namespace Orchestra\Seating\Tests\Unit;

use Orchestra\Seating\Seating\GridSize;
use Orchestra\Seating\Seating\Seat;
use Orchestra\Seating\Seating\SeatingPlan;
use PHPUnit\Framework\TestCase;

final class SeatingPlanTest extends TestCase
{
    public function testDropsSeatsOfPeopleWhoNoLongerTakePart(): void
    {
        $plan = new SeatingPlan(1, new GridSize(1, 4), true, [
            new Seat(1, 1, 1),
            new Seat(2, 1, 2), // no longer takes part
            new Seat(3, 1, 3),
            new Seat(5, 1, 3), // the same cell: the first seat wins
            new Seat(6, 3, 1), // outside the grid, even after adding a row
        ]);

        $arranged = $plan->arrangedFor([1, 3, 4, 5, 6]);

        $this->assertSame([1, 3], \array_map(static fn (Seat $seat): int => $seat->userId, $arranged->seats));
        $this->assertSame([4, 5, 6], $arranged->unseated([1, 3, 4, 5, 6]));
        $this->assertSame(2, $arranged->grid->rows, 'a row is added so that five people fit');
    }

    public function testImportKeepsTheSeatsOfPeopleInBothProjects(): void
    {
        $current = new SeatingPlan(2, new GridSize(1, 4), false, [new Seat(3, 1, 1)], true);
        $source = new SeatingPlan(1, new GridSize(2, 3), true, [
            new Seat(1, 2, 3),
            new Seat(2, 1, 1), // does not take part in project 2
        ]);

        $imported = $current->importedFrom($source, [1, 3]);

        $this->assertSame([2, false], [$imported->projectId, $imported->seatOrganizers]);
        $this->assertSame([[2, 3]], [[$imported->grid->rows, $imported->grid->columns]]);
        $this->assertSame([1], \array_map(static fn (Seat $seat): int => $seat->userId, $imported->seats));
        $this->assertSame([3], $imported->unseated([1, 3]), 'people new to the seating are not seated');
        $this->assertNull($imported->at(1, 1), 'the seats of people who do not take part stay empty');
    }
}
