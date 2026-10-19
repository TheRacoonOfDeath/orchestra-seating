<?php

declare(strict_types=1);

namespace Orchestra\Seating\Tests\Unit;

use Orchestra\Seating\Seating\GridSize;
use Orchestra\Seating\Seating\Seat;
use Orchestra\Seating\Seating\SeatingInput;
use Orchestra\Seating\Seating\SeatingPlan;
use PHPUnit\Framework\TestCase;

final class SeatingInputTest extends TestCase
{
    private SeatingPlan $plan;

    protected function setUp(): void
    {
        $this->plan = new SeatingPlan(9, new GridSize(2, 4), true, [new Seat(1, 1, 1)], true);
    }

    public function testSeatsThePeople(): void
    {
        [$plan, $errors] = $this->apply(['seat' => [1 => '2-4', 2 => '1-1', 3 => '', 99 => '1-2']]);

        $this->assertSame([], $errors);
        $this->assertSame([[1, 2, 4], [2, 1, 1]], self::seats($plan));
        $this->assertTrue($plan->seatOrganizers);
    }

    public function testRejectsDoubleBookedAndOutsideSeats(): void
    {
        [$plan, $errors] = $this->apply(['seat' => [1 => '1-1', 2 => '1-1', 3 => '3-1']]);

        $this->assertSame(['Two people cannot sit on the same seat.', 'A seat is outside the grid.'], $errors);
        $this->assertSame($this->plan, $plan);
    }

    public function testImportsTheSeatingOfAnotherProject(): void
    {
        // People 1 and 2 took part in project 5, person 4 did not take part here; person 3 is new.
        $source = new SeatingPlan(5, new GridSize(1, 5), true, [
            new Seat(1, 1, 5),
            new Seat(2, 1, 1),
            new Seat(4, 1, 3),
        ], true);
        [$plan, $errors] = $this->apply(
            ['op' => 'import', 'import_project' => '5', 'seat' => [1 => '2-2']],
            static fn (int $id): ?SeatingPlan => $id === 5 ? $source : null
        );

        $this->assertSame([], $errors);
        $this->assertSame(9, $plan->projectId);
        $this->assertSame([[1, 1, 5], [2, 1, 1]], self::seats($plan), 'the submitted seats are replaced');
        $this->assertSame([3], $plan->unseated([1, 2, 3]));
        $this->assertSame([1, 5], self::gridSize($plan), 'the grid of the other project is taken over');
    }

    public function testImportingGrowsTheGridForEveryone(): void
    {
        $source = new SeatingPlan(5, new GridSize(1, 2), true, [new Seat(1, 1, 2)], true);
        [$plan] = $this->apply(['op' => 'import', 'import_project' => '5'], static fn (): SeatingPlan => $source);

        $this->assertSame([2, 2], self::gridSize($plan));
    }

    public function testImportsOnlyFromProjectsWithASeating(): void
    {
        [$plan, $errors] = $this->apply(['op' => 'import', 'import_project' => '6']);

        $this->assertSame(['Choose a project with a seating.'], $errors);
        $this->assertSame($this->plan, $plan);
    }

    public function testAddsAndRemovesRowsAndColumns(): void
    {
        $this->assertSame([3, 4], self::gridSize($this->apply(['op' => 'add_row'])[0]));
        $this->assertSame([2, 5], self::gridSize($this->apply(['op' => 'add_column'])[0]));
        $this->assertSame([2, 3], self::gridSize($this->apply(['op' => 'remove_column', 'seat' => [1 => '1-1']])[0]));
        $this->assertSame(
            ['Only an empty last row or column can be removed.'],
            $this->apply(['op' => 'remove_row', 'seat' => [1 => '2-1']])[1]
        );
    }

    public function testKeepsASeatForEveryone(): void
    {
        $plan = new SeatingPlan(9, new GridSize(1, 4), true, [], true);
        $input = ['op' => 'remove_column', 'seat_organizers' => '1'];
        [, $errors] = SeatingInput::apply($input, $plan, static fn (): array => [1, 2, 3, 4], self::noSource(...));

        $this->assertSame(['The grid needs a seat for everyone.'], $errors);
    }

    public function testLeavingOutOrganizersDropsTheirSeats(): void
    {
        $people = static fn (bool $organizers): array => $organizers ? [1, 2] : [1];
        [$plan] = SeatingInput::apply(['seat' => [1 => '1-1', 2 => '1-2']], $this->plan, $people, self::noSource(...));

        $this->assertFalse($plan->seatOrganizers);
        $this->assertSame([[1, 1, 1]], self::seats($plan));
    }

    public function testClearsTheSeating(): void
    {
        $this->assertSame([], $this->apply(['op' => 'clear', 'seat' => [1 => '1-1']])[0]->seats);
    }

    /**
     * @param array<string, mixed> $input
     * @param (\Closure(int): ?SeatingPlan)|null $source
     * @return array{SeatingPlan, list<string>}
     */
    private function apply(array $input, ?\Closure $source = null): array
    {
        $people = static fn (): array => [1, 2, 3];

        return SeatingInput::apply(
            $input + ['seat_organizers' => '1'],
            $this->plan,
            $people,
            $source ?? self::noSource(...)
        );
    }

    private static function noSource(int $projectId): ?SeatingPlan
    {
        return null;
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
