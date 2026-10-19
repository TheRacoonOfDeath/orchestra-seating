<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

/**
 * A person on a seat of the grid. Rows count from the front (1, next to the conductor, shown as A),
 * seats from the left (1).
 */
final class Seat
{
    public function __construct(
        public readonly int $userId,
        public readonly int $row,
        public readonly int $column,
    ) {
    }

    /**
     * The form value of a cell, e.g. "2-5" for row 2, seat 5.
     */
    public static function key(int $row, int $column): string
    {
        return $row . '-' . $column;
    }

    /**
     * The name of a row as shown to users: A for the front row, then B, …, Z, AA, AB, …
     */
    public static function rowName(int $row): string
    {
        $name = '';
        for ($rest = \max(1, $row); $rest > 0; $rest = \intdiv($rest - 1, 26)) {
            $name = \chr(\ord('A') + ($rest - 1) % 26) . $name;
        }

        return $name;
    }
}
