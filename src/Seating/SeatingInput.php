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
    /** The project whose seating is imported */
    public const FIELD_IMPORT = 'import_project';
    public const FIELD_SEAT_ORGANIZERS = 'seat_organizers';

    public const SAVE = 'save';
    public const ADD_ROW = 'add_row';
    public const ADD_COLUMN = 'add_column';
    public const REMOVE_ROW = 'remove_row';
    public const REMOVE_COLUMN = 'remove_column';
    public const CLEAR = 'clear';
    public const IMPORT = 'import';

    /**
     * @param array<mixed> $input Unslashed form data
     * @param Closure(bool $seatOrganizers): list<int> $people The people to seat, depending on
     *        whether organizers are seated
     * @param Closure(int $projectId): ?SeatingPlan $source The seating that may be imported from a
     *        project, or null
     * @return array{SeatingPlan, list<string>} the new plan, or the current one and error messages
     */
    public static function apply(array $input, SeatingPlan $current, Closure $people, Closure $source): array
    {
        $seatOrganizers = ($input[self::FIELD_SEAT_ORGANIZERS] ?? '') === '1';
        $userIds = $people($seatOrganizers);
        $operation = $input[self::FIELD_OPERATION] ?? self::SAVE;

        if ($operation === self::IMPORT) {
            $projectId = $input[self::FIELD_IMPORT] ?? '';
            $imported = \is_numeric($projectId) ? $source((int) $projectId) : null;
            if ($imported === null) {
                return [$current, [\__('Choose a project with a seating.', 'orchestra-seating')]];
            }

            return [$current->with(seatOrganizers: $seatOrganizers)->importedFrom($imported, $userIds), []];
        }

        $grid = $current->grid->fitting(\count($userIds));

        $submitted = \is_array($input[self::FIELD_SEATS] ?? null) ? $input[self::FIELD_SEATS] : [];
        $seats = [];
        $taken = [];
        $errors = [];
        foreach ($userIds as $userId) {
            $value = $submitted[$userId] ?? '';
            if (!\is_string($value) || $value === '') {
                continue;
            }
            $valid = \preg_match('/^(\d{1,2})-(\d{1,2})$/', $value, $match) === 1;
            if (!$valid || !$grid->contains((int) $match[1], (int) $match[2])) {
                $errors[] = \__('A seat is outside the grid.', 'orchestra-seating');
                continue;
            }
            if (isset($taken[$value])) {
                $errors[] = \__('Two people cannot sit on the same seat.', 'orchestra-seating');
                continue;
            }
            $taken[$value] = true;
            $seats[] = new Seat($userId, (int) $match[1], (int) $match[2]);
        }
        if ($errors !== []) {
            return [$current, \array_values(\array_unique($errors))];
        }

        $plan = $current->with(grid: $grid, seats: $seats, seatOrganizers: $seatOrganizers);

        [$plan, $error] = match ($operation) {
            self::ADD_ROW => self::resize($plan, 1, 0, \count($userIds)),
            self::ADD_COLUMN => self::resize($plan, 0, 1, \count($userIds)),
            self::REMOVE_ROW => self::resize($plan, -1, 0, \count($userIds)),
            self::REMOVE_COLUMN => self::resize($plan, 0, -1, \count($userIds)),
            self::CLEAR => [$plan->with(seats: []), null],
            default => [$plan, null],
        };
        if ($error !== null) {
            return [$current, [$error]];
        }

        return [$plan->arrangedFor($userIds), []];
    }

    /**
     * Adds or removes the last row or column. Only empty rows and columns are removed, and the grid
     * keeps a seat for everyone.
     *
     * @return array{SeatingPlan, ?string}
     */
    private static function resize(SeatingPlan $plan, int $rows, int $columns, int $people): array
    {
        $grid = new GridSize($plan->grid->rows + $rows, $plan->grid->columns + $columns);
        if ($grid->rows > GridSize::MAX_ROWS || $grid->columns > GridSize::MAX_COLUMNS) {
            return [$plan, \__('The grid cannot get any larger.', 'orchestra-seating')];
        }
        if ($grid->rows < 1 || $grid->columns < 1 || $grid->cells() < $people) {
            return [$plan, \__('The grid needs a seat for everyone.', 'orchestra-seating')];
        }
        if (
            ($rows < 0 && !$plan->rowIsEmpty($plan->grid->rows))
            || ($columns < 0 && !$plan->columnIsEmpty($plan->grid->columns))
        ) {
            return [$plan, \__('Only an empty last row or column can be removed.', 'orchestra-seating')];
        }

        return [$plan->with(grid: $grid), null];
    }
}
