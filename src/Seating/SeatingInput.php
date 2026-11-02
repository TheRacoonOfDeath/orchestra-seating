<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

use Closure;

/**
 * Applies the submitted seating form to a plan. Every button of the form submits all seats, so
 * changing the grid never loses seats that were just moved.
 */
final class SeatingInput
{
    public const FIELD_OPERATION = 'op';
    /** `seat[userId]` = "row-column", e.g. "2-5", or "" for no seat */
    public const FIELD_SEATS = 'seat';
    public const FIELD_SEAT_ORGANIZERS = 'seat_organizers';
    /** The person dropped between two others, with `insert_between` */
    public const FIELD_INSERT_PERSON = 'insert_person';
    /** "row-column": the person is seated in front of this seat, with `insert_between` */
    public const FIELD_INSERT_AT = 'insert_at';
    /** `voice[pieceId][userId]` = part ID, or "" for none */
    public const FIELD_VOICES = 'voice';
    /** `voice_was[pieceId][userId]`: the part shown when the page was loaded */
    public const FIELD_VOICES_WAS = 'voice_was';

    public const SAVE = 'save';
    public const CLEAR = 'clear';
    public const APPLY_MASTER = 'apply_master';
    public const INSERT_BETWEEN = 'insert_between';
    /** Sent by seating.js when a voice is changed; saves the seating and the voices */
    public const VOICES = 'voices';
    /** Followed by "-" and the row or column, e.g. "insert_row-3" adds a row in front of row 3 */
    public const INSERT_ROW = 'insert_row';
    public const INSERT_COLUMN = 'insert_column';
    public const DELETE_ROW = 'delete_row';
    public const DELETE_COLUMN = 'delete_column';

    /**
     * The operation of a grid button, e.g. "delete_column-4".
     */
    public static function gridOperation(string $operation, int $index): string
    {
        return $operation . '-' . $index;
    }

    /**
     * @param array<mixed> $input Unslashed form data
     * @param Closure(bool $seatOrganizers): list<int> $people The people to seat, depending on
     *        whether organizers are seated
     * @param (Closure(): ?SeatingPlan)|null $master The saved master seating, or null in the editor of
     *        the master seating itself
     * @return array{SeatingPlan, list<string>} the new plan, or the current one and error messages
     */
    public static function apply(array $input, SeatingPlan $current, Closure $people, ?Closure $master): array
    {
        $seatOrganizers = ($input[self::FIELD_SEAT_ORGANIZERS] ?? '') === '1';
        $userIds = $people($seatOrganizers);
        $operation = $input[self::FIELD_OPERATION] ?? self::SAVE;

        if ($operation === self::APPLY_MASTER) {
            $source = $master === null ? null : $master();
            if ($source === null) {
                return [$current, [\__('There is no master seating yet.', 'orchestra-seating')]];
            }
            $plan = $current->with(seatOrganizers: $seatOrganizers)->fromMaster($source, $userIds);
            if ($plan->seats === []) {
                return [$current, [\__('Nobody in the master seating takes part.', 'orchestra-seating')]];
            }

            return [$plan, []];
        }

        $grid = $current->grid;
        $submitted = \is_array($input[self::FIELD_SEATS] ?? null) ? $input[self::FIELD_SEATS] : [];
        $seats = [];
        $taken = [];
        $errors = [];
        foreach ($userIds as $userId) {
            $value = $submitted[$userId] ?? '';
            if (!\is_string($value) || $value === '') {
                continue;
            }
            $cell = self::cell($value);
            if ($cell === null || !$grid->contains($cell[0], $cell[1])) {
                $errors[] = \__('A seat is outside the grid.', 'orchestra-seating');
                continue;
            }
            if (isset($taken[$value])) {
                $errors[] = \__('Two people cannot sit on the same seat.', 'orchestra-seating');
                continue;
            }
            $taken[$value] = true;
            $seats[] = new Seat($userId, $cell[0], $cell[1]);
        }
        if ($errors !== []) {
            return [$current, \array_values(\array_unique($errors))];
        }

        $plan = $current->with(seats: $seats, seatOrganizers: $seatOrganizers);
        $operation = \is_string($operation) ? $operation : self::SAVE;
        [$plan, $error] = match (true) {
            $operation === self::CLEAR => [$plan->with(seats: []), null],
            $operation === self::INSERT_BETWEEN => self::insertBetween($plan, $input, $userIds),
            \preg_match('/^(insert|delete)_(row|column)-(\d{1,2})$/', $operation, $match) === 1
                => self::changeGrid($plan, $match[1] === 'insert', $match[2] === 'row', (int) $match[3]),
            default => [$plan, null],
        };
        if ($error !== null) {
            return [$current, [$error]];
        }

        return [$plan->arrangedFor($userIds), []];
    }

    /**
     * The voices changed in the form: only those whose select differs from the part shown when the
     * page was loaded, so that a form loaded earlier does not undo assignments made elsewhere.
     *
     * @param array<mixed> $input Unslashed form data
     * @param list<int> $userIds The people who may be given a voice
     * @param list<int> $pieceIds The pieces of the program
     * @return list<array{pieceId: int, userId: int, partId: ?int}>
     */
    public static function voiceChanges(array $input, array $userIds, array $pieceIds): array
    {
        $voices = \is_array($input[self::FIELD_VOICES] ?? null) ? $input[self::FIELD_VOICES] : [];
        $was = \is_array($input[self::FIELD_VOICES_WAS] ?? null) ? $input[self::FIELD_VOICES_WAS] : [];
        $changes = [];
        foreach ($pieceIds as $pieceId) {
            $pieceVoices = \is_array($voices[$pieceId] ?? null) ? $voices[$pieceId] : [];
            $pieceWas = \is_array($was[$pieceId] ?? null) ? $was[$pieceId] : [];
            foreach ($userIds as $userId) {
                $value = $pieceVoices[$userId] ?? null;
                $previous = $pieceWas[$userId] ?? null;
                if (!\is_string($value) || !\is_string($previous) || $value === $previous) {
                    continue;
                }
                if ($value !== '' && !\ctype_digit($value)) {
                    continue;
                }
                $partId = $value === '' ? null : (int) $value;
                $changes[] = ['pieceId' => $pieceId, 'userId' => $userId, 'partId' => $partId];
            }
        }

        return $changes;
    }

    /**
     * Inserts a row or column in front of the given one, or deletes it.
     *
     * @return array{SeatingPlan, ?string}
     */
    private static function changeGrid(SeatingPlan $plan, bool $insert, bool $row, int $index): array
    {
        $size = $row ? $plan->grid->rows : $plan->grid->columns;
        $maximum = $row ? GridSize::MAX_ROWS : GridSize::MAX_COLUMNS;
        if ($insert) {
            if ($index < 1 || $index > $size + 1) {
                return [$plan, \__('This row or column does not exist.', 'orchestra-seating')];
            }
            if ($size >= $maximum) {
                return [$plan, \__('The grid cannot get any larger.', 'orchestra-seating')];
            }

            return [$row ? $plan->insertRow($index) : $plan->insertColumn($index), null];
        }

        if ($index < 1 || $index > $size) {
            return [$plan, \__('This row or column does not exist.', 'orchestra-seating')];
        }
        if ($size <= 1) {
            return [$plan, \__('The last row or column cannot be deleted.', 'orchestra-seating')];
        }

        return [$row ? $plan->deleteRow($index) : $plan->deleteColumn($index), null];
    }

    /**
     * @param array<mixed> $input
     * @param list<int> $userIds
     * @return array{SeatingPlan, ?string}
     */
    private static function insertBetween(SeatingPlan $plan, array $input, array $userIds): array
    {
        $person = $input[self::FIELD_INSERT_PERSON] ?? '';
        $at = $input[self::FIELD_INSERT_AT] ?? '';
        $userId = \is_numeric($person) ? (int) $person : 0;
        $cell = \is_string($at) ? self::cell($at) : null;
        if (
            !\in_array($userId, $userIds, true)
            || $cell === null
            || !$plan->grid->contains($cell[0], 1)
            || $cell[1] < 1
            || $cell[1] > $plan->grid->columns + 1
        ) {
            return [$plan, \__('The person cannot be seated there.', 'orchestra-seating')];
        }

        $inserted = $plan->insertBetween($userId, $cell[0], $cell[1]);
        if ($inserted->grid->columns > GridSize::MAX_COLUMNS) {
            return [$plan, \__('The grid cannot get any larger.', 'orchestra-seating')];
        }

        return [$inserted, null];
    }

    /**
     * @return array{int, int}|null row and column of a "row-column" value
     */
    private static function cell(string $value): ?array
    {
        return \preg_match('/^(\d{1,2})-(\d{1,2})$/', $value, $match) === 1 ? [(int) $match[1], (int) $match[2]] : null;
    }
}
