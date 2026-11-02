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
            new Seat(6, 2, 1), // outside the grid
        ]);

        $arranged = $plan->arrangedFor([1, 3, 4, 5, 6]);

        $this->assertSame([[1, 1, 1], [3, 1, 3]], self::seats($arranged));
        $this->assertSame([4, 5, 6], $arranged->unseated([1, 3, 4, 5, 6]));
        $this->assertSame([1, 4], self::gridSize($arranged), 'the grid does not grow');
    }

    public function testTheMasterSeatingIsClosedUpToTheLeft(): void
    {
        $current = new SeatingPlan(2, new GridSize(1, 3), false, [new Seat(9, 1, 1)], true);
        // Row 1: 1 . 3 . . 6; row 2: 7 and 8 do not take part; row 3: . . 10
        $master = new SeatingPlan(SeatingPlan::MASTER, new GridSize(3, 6), true, [
            new Seat(6, 1, 6),
            new Seat(1, 1, 1),
            new Seat(3, 1, 3),
            new Seat(7, 2, 2),
            new Seat(8, 2, 4),
            new Seat(10, 3, 3),
        ]);

        $plan = $current->fromMaster($master, [1, 3, 6, 9, 10, 11]);

        $this->assertSame([2, false], [$plan->projectId, $plan->seatOrganizers]);
        $this->assertSame([[1, 1, 1], [3, 1, 2], [6, 1, 3], [10, 2, 1]], self::seats($plan));
        $this->assertSame([2, 3], self::gridSize($plan), 'empty rows and columns are removed');
        $this->assertSame([9, 11], $plan->unseated([1, 3, 6, 9, 10, 11]), 'people not in the master are not seated');
    }

    public function testInsertsAndDeletesRowsAndColumns(): void
    {
        $plan = new SeatingPlan(1, new GridSize(2, 3), true, [new Seat(1, 1, 1), new Seat(2, 2, 2), new Seat(3, 2, 3)]);

        $this->assertSame([[1, 1, 1], [2, 3, 2], [3, 3, 3]], self::seats($plan->insertRow(2)));
        $this->assertSame([3, 3], self::gridSize($plan->insertRow(3)), 'a row at the back');
        $this->assertSame([[1, 1, 1], [2, 2, 3], [3, 2, 4]], self::seats($plan->insertColumn(2)));
        $this->assertSame([[1, 1, 1], [2, 2, 2], [3, 2, 3]], self::seats($plan->insertColumn(4)));

        $deleted = $plan->deleteRow(1);
        $this->assertSame([[2, 1, 2], [3, 1, 3]], self::seats($deleted));
        $this->assertSame([1, 3], self::gridSize($deleted));
        $this->assertSame([[1, 1, 1], [3, 2, 2]], self::seats($plan->deleteColumn(2)));
        $this->assertSame([2, 2], self::gridSize($plan->deleteColumn(2)));
    }

    public function testInsertingBetweenMovesPeopleTowardsTheNearestEmptySeat(): void
    {
        // Row 1: 1 2 . 3 4 5 .
        $plan = new SeatingPlan(1, new GridSize(2, 7), true, [
            new Seat(1, 1, 1),
            new Seat(2, 1, 2),
            new Seat(3, 1, 4),
            new Seat(4, 1, 5),
            new Seat(5, 1, 6),
            new Seat(9, 2, 1),
        ]);

        // Between 4 and 5: the gap at the right is nearer.
        $this->assertSame(
            [[1, 1, 1], [2, 1, 2], [3, 1, 4], [4, 1, 5], [5, 1, 7], [9, 2, 1], [8, 1, 6]],
            self::seats($plan->insertBetween(8, 1, 6))
        );
        // Between 3 and 4: the gap at the left is nearer.
        $this->assertSame(
            [[1, 1, 1], [2, 1, 2], [3, 1, 3], [4, 1, 5], [5, 1, 6], [9, 2, 1], [8, 1, 4]],
            self::seats($plan->insertBetween(8, 1, 5))
        );
        // Between 1 and 2: one person moves to either side, so they move to the right.
        $this->assertSame(
            [[1, 1, 1], [2, 1, 3], [3, 1, 4], [4, 1, 5], [5, 1, 6], [8, 1, 2]],
            self::seats($plan->insertBetween(8, 1, 2)->arrangedFor([1, 2, 3, 4, 5, 8]))
        );
        // Person 9 moves from row 2 into row 1, between 2 and 3: the empty seat is right there.
        $this->assertSame(
            [[1, 1, 1], [2, 1, 2], [3, 1, 4], [4, 1, 5], [5, 1, 6], [9, 1, 3]],
            self::seats($plan->insertBetween(9, 1, 3))
        );
    }

    public function testInsertingIntoAFullRowAddsAColumn(): void
    {
        $plan = new SeatingPlan(1, new GridSize(1, 3), true, [new Seat(1, 1, 1), new Seat(2, 1, 2), new Seat(3, 1, 3)]);

        $inserted = $plan->insertBetween(4, 1, 2);

        $this->assertSame([1, 4], self::gridSize($inserted));
        $this->assertSame([[1, 1, 1], [2, 1, 3], [3, 1, 4], [4, 1, 2]], self::seats($inserted));
        $this->assertSame(
            [[2, 1, 1], [3, 1, 3], [1, 1, 2]],
            self::seats($plan->insertBetween(1, 1, 3)),
            'moving within the row leaves an empty seat right there'
        );
    }

    /**
     * @return list<array{int, int, int}>
     */
    private static function seats(SeatingPlan $plan): array
    {
        return \array_map(static fn (Seat $seat): array => [$seat->userId, $seat->row, $seat->column], $plan->seats);
    }

    /**
     * @return array{int, int}
     */
    private static function gridSize(SeatingPlan $plan): array
    {
        return [$plan->grid->rows, $plan->grid->columns];
    }
}
