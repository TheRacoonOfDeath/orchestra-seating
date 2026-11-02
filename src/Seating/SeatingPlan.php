<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

/**
 * The seating of a project, or the master seating (project ID 0): the grid, whether organizers
 * are seated, and the seats. It does not depend on a piece: the voices of every piece of the program
 * can be shown on it.
 */
final class SeatingPlan
{
    /** The project ID of the master seating, the standing seating of all members and organizers */
    public const MASTER = 0;

    /**
     * @param list<Seat> $seats
     */
    public function __construct(
        public readonly int $projectId,
        public readonly GridSize $grid,
        public readonly bool $seatOrganizers,
        public readonly array $seats,
        /** Whether the plan has been saved; a new plan is only a suggestion */
        public readonly bool $saved = false,
    ) {
    }

    public static function initial(int $projectId, int $people): self
    {
        return new self($projectId, GridSize::initial($people), true, []);
    }

    /**
     * @param list<Seat> $seats
     */
    public function with(
        ?GridSize $grid = null,
        ?array $seats = null,
        ?bool $seatOrganizers = null,
    ): self {
        return new self(
            $this->projectId,
            $grid ?? $this->grid,
            $seatOrganizers ?? $this->seatOrganizers,
            $seats ?? $this->seats,
            $this->saved
        );
    }

    public function seatOf(int $userId): ?Seat
    {
        foreach ($this->seats as $seat) {
            if ($seat->userId === $userId) {
                return $seat;
            }
        }

        return null;
    }

    public function at(int $row, int $column): ?Seat
    {
        foreach ($this->seats as $seat) {
            if ($seat->row === $row && $seat->column === $column) {
                return $seat;
            }
        }

        return null;
    }

    /**
     * Keeps the seats of the given people that are inside the grid, one person per cell. Seats of
     * people who no longer take part are dropped. The grid does not grow: people without a seat wait
     * in "Not seated".
     *
     * @param list<int> $userIds The people to seat
     */
    public function arrangedFor(array $userIds): self
    {
        $seats = [];
        $taken = [];
        foreach ($this->seats as $seat) {
            $cell = Seat::key($seat->row, $seat->column);
            $kept = \in_array($seat->userId, $userIds, true) && $this->grid->contains($seat->row, $seat->column);
            if ($kept && !isset($taken[$cell])) {
                $taken[$cell] = true;
                $seats[] = $seat;
            }
        }

        return $this->with(seats: $seats);
    }

    /**
     * The master seating for the people of this project: everyone who is seated there keeps their
     * row, every row is closed up to the left, and rows and columns nobody sits in are removed.
     * People who are not in the master seating are not seated.
     *
     * @param list<int> $userIds The people to seat
     */
    public function fromMaster(self $master, array $userIds): self
    {
        $rows = [];
        foreach ($master->seats as $seat) {
            if (\in_array($seat->userId, $userIds, true)) {
                $rows[$seat->row][$seat->column] = $seat->userId;
            }
        }
        \ksort($rows);

        $seats = [];
        $columns = 1;
        foreach (\array_values($rows) as $index => $row) {
            \ksort($row);
            foreach (\array_values($row) as $position => $userId) {
                $seats[] = new Seat($userId, $index + 1, $position + 1);
            }
            $columns = \max($columns, \count($row));
        }

        return $this->with(grid: new GridSize(\max(1, \count($rows)), $columns), seats: $seats);
    }

    /**
     * Adds a row in front of the given row (rows + 1 adds one at the back); the rows from there on
     * move one back.
     */
    public function insertRow(int $before): self
    {
        return $this->with(
            grid: new GridSize($this->grid->rows + 1, $this->grid->columns),
            seats: \array_map(
                static fn (Seat $seat): Seat => $seat->row >= $before
                    ? new Seat($seat->userId, $seat->row + 1, $seat->column) : $seat,
                $this->seats
            )
        );
    }

    /**
     * Adds a column left of the given column (columns + 1 adds one at the right); the columns from
     * there on move one to the right.
     */
    public function insertColumn(int $before): self
    {
        return $this->with(
            grid: new GridSize($this->grid->rows, $this->grid->columns + 1),
            seats: \array_map(
                static fn (Seat $seat): Seat => $seat->column >= $before
                    ? new Seat($seat->userId, $seat->row, $seat->column + 1) : $seat,
                $this->seats
            )
        );
    }

    /**
     * Removes a row; the people on it are no longer seated and the rows behind it move forward.
     */
    public function deleteRow(int $row): self
    {
        $seats = [];
        foreach ($this->seats as $seat) {
            if ($seat->row !== $row) {
                $seats[] = $seat->row > $row ? new Seat($seat->userId, $seat->row - 1, $seat->column) : $seat;
            }
        }

        return $this->with(grid: new GridSize($this->grid->rows - 1, $this->grid->columns), seats: $seats);
    }

    /**
     * Removes a column; the people on it are no longer seated and the columns right of it move left.
     */
    public function deleteColumn(int $column): self
    {
        $seats = [];
        foreach ($this->seats as $seat) {
            if ($seat->column !== $column) {
                $seats[] = $seat->column > $column ? new Seat($seat->userId, $seat->row, $seat->column - 1) : $seat;
            }
        }

        return $this->with(grid: new GridSize($this->grid->rows, $this->grid->columns - 1), seats: $seats);
    }

    /**
     * Seats a person in a row in front of the given seat, i.e. between the seats column − 1 and
     * column (columns + 1 is behind the last seat). The people between there and the nearest empty
     * seat of the row move one seat towards it; with the same distance on both sides, they move to
     * the right. A full row gets a new column at the right.
     */
    public function insertBetween(int $userId, int $row, int $column): self
    {
        $seats = \array_values(\array_filter($this->seats, static fn (Seat $seat): bool => $seat->userId !== $userId));
        $plan = $this->with(seats: $seats);

        $taken = [];
        foreach ($seats as $seat) {
            if ($seat->row === $row) {
                $taken[$seat->column] = true;
            }
        }
        $left = null;
        for ($empty = $column - 1; $empty >= 1; $empty--) {
            if (!isset($taken[$empty])) {
                $left = $empty;
                break;
            }
        }
        $right = null;
        for ($empty = $column; $empty <= $this->grid->columns; $empty++) {
            if (!isset($taken[$empty])) {
                $right = $empty;
                break;
            }
        }
        if ($right === null && $left === null) {
            $plan = $plan->with(grid: new GridSize($this->grid->rows, $this->grid->columns + 1));
            $right = $this->grid->columns + 1;
        }

        // The number of people who move: those between the insertion point and the empty seat.
        $moveLeft = $left === null ? null : $column - 1 - $left;
        $moveRight = $right === null ? null : $right - $column;
        if ($moveRight !== null && ($moveLeft === null || $moveRight <= $moveLeft)) {
            $shift = static fn (Seat $seat): Seat => $seat->row === $row && $seat->column >= $column
                && $seat->column < $right ? new Seat($seat->userId, $row, $seat->column + 1) : $seat;
            $target = $column;
        } else {
            $shift = static fn (Seat $seat): Seat => $seat->row === $row && $seat->column > $left
                && $seat->column < $column ? new Seat($seat->userId, $row, $seat->column - 1) : $seat;
            $target = $column - 1;
        }
        $seats = \array_map($shift, $plan->seats);
        $seats[] = new Seat($userId, $row, $target);

        return $plan->with(seats: $seats);
    }

    /**
     * @param list<int> $userIds
     * @return list<int> the people among them without a seat, in the given order
     */
    public function unseated(array $userIds): array
    {
        return \array_values(\array_filter($userIds, fn (int $id): bool => $this->seatOf($id) === null));
    }
}
