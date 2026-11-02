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

    public function testAppliesTheMasterSeating(): void
    {
        $master = new SeatingPlan(SeatingPlan::MASTER, new GridSize(2, 5), true, [
            new Seat(1, 2, 5),
            new Seat(2, 2, 2),
            new Seat(4, 1, 3), // does not take part
        ], true);
        $input = ['op' => 'apply_master', 'seat' => [1 => '2-2']];
        [$plan, $errors] = $this->apply($input, static fn (): SeatingPlan => $master);

        $this->assertSame([], $errors);
        $this->assertSame(9, $plan->projectId);
        $this->assertSame([[2, 1, 1], [1, 1, 2]], self::seats($plan), 'the submitted seats are replaced');
        $this->assertSame([1, 2], self::gridSize($plan));
        $this->assertSame([3], $plan->unseated([1, 2, 3]));
    }

    public function testAppliesOnlyAMasterSeatingWithSomeoneWhoTakesPart(): void
    {
        $this->assertSame(['There is no master seating yet.'], $this->apply(['op' => 'apply_master'])[1]);
        $this->assertSame(
            ['There is no master seating yet.'],
            SeatingInput::apply(['op' => 'apply_master'], $this->plan, static fn (): array => [1], null)[1],
            'not in the editor of the master seating'
        );
        $master = new SeatingPlan(SeatingPlan::MASTER, new GridSize(1, 4), true, [new Seat(4, 1, 1)], true);
        [$plan, $errors] = $this->apply(['op' => 'apply_master'], static fn (): SeatingPlan => $master);

        $this->assertSame(['Nobody in the master seating takes part.'], $errors);
        $this->assertSame($this->plan, $plan);
    }

    public function testInsertsAndDeletesRowsAndColumns(): void
    {
        [$plan] = $this->apply(['op' => 'insert_row-1', 'seat' => [1 => '1-1', 2 => '2-3']]);
        $this->assertSame([3, 4], self::gridSize($plan));
        $this->assertSame([[1, 2, 1], [2, 3, 3]], self::seats($plan), 'the submitted seats move back');

        [$plan] = $this->apply(['op' => 'delete_column-1', 'seat' => [1 => '1-1', 2 => '2-3']]);
        $this->assertSame([2, 3], self::gridSize($plan));
        $this->assertSame([[2, 2, 2]], self::seats($plan), 'the people of the column are not seated');

        $this->assertSame([2, 5], self::gridSize($this->apply(['op' => 'insert_column-5'])[0]));
    }

    public function testRejectsGridChangesOutsideTheGridAndItsLimits(): void
    {
        $this->assertSame(['This row or column does not exist.'], $this->apply(['op' => 'insert_row-4'])[1]);
        $this->assertSame(['This row or column does not exist.'], $this->apply(['op' => 'delete_column-5'])[1]);
        $this->assertSame(['This row or column does not exist.'], $this->apply(['op' => 'delete_row-0'])[1]);

        $single = new SeatingPlan(9, new GridSize(1, 30), true, [], true);
        $people = static fn (): array => [1];
        $this->assertSame(
            ['The last row or column cannot be deleted.'],
            SeatingInput::apply(['op' => 'delete_row-1'], $single, $people, null)[1]
        );
        $this->assertSame(
            ['The grid cannot get any larger.'],
            SeatingInput::apply(['op' => 'insert_column-1'], $single, $people, null)[1]
        );
    }

    public function testInsertsAPersonBetweenTwoOthers(): void
    {
        $input = ['op' => 'insert_between', 'insert_person' => '3', 'insert_at' => '1-2'];
        [$plan, $errors] = $this->apply($input + ['seat' => [1 => '1-1', 2 => '1-2']]);

        $this->assertSame([], $errors);
        $this->assertSame([[1, 1, 1], [2, 1, 3], [3, 1, 2]], self::seats($plan));

        $this->assertSame(
            ['The person cannot be seated there.'],
            $this->apply(['insert_person' => '99'] + $input)[1],
            'only people of the seating'
        );
        $this->assertSame(['The person cannot be seated there.'], $this->apply(['insert_at' => '3-1'] + $input)[1]);
    }

    public function testDoesNotGrowTheGrid(): void
    {
        $plan = new SeatingPlan(9, new GridSize(1, 1), true, [], true);
        [$saved] = SeatingInput::apply(['seat' => [1 => '1-1']], $plan, static fn (): array => [1, 2, 3], null);

        $this->assertSame([1, 1], self::gridSize($saved));
        $this->assertSame([2, 3], $saved->unseated([1, 2, 3]));
    }

    public function testLeavingOutOrganizersDropsTheirSeats(): void
    {
        $people = static fn (bool $organizers): array => $organizers ? [1, 2] : [1];
        [$plan] = SeatingInput::apply(['seat' => [1 => '1-1', 2 => '1-2']], $this->plan, $people, null);

        $this->assertFalse($plan->seatOrganizers);
        $this->assertSame([[1, 1, 1]], self::seats($plan));
    }

    public function testClearsTheSeating(): void
    {
        $this->assertSame([], $this->apply(['op' => 'clear', 'seat' => [1 => '1-1']])[0]->seats);
    }

    public function testVoiceChangesOnlyContainChangedSelects(): void
    {
        $input = [
            'voice' => [10 => [1 => '7', 2 => '', 3 => '8'], 11 => [1 => '9']],
            'voice_was' => [10 => [1 => '6', 2 => '5', 3 => '8'], 11 => [1 => '']],
        ];

        $this->assertSame(
            [
                ['pieceId' => 10, 'userId' => 1, 'partId' => 7],
                ['pieceId' => 10, 'userId' => 2, 'partId' => null],
                ['pieceId' => 11, 'userId' => 1, 'partId' => 9],
            ],
            SeatingInput::voiceChanges($input, [1, 2, 3], [10, 11])
        );
    }

    public function testVoiceChangesIgnoreUnknownPeopleAndPiecesAndInvalidValues(): void
    {
        $input = [
            'voice' => [10 => [1 => '7', 4 => '7', 2 => 'x'], 12 => [1 => '7']],
            'voice_was' => [10 => [1 => '6', 4 => '6', 2 => '6'], 12 => [1 => '6']],
        ];

        $this->assertSame(
            [['pieceId' => 10, 'userId' => 1, 'partId' => 7]],
            SeatingInput::voiceChanges($input, [1, 2], [10])
        );
        $this->assertSame([], SeatingInput::voiceChanges(['voice' => [10 => [1 => '7']]], [1], [10]));
    }

    /**
     * @param array<string, mixed> $input
     * @param (\Closure(): ?SeatingPlan)|null $master
     * @return array{SeatingPlan, list<string>}
     */
    private function apply(array $input, ?\Closure $master = null): array
    {
        $people = static fn (): array => [1, 2, 3];

        return SeatingInput::apply(
            $input + ['seat_organizers' => '1'],
            $this->plan,
            $people,
            $master ?? static fn (): ?SeatingPlan => null
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
