<?php

declare(strict_types=1);

namespace Orchestra\Seating\Ui;

use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\ProjectsService;
use Orchestra\Seating\Capabilities;
use Orchestra\Seating\Seating\SeatingInput;
use Orchestra\Seating\Seating\SeatingPlan;
use Orchestra\Seating\Seating\SeatingService;

/**
 * Saves the seating form (admin-post.php?action=orchestra_seating_save). Every button of the form
 * posts here; the `op` field says which one.
 */
final class SeatingController
{
    public const ACTION = 'orchestra_seating_save';

    public function __construct(
        private readonly SeatingService $seating,
        private readonly ProjectsService $projects,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, 'auth_redirect');
    }

    public static function nonceAction(int $projectId): string
    {
        return self::ACTION . '_' . $projectId;
    }

    /**
     * @internal Hooked to `admin_post_orchestra_seating_save`.
     */
    public function handle(): never
    {
        $projectId = \absint(\is_string($_POST['project'] ?? null) ? $_POST['project'] : 0);
        \check_admin_referer(self::nonceAction($projectId));

        $project = $this->projects->project($projectId);
        if ($project === null || !\current_user_can(Capabilities::PLAN_MANAGE)) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-seating'), 403);
        }
        if ($project->status === ProjectStatus::Completed) {
            Notices::error(\__('The seating of a completed project cannot be changed.', 'orchestra-seating'));
            Redirect::back();
        }

        $input = \wp_unslash($_POST);
        $sources = $this->seating->importSources($project->id);
        [$plan, $errors] = SeatingInput::apply(
            $input,
            $this->seating->plan($project),
            fn (bool $seatOrganizers): array => $this->seating->peopleIds($project->id, $seatOrganizers),
            fn (int $sourceId): ?SeatingPlan => isset($sources[$sourceId]) ? $this->seating->savedPlan($sourceId) : null
        );
        if ($errors !== []) {
            Notices::errors($errors);
            Redirect::back();
        }

        $this->seating->save($plan);
        $operation = $input[SeatingInput::FIELD_OPERATION] ?? SeatingInput::SAVE;
        if ($operation === SeatingInput::SAVE) {
            Notices::success(\__('The seating has been saved.', 'orchestra-seating'));
        } elseif ($operation === SeatingInput::IMPORT) {
            $sourceId = $input[SeatingInput::FIELD_IMPORT] ?? '';
            Notices::success(\sprintf(
                /* translators: %s: project name */
                \__('The seating of %s has been imported.', 'orchestra-seating'),
                \is_numeric($sourceId) ? $sources[(int) $sourceId] ?? '' : ''
            ));
        }
        Redirect::back();
    }
}
