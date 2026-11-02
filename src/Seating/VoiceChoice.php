<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

/**
 * A part that can be chosen as a person's voice in the seating editor.
 */
final class VoiceChoice
{
    /**
     * @param int $tone The position of the part in the piece, which chooses its color
     */
    public function __construct(
        public readonly int $partId,
        public readonly string $name,
        public readonly int $tone,
    ) {
    }
}
