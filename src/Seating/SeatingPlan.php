<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

/**
 * The seating of a project: the grid, whether organizers are seated, and the seats. It does not
 * depend on a piece: the voices of every piece of the program can be shown on it.
 */
final class SeatingPlan
{
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
     * Keeps the seats of the given people that are inside the grid, one person per cell, and adds
     * rows until everyone fits. Seats of people who no longer take part are dropped.
     *
     * @param list<int> $userIds The people to seat
     */
    public function arrangedFor(array $userIds): self
    {
        $grid = $this->grid->fitting(\count($userIds));
        $seats = [];
        $taken = [];
        foreach ($this->seats as $seat) {
            $cell = Seat::key($seat->row, $seat->column);
            $kept = \in_array($seat->userId, $userIds, true) && $grid->contains($seat->row, $seat->column);
            if ($kept && !isset($taken[$cell])) {
                $taken[$cell] = true;
                $seats[] = $seat;
            }
        }

        return $this->with(grid: $grid, seats: $seats);
    }

    /**
     * The seating of another project for the people of this one: everyone who is seated there
     * gets the same seat, the seats of people who do not take part here stay empty, and new people
     * are not seated. The grid is taken over and grows if it is too small.
     *
     * @param list<int> $userIds The people to seat
     */
    public function importedFrom(self $source, array $userIds): self
    {
        $seats = \array_values(\array_filter(
            $source->seats,
            static fn (Seat $seat): bool => \in_array($seat->userId, $userIds, true)
        ));

        return $this->with(grid: $source->grid, seats: $seats)->arrangedFor($userIds);
    }

    /**
     * @param list<int> $userIds
     * @return list<int> the people among them without a seat, in the given order
     */
    public function unseated(array $userIds): array
    {
        return \array_values(\array_filter($userIds, fn (int $id): bool => $this->seatOf($id) === null));
    }

    public function rowIsEmpty(int $row): bool
    {
        return \array_filter($this->seats, static fn (Seat $seat): bool => $seat->row === $row) === [];
    }

    public function columnIsEmpty(int $column): bool
    {
        return \array_filter($this->seats, static fn (Seat $seat): bool => $seat->column === $column) === [];
    }
}
