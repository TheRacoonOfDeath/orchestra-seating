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
        /** @var array<int, int> piece ID => the position of the part among the parts of the piece, from 0 */
        public readonly array $tones = [],
        /** @var array<int, int> piece ID => the ID of the part, for the pieces with an assignment */
        public readonly array $parts = [],
    ) {
    }

    /**
     * The ID of the part in the piece; null without an assignment.
     */
    public function part(int $pieceId): ?int
    {
        return $this->parts[$pieceId] ?? null;
    }

    /**
     * The position of the part in the piece among its parts, which chooses its color; null without
     * an assignment. People with the same part have the same tone.
     */
    public function tone(?int $pieceId): ?int
    {
        return $pieceId === null ? null : $this->tones[$pieceId] ?? null;
    }

    /**
     * The part in the piece; "" without an assignment.
     */
    public function voice(?int $pieceId): string
    {
        return $pieceId === null ? '' : $this->voices[$pieceId] ?? '';
    }
}
