<?php

declare(strict_types=1);

namespace Orchestra\Seating\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\ProjectsService;
use Orchestra\Seating\Capabilities;
use Orchestra\Seating\Export\SeatingPdfController;
use Orchestra\Seating\Seating\Person;
use Orchestra\Seating\Seating\Seat;
use Orchestra\Seating\Seating\SeatingInput;
use Orchestra\Seating\Seating\SeatingPlan;
use Orchestra\Seating\Seating\SeatingService;

/**
 * The "Seating" tab of a project, added to orchestra-projects with its tab filters. Members see the
 * seating; conductors and organizers arrange it by drag and drop, or with a select per person
 * without JavaScript. Everyone can step through the pieces of the program to see the voices.
 */
final class SeatingTab
{
    public const TAB = 'seating';

    /** The piece whose voices are shown first, as a query parameter of the tab */
    public const PIECE_PARAMETER = 'seating_piece';

    private const HANDLE = 'orchestra-seating';

    public function __construct(private readonly SeatingService $seating, private readonly string $pluginFile)
    {
    }

    public function register(): void
    {
        \add_filter(ProjectsService::TABS_FILTER, [$this, 'addTab'], 10, 2);
        \add_filter(ProjectsService::TAB_CONTENT_FILTER, [$this, 'render'], 10, 4);
    }

    /**
     * Conductors and organizers always see the tab, members once there is a seating.
     *
     * @internal Hooked to `orchestra_projects_tabs`.
     * @param array<mixed> $tabs
     * @return array<mixed>
     */
    public function addTab(array $tabs, Project $project): array
    {
        if ($this->visible($project)) {
            $tabs[self::TAB] = \__('Seating', 'orchestra-seating');
        }

        return $tabs;
    }

    /**
     * Whether the current user may see the seating of the project.
     */
    public function visible(Project $project): bool
    {
        return \current_user_can(Capabilities::PLAN_MANAGE) || (
            \current_user_can(Capabilities::PLAN_READ)
            && $project->status->visibleToMembers()
            && $this->seating->plan($project)->seats !== []
        );
    }

    /**
     * @internal Hooked to `orchestra_projects_tab_content`.
     */
    public function render(string $html, string $tab, Project $project, string $tabUrl): string
    {
        if ($tab !== self::TAB) {
            return $html;
        }

        $version = (string) @\filemtime(\dirname($this->pluginFile) . '/assets/seating.css');
        \wp_enqueue_style(self::HANDLE, \plugins_url('assets/seating.css', $this->pluginFile), [], $version);

        $plan = $this->seating->plan($project);
        $people = [];
        foreach ($this->seating->people($plan) as $person) {
            $people[$person->userId] = $person;
        }
        $pieces = $this->seating->programPieces($project->id);
        $requested = \absint(\is_string($_GET[self::PIECE_PARAMETER] ?? null) ? $_GET[self::PIECE_PARAMETER] : 0);
        $pieceId = isset($pieces[$requested]) ? $requested : \array_key_first($pieces);

        $version = (string) @\filemtime(\dirname($this->pluginFile) . '/assets/seating.js');
        \wp_enqueue_script(
            self::HANDLE,
            \plugins_url('assets/seating.js', $this->pluginFile),
            [],
            $version,
            ['in_footer' => true]
        );

        if (!\current_user_can(Capabilities::PLAN_MANAGE) || $project->status === ProjectStatus::Completed) {
            return self::pdfButton($project) . '<div class="orchestra-card orchestra-seating orchestra-stack"'
                . ' data-seating>' . self::voices($pieces, $pieceId, $tabUrl, false)
                . self::grid($plan, $people, false, $pieces, $pieceId) . '</div>';
        }

        return self::pdfButton($project) . $this->editor($project, $plan, $people, $pieces, $pieceId, $tabUrl);
    }

    /**
     * Steps through the pieces of the program; only the voices on the seats change. Without
     * JavaScript the arrows are links that reload the tab with the piece.
     *
     * @param array<int, string> $pieces
     */
    private static function voices(array $pieces, ?int $pieceId, string $tabUrl, bool $editable): string
    {
        if ($pieceId === null) {
            return $editable ? '<p class="orchestra-muted">'
                . \esc_html__('Add pieces to the program to show the voices.', 'orchestra-seating') . '</p>' : '';
        }

        $ids = \array_keys($pieces);
        $index = (int) \array_search($pieceId, $ids, true);
        $labels = [];
        foreach ($ids as $position => $id) {
            $labels[] = ['id' => $id, 'label' => \sprintf(
                /* translators: 1: piece title, 2: its position in the program, 3: number of pieces */
                \__('Voices in %1$s (%2$d of %3$d)', 'orchestra-seating'),
                $pieces[$id],
                $position + 1,
                \count($ids)
            )];
        }
        $step = static function (int $offset, string $symbol, string $label) use ($ids, $index, $tabUrl): string {
            $target = $ids[($index + $offset + \count($ids)) % \count($ids)];

            return '<a class="orchestra-button small" href="'
                . \esc_url(\add_query_arg(self::PIECE_PARAMETER, $target, $tabUrl)) . '" data-seating-step="'
                . $offset . '" aria-label="' . \esc_attr($label) . '">' . $symbol . '</a>';
        };

        return '<div class="orchestra-seating__voices" data-seating-voices="'
            . \esc_attr((string) \wp_json_encode($labels)) . '" data-seating-current="' . $pieceId . '">'
            . (\count($ids) > 1 ? $step(-1, '‹', \__('Previous piece', 'orchestra-seating')) : '')
            . '<span class="orchestra-seating__piece" aria-live="polite" data-seating-piece>'
            . \esc_html($labels[$index]['label']) . '</span>'
            . (\count($ids) > 1 ? $step(1, '›', \__('Next piece', 'orchestra-seating')) : '') . '</div>';
    }

    /**
     * The download of the seating as a PDF, for conductors and organizers, with orchestra-core 0.8
     * or later. It shows the saved seating, so the editor's changes must be saved first.
     */
    private static function pdfButton(Project $project): string
    {
        return SeatingPdfController::available() && SeatingPdfController::allowed()
            ? '<div class="orchestra-actions">' . Form::link(
                SeatingPdfController::url($project->id),
                \__('Download PDF', 'orchestra-seating'),
                'small'
            ) . '</div>'
            : '';
    }

    /**
     * @param array<int, Person> $people
     * @param array<int, string> $pieces
     */
    private function editor(
        Project $project,
        SeatingPlan $plan,
        array $people,
        array $pieces,
        ?int $pieceId,
        string $tabUrl,
    ): string {
        $sources = $this->seating->importSources($project->id);
        $unseated = \array_values(\array_filter(
            $people,
            static fn (Person $person): bool => $plan->seatOf($person->userId) === null
        ));
        $seated = \count($people) - \count($unseated);

        $html = Form::open(
            SeatingController::ACTION,
            SeatingController::nonceAction($project->id),
            ['project' => $project->id, 'redirect_to' => $tabUrl],
            [
                'class' => 'orchestra-seating orchestra-seating--edit orchestra-stack',
                'data-seating' => '',
                'data-seating-form' => '',
            ]
        );
        $html .= '<div class="orchestra-card orchestra-stack"><div class="orchestra-row">'
            . '<label class="orchestra-checkbox"><input type="checkbox" name="' . SeatingInput::FIELD_SEAT_ORGANIZERS
            . '" value="1"' . \checked($plan->seatOrganizers, true, false) . ' data-seating-autosave> <span>'
            . \esc_html__('Seat organizers', 'orchestra-seating') . '</span></label></div>'
            . '<p class="orchestra-muted">' . \esc_html(\sprintf(
                /* translators: 1: number of seated people, 2: number of people to seat */
                \__('%1$d of %2$d people seated. Conductors are not seated.', 'orchestra-seating'),
                $seated,
                \count($people)
            )) . '</p>' . self::import($sources) . '</div>';

        $html .= '<section class="orchestra-seating__tray orchestra-stack" data-seating-tray><h3>'
            . \esc_html__('Not seated', 'orchestra-seating') . '</h3><div class="orchestra-seating__tray-list">';
        foreach ($unseated as $person) {
            $html .= self::person($person, true, $pieces, $pieceId);
        }
        $html .= '</div><p class="orchestra-muted orchestra-seating__hint">' . \esc_html__(
            'Drag people onto the seats or back here. Dropping on a taken seat swaps the two. With the keyboard:'
                . ' Enter picks a person up, the arrow keys choose a seat, Enter places them, Delete unseats them.',
            'orchestra-seating'
        ) . '</p></section>';

        $html .= '<div class="orchestra-card orchestra-stack">' . self::voices($pieces, $pieceId, $tabUrl, true)
            . self::grid($plan, $people, true, $pieces, $pieceId)
            . '<div class="orchestra-actions">'
            . self::operation(\__('+ Row', 'orchestra-seating'), SeatingInput::ADD_ROW)
            . self::operation(\__('− Row', 'orchestra-seating'), SeatingInput::REMOVE_ROW)
            . self::operation(\__('+ Column', 'orchestra-seating'), SeatingInput::ADD_COLUMN)
            . self::operation(\__('− Column', 'orchestra-seating'), SeatingInput::REMOVE_COLUMN)
            . '</div></div>';

        $html .= '<div class="orchestra-seating__fallback">'
            . Html::table(
                [\__('Person', 'orchestra-seating'), \__('Seat', 'orchestra-seating')],
                \array_map(static fn (Person $person): array => [
                    \esc_html($person->name) . ($person->voice($pieceId) === ''
                        ? ''
                        : ' <span class="orchestra-muted">(' . \esc_html($person->voice($pieceId)) . ')</span>'),
                    self::seatSelect($plan, $person),
                ], \array_values($people)),
                \__('Nobody has accepted yet.', 'orchestra-seating')
            ) . '</div>';

        return $html . '<div class="orchestra-actions">'
            . self::operation(\__('Save seating', 'orchestra-seating'), SeatingInput::SAVE, 'primary')
            . '<button type="submit" class="orchestra-button" name="' . SeatingInput::FIELD_OPERATION . '" value="'
            . SeatingInput::CLEAR . '" data-orchestra-confirm="'
            . \esc_attr__('Remove everyone from their seats?', 'orchestra-seating') . '">'
            . \esc_html__('Clear seating', 'orchestra-seating') . '</button></div></form>';
    }

    /**
     * Takes over the seating of another project.
     *
     * @param array<int, string> $sources project ID => name
     */
    private static function import(array $sources): string
    {
        if ($sources === []) {
            return '';
        }

        $options = '';
        foreach ($sources as $id => $title) {
            $options .= '<option value="' . $id . '">' . \esc_html($title) . '</option>';
        }

        return '<div class="orchestra-seating__import">' . Html::field(
            'orchestra-seating-import',
            \__('Seating of another project', 'orchestra-seating'),
            '<select id="orchestra-seating-import" name="' . SeatingInput::FIELD_IMPORT . '">' . $options . '</select>'
        ) . '<button type="submit" class="orchestra-button small" name="' . SeatingInput::FIELD_OPERATION
            . '" value="' . SeatingInput::IMPORT . '" data-orchestra-confirm="' . \esc_attr__(
                'Replace the current seating with the seating of the chosen project? People who take part in'
                    . ' both keep their seat, everyone else is not seated.',
                'orchestra-seating'
            ) . '">' . \esc_html__('Import seating', 'orchestra-seating') . '</button></div>';
    }

    private static function operation(string $label, string $operation, string $style = 'small'): string
    {
        return Form::submit($label, $style, SeatingInput::FIELD_OPERATION, $operation);
    }

    /**
     * The grid with the front row at the bottom, next to the conductor. The rows are labelled with
     * letters on the left, the columns with numbers below.
     *
     * @param array<int, Person> $people
     * @param array<int, string> $pieces
     */
    private static function grid(
        SeatingPlan $plan,
        array $people,
        bool $editable,
        array $pieces,
        ?int $pieceId,
    ): string {
        $html = '<div class="orchestra-seating__scroll"><div class="orchestra-seating__stage">'
            . '<div class="orchestra-seating__grid" style="--orchestra-seating-columns: ' . $plan->grid->columns
            . '; --orchestra-seating-rows: ' . $plan->grid->rows . '">';
        for ($row = $plan->grid->rows; $row >= 1; $row--) {
            $html .= '<div class="orchestra-seating__label" aria-hidden="true">' . Seat::rowName($row) . '</div>';
            for ($column = 1; $column <= $plan->grid->columns; $column++) {
                $seat = $plan->at($row, $column);
                $person = $seat === null ? null : ($people[$seat->userId] ?? null);
                $html .= '<div class="orchestra-seating__cell' . ($person === null ? ' is-empty' : '') . '"'
                    . ($editable ? ' data-seating-cell="' . Seat::key($row, $column) . '"' : '')
                    . ' title="' . \esc_attr(self::seatLabel($row, $column)) . '">'
                    . ($person === null ? '' : self::person($person, $editable, $pieces, $pieceId)) . '</div>';
            }
        }
        $html .= '<div aria-hidden="true"></div>';
        for ($column = 1; $column <= $plan->grid->columns; $column++) {
            $html .= '<div class="orchestra-seating__label" aria-hidden="true">' . $column . '</div>';
        }

        return $html . '</div><div class="orchestra-seating__front">'
            . \esc_html__('Conductor', 'orchestra-seating') . '</div></div></div>';
    }

    /**
     * A person with a voice per piece; only the voice of the shown piece is visible.
     *
     * @param array<int, string> $pieces
     */
    private static function person(Person $person, bool $editable, array $pieces, ?int $pieceId): string
    {
        $voices = '';
        foreach (\array_keys($pieces) as $id) {
            $voice = $person->voice($id);
            $voices .= '<span class="orchestra-seating__voice" data-seating-voice="' . $id . '"'
                . ($id === $pieceId ? '' : ' hidden') . '>' . \esc_html($voice === '' ? '—' : $voice) . '</span>';
        }

        return '<div class="orchestra-seating__person"'
            . ($editable ? ' data-seating-person="' . $person->userId . '" tabindex="0" role="button"' : '')
            . ' title="' . \esc_attr($person->name) . '">'
            . '<span class="orchestra-seating__name">' . \esc_html($person->name) . '</span>' . $voices . '</div>';
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
