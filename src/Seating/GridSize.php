<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

/**
 * The size of the seating grid.
 */
final class GridSize
{
    public const MIN_COLUMNS = 4;
    public const MAX_ROWS = 30;
    public const MAX_COLUMNS = 30;

    public function __construct(public readonly int $rows, public readonly int $columns)
    {
    }

    /**
     * A grid with a seat for everyone, wider than deep like an orchestra: 12 people get 3 rows of
     * 5 seats, 40 people 5 rows of 8.
     */
    public static function initial(int $people): self
    {
        $columns = \min(self::MAX_COLUMNS, \max(self::MIN_COLUMNS, (int) \ceil(\sqrt($people * 1.5))));

        return new self(\max(1, (int) \ceil($people / $columns)), $columns);
    }

    public function cells(): int
    {
        return $this->rows * $this->columns;
    }

    /**
     * Adds rows at the back until everyone fits.
     */
    public function fitting(int $people): self
    {
        $rows = $this->rows;
        while ($rows * $this->columns < $people && $rows < self::MAX_ROWS) {
            $rows++;
        }

        return new self($rows, $this->columns);
    }

    public function contains(int $row, int $column): bool
    {
        return $row >= 1 && $row <= $this->rows && $column >= 1 && $column <= $this->columns;
    }
}
