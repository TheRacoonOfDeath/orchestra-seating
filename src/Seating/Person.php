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
        /** The part in the chosen piece; "" without an assignment */
        public readonly string $voice,
    ) {
    }
}
