<?php

declare(strict_types=1);

namespace Orchestra\Seating\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Seating\Seating\GridSize;
use Orchestra\Seating\Seating\Person;
use Orchestra\Seating\Seating\Seat;
use Orchestra\Seating\Seating\SeatingInput;
use Orchestra\Seating\Seating\SeatingPlan;
use Orchestra\Seating\Seating\VoiceChoice;

/**
 * The seating grid and its editor, shared by the Seating tab of a project and the master seating.
 * The editor is a form with one select per person; seating.js replaces the selects with drag and
 * drop and keeps them in sync. Buttons around the grid insert and delete rows and columns.
 */
final class SeatingEditor
{
    public const HANDLE = 'orchestra-seating';

    /** The number of voice colors in seating.css; further voices repeat them. */
    private const COLORS = 8;

    /**
     * Loads seating.css and seating.js.
     */
    public static function enqueue(string $pluginFile): void
    {
        $version = (string) @\filemtime(\dirname($pluginFile) . '/assets/seating.css');
        \wp_enqueue_style(self::HANDLE, \plugins_url('assets/seating.css', $pluginFile), [], $version);
        $version = (string) @\filemtime(\dirname($pluginFile) . '/assets/seating.js');
        \wp_enqueue_script(
            self::HANDLE,
            \plugins_url('assets/seating.js', $pluginFile),
            [],
            $version,
            ['in_footer' => true]
        );
    }

    /**
     * The editor form: the settings card, "Not seated", the grid, the selects and the save buttons.
     *
     * @param array<string, int|string> $fields Hidden fields, including `redirect_to`
     * @param array<int, Person> $people user ID => person
     * @param array<int, string> $pieces piece ID => title
     * @param array<int, list<VoiceChoice>> $voiceChoices piece ID => parts; empty if the voices
     *        cannot be changed here
     * @param array<int, array<int, array<int, bool>>> $played piece ID => user ID => part ID => whether
     *        the person played it last time (true) or before (false), to color the voice choices
     */
    public static function form(
        string $action,
        string $nonceAction,
        array $fields,
        string $settings,
        string $voices,
        SeatingPlan $plan,
        array $people,
        array $pieces,
        ?int $pieceId,
        string $nobody,
        array $voiceChoices = [],
        array $played = [],
    ): string {
        $unseated = \array_values(\array_filter(
            $people,
            static fn (Person $person): bool => $plan->seatOf($person->userId) === null
        ));
        $seated = \count($people) - \count($unseated);

        $html = Form::open($action, $nonceAction, $fields, [
            'class' => 'orchestra-seating orchestra-seating--edit orchestra-stack',
            'data-seating' => '',
            'data-seating-form' => '',
        ]);
        $html .= '<div class="orchestra-card orchestra-stack">' . $settings
            . '<p class="orchestra-muted">' . \esc_html(\sprintf(
                /* translators: 1: number of seated people, 2: number of people to seat */
                \__('%1$d of %2$d people seated. Conductors are not seated.', 'orchestra-seating'),
                $seated,
                \count($people)
            )) . '</p></div>';

        $html .= '<section class="orchestra-seating__tray orchestra-stack" data-seating-tray><h3>'
            . \esc_html__('Not seated', 'orchestra-seating') . '</h3><div class="orchestra-seating__tray-list">';
        foreach ($unseated as $person) {
            $html .= self::person($person, true, $pieces, $pieceId, $voiceChoices, $played);
        }
        $html .= '</div><p class="orchestra-muted orchestra-seating__hint">' . \esc_html__(
            'Drag people onto the seats or back here. Dropping on a taken seat swaps the two; dropping on the'
                . ' left or right edge of a seat puts the person between two people, and the others move aside.'
                . ' With the keyboard: Enter picks a person up, the arrow keys choose a seat, Enter places them,'
                . ' Delete unseats them.',
            'orchestra-seating'
        ) . '</p></section>';

        $html .= '<div class="orchestra-card orchestra-stack">' . $voices
            . self::grid($plan, $people, true, $pieces, $pieceId, $voiceChoices, $played) . '</div>'
            . '<input type="hidden" name="' . SeatingInput::FIELD_INSERT_PERSON . '" value=""'
            . ' data-seating-insert-person><input type="hidden" name="' . SeatingInput::FIELD_INSERT_AT . '"'
            . ' value="" data-seating-insert-at data-seating-insert="' . SeatingInput::INSERT_BETWEEN . '">';

        $html .= '<div class="orchestra-seating__fallback">'
            . Html::table(
                [\__('Person', 'orchestra-seating'), \__('Seat', 'orchestra-seating')],
                \array_map(static fn (Person $person): array => [
                    \esc_html($person->name) . ($person->voice($pieceId) === ''
                        ? ''
                        : ' <span class="orchestra-muted">(' . \esc_html($person->voice($pieceId)) . ')</span>'),
                    self::seatSelect($plan, $person),
                ], \array_values($people)),
                $nobody
            ) . '</div>';

        return $html . '<div class="orchestra-actions">'
            // seating.js submits the form with this button when a voice is changed.
            . ($voiceChoices === [] ? '' : '<button type="submit" name="' . SeatingInput::FIELD_OPERATION
                . '" value="' . SeatingInput::VOICES . '" hidden data-seating-voices-submit></button>')
            . Form::submit(
                \__('Save seating', 'orchestra-seating'),
                'primary',
                SeatingInput::FIELD_OPERATION,
                SeatingInput::SAVE
            )
            . '<button type="submit" class="orchestra-button" name="' . SeatingInput::FIELD_OPERATION . '" value="'
            . SeatingInput::CLEAR . '" data-orchestra-confirm="'
            . \esc_attr__('Remove everyone from their seats?', 'orchestra-seating') . '">'
            . \esc_html__('Clear seating', 'orchestra-seating') . '</button></div></form>';
    }

    /**
     * The grid with the front row at the bottom, next to the conductor. The rows are labelled with
     * letters on the left, the columns with numbers below. Every seated person has a thick line on
     * each side, except towards a neighbour with the same voice in the shown piece, so that every
     * section is outlined; seating.js redraws the lines when the piece or the seating changes.
     *
     * When editable, the grid has a row of buttons above it and a column of buttons left of it: "×"
     * deletes the column or row, "+" between two columns or rows (and at both ends) inserts one.
     *
     * @param array<int, Person> $people
     * @param array<int, string> $pieces
     * @param array<int, list<VoiceChoice>> $voiceChoices piece ID => parts, when the voices can be changed
     * @param array<int, array<int, array<int, bool>>> $played see {@see form()}
     */
    public static function grid(
        SeatingPlan $plan,
        array $people,
        bool $editable,
        array $pieces,
        ?int $pieceId,
        array $voiceChoices = [],
        array $played = [],
    ): string {
        $rows = $plan->grid->rows;
        $columns = $plan->grid->columns;
        $html = '<div class="orchestra-seating__scroll"><div class="orchestra-seating__stage">'
            . '<div class="orchestra-seating__grid' . ($editable ? ' orchestra-seating__grid--edit' : '')
            . '" style="--orchestra-seating-columns: ' . $columns . '; --orchestra-seating-rows: ' . $rows . '">';
        $tone = static function (int $row, int $column) use ($plan, $people, $pieceId): ?string {
            $seat = $plan->at($row, $column);
            $person = $seat === null ? null : ($people[$seat->userId] ?? null);

            return $person === null ? null : (string) $person->tone($pieceId);
        };

        if ($editable) {
            $html .= '<div aria-hidden="true"></div><div aria-hidden="true"></div>';
            for ($column = 1; $column <= $columns; $column++) {
                $html .= '<div class="orchestra-seating__control orchestra-seating__control--column">'
                    . self::insertButton(SeatingInput::INSERT_COLUMN, $column, 'before', $columns, \sprintf(
                        /* translators: %d: seat number */
                        \__('Add a column left of seat %d', 'orchestra-seating'),
                        $column
                    ), GridSize::MAX_COLUMNS)
                    . self::deleteButton(SeatingInput::DELETE_COLUMN, $column, $columns, \sprintf(
                        /* translators: %d: seat number */
                        \__('Delete column %d', 'orchestra-seating'),
                        $column
                    ), \array_filter($plan->seats, static fn (Seat $seat): bool => $seat->column === $column) !== [])
                    . ($column === $columns ? self::insertButton(
                        SeatingInput::INSERT_COLUMN,
                        $columns + 1,
                        'after',
                        $columns,
                        \sprintf(
                            /* translators: %d: seat number */
                            \__('Add a column right of seat %d', 'orchestra-seating'),
                            $column
                        ),
                        GridSize::MAX_COLUMNS
                    ) : '')
                    . '</div>';
            }
        }

        for ($row = $rows; $row >= 1; $row--) {
            if ($editable) {
                $name = Seat::rowName($row);
                $html .= '<div class="orchestra-seating__control orchestra-seating__control--row">'
                    . ($row === $rows ? self::insertButton(
                        SeatingInput::INSERT_ROW,
                        $rows + 1,
                        'before',
                        $rows,
                        \sprintf(
                            /* translators: %s: row letter */
                            \__('Add a row behind row %s', 'orchestra-seating'),
                            $name
                        ),
                        GridSize::MAX_ROWS
                    ) : '')
                    . self::deleteButton(SeatingInput::DELETE_ROW, $row, $rows, \sprintf(
                        /* translators: %s: row letter */
                        \__('Delete row %s', 'orchestra-seating'),
                        $name
                    ), \array_filter($plan->seats, static fn (Seat $seat): bool => $seat->row === $row) !== [])
                    . self::insertButton(SeatingInput::INSERT_ROW, $row, 'after', $rows, \sprintf(
                        /* translators: %s: row letter */
                        \__('Add a row in front of row %s', 'orchestra-seating'),
                        $name
                    ), GridSize::MAX_ROWS)
                    . '</div>';
            }
            $html .= '<div class="orchestra-seating__label" aria-hidden="true">' . Seat::rowName($row) . '</div>';
            for ($column = 1; $column <= $columns; $column++) {
                $seat = $plan->at($row, $column);
                $person = $seat === null ? null : ($people[$seat->userId] ?? null);
                $own = $tone($row, $column);
                $lines = '';
                // The lines outline the sections of the voices; without voices (the master seating), there are none.
                if ($own !== null && $pieceId !== null) {
                    $neighbours = [
                        'back' => [$row + 1, $column],
                        'front' => [$row - 1, $column],
                        'left' => [$row, $column - 1],
                        'right' => [$row, $column + 1],
                    ];
                    foreach ($neighbours as $side => [$neighbourRow, $neighbourColumn]) {
                        if ($tone($neighbourRow, $neighbourColumn) !== $own) {
                            $lines .= ' is-line-' . $side;
                        }
                    }
                }
                $html .= '<div class="orchestra-seating__cell' . ($person === null ? ' is-empty' : '') . $lines . '"'
                    . ' data-seating-seat="' . Seat::key($row, $column) . '"'
                    . ($editable ? ' data-seating-cell="' . Seat::key($row, $column) . '"' : '')
                    . ' title="' . \esc_attr(self::seatLabel($row, $column)) . '">'
                    . ($person === null
                        ? ''
                        : self::person($person, $editable, $pieces, $pieceId, $voiceChoices, $played))
                    . '</div>';
            }
        }
        $html .= \str_repeat('<div aria-hidden="true"></div>', $editable ? 2 : 1);
        for ($column = 1; $column <= $columns; $column++) {
            $html .= '<div class="orchestra-seating__label" aria-hidden="true">' . $column . '</div>';
        }

        return $html . '</div><div class="orchestra-seating__front">'
            . \esc_html__('Conductor', 'orchestra-seating') . '</div></div></div>';
    }

    /**
     * A "+" button on the edge of a row or column; hidden when the grid has reached its maximum.
     *
     * @param string $edge "before" (left or above) or "after" (right or below)
     */
    private static function insertButton(
        string $operation,
        int $index,
        string $edge,
        int $size,
        string $label,
        int $maximum,
    ): string {
        if ($size >= $maximum) {
            return '';
        }

        return '<button type="submit" class="orchestra-seating__add orchestra-seating__add--' . $edge . '" name="'
            . SeatingInput::FIELD_OPERATION . '" value="' . SeatingInput::gridOperation($operation, $index) . '"'
            . ' aria-label="' . \esc_attr($label) . '" title="' . \esc_attr($label) . '">+</button>';
    }

    /**
     * A "×" button that deletes a row or column; there is none for the last one. It asks before
     * deleting a row or column with people, who are then not seated; seating.js keeps the question
     * up to date while people are moved.
     */
    private static function deleteButton(string $operation, int $index, int $size, string $label, bool $taken): string
    {
        if ($size <= 1) {
            return '';
        }
        $line = $operation === SeatingInput::DELETE_ROW ? 'row' : 'column';
        $question = \esc_attr(\sprintf(
            /* translators: %s: e.g. "Delete row C" */
            \__('%s? The people on it will not be seated.', 'orchestra-seating'),
            $label
        ));

        return '<button type="submit" class="orchestra-seating__delete" name="' . SeatingInput::FIELD_OPERATION
            . '" value="' . SeatingInput::gridOperation($operation, $index) . '" data-seating-delete-' . $line . '="'
            . $index . '" data-seating-question="' . $question . '"'
            . ($taken ? ' data-orchestra-confirm="' . $question . '"' : '')
            . ' aria-label="' . \esc_attr($label) . '" title="' . \esc_attr($label) . '">×</button>';
    }

    /**
     * A person with a voice per piece; only the voice of the shown piece is visible. In the editor,
     * with voice choices, the voice is a select of the parts of the piece.
     *
     * @param array<int, string> $pieces
     * @param array<int, list<VoiceChoice>> $voiceChoices
     * @param array<int, array<int, array<int, bool>>> $played
     */
    private static function person(
        Person $person,
        bool $editable,
        array $pieces,
        ?int $pieceId,
        array $voiceChoices = [],
        array $played = [],
    ): string {
        $voices = '';
        foreach ($pieces as $id => $title) {
            $voice = $person->voice($id);
            $attributes = ' data-seating-voice="' . $id . '"' . self::tone($person->tone($id))
                . ($id === $pieceId ? '' : ' hidden');
            $voices .= $editable && isset($voiceChoices[$id])
                ? self::voiceSelect(
                    $person,
                    $id,
                    $title,
                    $voiceChoices[$id],
                    $attributes,
                    $played[$id][$person->userId] ?? []
                )
                : '<span class="orchestra-seating__voice"' . $attributes . '>'
                    . \esc_html($voice === '' ? '—' : $voice) . '</span>';
        }

        return '<div class="orchestra-seating__person"' . self::tone($person->tone($pieceId))
            . ($editable ? ' data-seating-person="' . $person->userId . '" tabindex="0" role="button"' : '')
            . ' title="' . \esc_attr($person->name) . '">'
            . '<span class="orchestra-seating__name">' . \esc_html($person->name) . '</span>' . $voices . '</div>';
    }

    /**
     * The voice of a person in a piece as a select of its parts. The hidden field keeps the part
     * shown when the page was loaded, so that only changed voices are saved. The part the person
     * played last time is green and listed first, then the parts they played before in yellow, then
     * the others; each group by name.
     *
     * @param list<VoiceChoice> $choices
     * @param array<int, bool> $played part ID => whether the person played it last time
     */
    private static function voiceSelect(
        Person $person,
        int $pieceId,
        string $title,
        array $choices,
        string $attributes,
        array $played = [],
    ): string {
        $current = $person->part($pieceId);
        $options = '<option value="">—</option>';
        // Green first, then yellow, then the others; by name within each group.
        $rank = static fn (VoiceChoice $choice): int => match ($played[$choice->partId] ?? null) {
            true => 0,
            false => 1,
            null => 2,
        };
        \usort($choices, static fn (VoiceChoice $a, VoiceChoice $b): int => $rank($a) <=> $rank($b)
            ?: \strnatcasecmp($a->name, $b->name));
        foreach ($choices as $choice) {
            $class = !isset($played[$choice->partId]) ? ''
                : ' class="orchestra-seating__played--' . ($played[$choice->partId] ? 'last' : 'earlier') . '"';
            $options .= '<option value="' . $choice->partId . '"' . $class
                . \selected($current, $choice->partId, false) . '>'
                . \esc_html($choice->name) . '</option>';
        }
        $key = '[' . $pieceId . '][' . $person->userId . ']';

        return '<input type="hidden" name="' . SeatingInput::FIELD_VOICES_WAS . $key . '" value="'
            . ($current ?? '') . '"><select class="orchestra-seating__voice orchestra-seating__voice-select" name="'
            . SeatingInput::FIELD_VOICES . $key . '"' . $attributes . ' aria-label="' . \esc_attr(\sprintf(
                /* translators: 1: name, 2: piece title */
                \__('Voice of %1$s in %2$s', 'orchestra-seating'),
                $person->name,
                $title
            )) . '">' . $options . '</select>';
    }

    /**
     * The voice of a person as attributes: the tone tells voices apart, the color is one of the
     * eight colors of seating.css. Without a voice, there is no tone and no color.
     */
    private static function tone(?int $tone): string
    {
        return $tone === null ? ' data-seating-tone=""'
            : ' data-seating-tone="' . $tone . '" data-seating-color="' . ($tone % self::COLORS) . '"';
    }

    private static function seatSelect(SeatingPlan $plan, Person $person): string
    {
        $current = $plan->seatOf($person->userId);
        $options = '<option value="">' . \esc_html__('Not seated', 'orchestra-seating') . '</option>';
        for ($row = 1; $row <= $plan->grid->rows; $row++) {
            for ($column = 1; $column <= $plan->grid->columns; $column++) {
                $key = Seat::key($row, $column);
                $selected = $current !== null && $current->row === $row && $current->column === $column;
                $options .= '<option value="' . $key . '"' . \selected($selected, true, false) . '>'
                    . \esc_html(self::seatLabel($row, $column)) . '</option>';
            }
        }

        return '<select name="' . SeatingInput::FIELD_SEATS . '[' . $person->userId . ']" data-seating-select="'
            . $person->userId . '" aria-label="' . \esc_attr(\sprintf(
                /* translators: %s: name */
                \__('Seat of %s', 'orchestra-seating'),
                $person->name
            )) . '">' . $options . '</select>';
    }

    private static function seatLabel(int $row, int $column): string
    {
        /* translators: 1: row letter, A at the front, 2: seat number, counted from the left */
        return \sprintf(\__('Row %1$s, seat %2$d', 'orchestra-seating'), Seat::rowName($row), $column);
    }
}
