<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

/**
 * A person as shown in the seating.
 */
final class Person
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        /** @var array<int, string> piece ID => the part in that piece, for the pieces with an assignment */
        public readonly array $voices,
    ) {
    }

    /**
     * The part in the piece; "" without an assignment.
     */
    public function voice(?int $pieceId): string
    {
        return $pieceId === null ? '' : $this->voices[$pieceId] ?? '';
    }
}
