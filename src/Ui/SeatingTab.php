<?php

declare(strict_types=1);

namespace Orchestra\Seating\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Pages;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\ProjectsService;
use Orchestra\Seating\Capabilities;
use Orchestra\Seating\Export\SeatingPdfController;
use Orchestra\Seating\Seating\Person;
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

        SeatingEditor::enqueue($this->pluginFile);
        $plan = $this->seating->plan($project);
        $people = [];
        foreach ($this->seating->people($plan) as $person) {
            $people[$person->userId] = $person;
        }
        $pieces = $this->seating->programPieces($project->id);
        $requested = \absint(\is_string($_GET[self::PIECE_PARAMETER] ?? null) ? $_GET[self::PIECE_PARAMETER] : 0);
        $pieceId = isset($pieces[$requested]) ? $requested : \array_key_first($pieces);

        if (!\current_user_can(Capabilities::PLAN_MANAGE) || $project->status === ProjectStatus::Completed) {
            return self::pdfButton($project) . '<div class="orchestra-card orchestra-seating orchestra-stack"'
                . ' data-seating>' . self::voices($pieces, $pieceId, $tabUrl, false)
                . SeatingEditor::grid($plan, $people, false, $pieces, $pieceId) . '</div>';
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
        $settings = '<div class="orchestra-row"><label class="orchestra-checkbox"><input type="checkbox" name="'
            . SeatingInput::FIELD_SEAT_ORGANIZERS . '" value="1"' . \checked($plan->seatOrganizers, true, false)
            . ' data-seating-autosave> <span>' . \esc_html__('Seat organizers', 'orchestra-seating')
            . '</span></label></div>' . $this->master();

        return SeatingEditor::form(
            SeatingController::ACTION,
            SeatingController::nonceAction($project->id),
            ['project' => $project->id, 'redirect_to' => $tabUrl],
            $settings,
            self::voices($pieces, $pieceId, $tabUrl, true),
            $plan,
            $people,
            $pieces,
            $pieceId,
            \__('Nobody has accepted yet. Everyone who registers can be seated here.', 'orchestra-seating')
        );
    }

    /**
     * Takes over the master seating, and links to it.
     */
    private function master(): string
    {
        $url = Pages::url(MasterShortcode::TAG);
        $link = $url === null ? '' : Form::link($url, \__('Edit master seating', 'orchestra-seating'), 'small');
        if ($this->seating->savedMaster() === null) {
            return '<div class="orchestra-actions"><span class="orchestra-muted">'
                . \esc_html__('There is no master seating yet.', 'orchestra-seating') . '</span>' . $link . '</div>';
        }

        return '<div class="orchestra-actions"><button type="submit" class="orchestra-button small" name="'
            . SeatingInput::FIELD_OPERATION . '" value="' . SeatingInput::APPLY_MASTER . '" data-orchestra-confirm="'
            . \esc_attr__(
                'Replace the current seating with the master seating? Everyone keeps their row, the rows are'
                    . ' closed up to the left, and people who are not in the master seating are not seated.',
                'orchestra-seating'
            ) . '">' . \esc_html__('Apply master seating', 'orchestra-seating') . '</button>' . $link . '</div>';
    }
}
