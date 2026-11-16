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
     * @param string $shortName The name with the short name of the instrument, e.g. "Git 1"
     */
    public function __construct(
        public readonly int $partId,
        public readonly string $name,
        public readonly int $tone,
        public readonly string $shortName = '',
    ) {
    }

    /**
     * The short name, or the name if there is none.
     */
    public function shortOrName(): string
    {
        return $this->shortName === '' ? $this->name : $this->shortName;
    }
}
