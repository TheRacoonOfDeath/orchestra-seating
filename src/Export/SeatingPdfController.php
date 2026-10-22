<?php

declare(strict_types=1);

namespace Orchestra\Seating\Export;

use Orchestra\Core\Pdf\Pdf;
use Orchestra\Core\Ui\LoginPageRedirect;
use Orchestra\Projects\ProjectsService;
use Orchestra\Seating\Capabilities;
use Orchestra\Seating\Ui\SeatingTab;

/**
 * Downloads the seating of a project as a PDF
 * (admin-post.php?action=orchestra_seating_pdf&project=ID), for conductors and organizers, who may
 * arrange the seating. Members see the seating, but cannot download it. It changes nothing and
 * needs no nonce.
 */
final class SeatingPdfController
{
    public const ACTION = 'orchestra_seating_pdf';

    public function __construct(
        private readonly ProjectsService $projects,
        private readonly SeatingTab $tab,
        private readonly SeatingPdf $pdf,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, [LoginPageRedirect::class, 'requireLogin']);
    }

    /**
     * Whether PDFs can be created: orchestra-core 0.8 or later.
     */
    public static function available(): bool
    {
        return \class_exists(Pdf::class);
    }

    /**
     * Whether the current user may download seatings.
     */
    public static function allowed(): bool
    {
        return \current_user_can(Capabilities::PLAN_MANAGE);
    }

    public static function url(int $projectId): string
    {
        return \add_query_arg(['action' => self::ACTION, 'project' => $projectId], \admin_url('admin-post.php'));
    }

    /**
     * @internal Hooked to `admin_post_orchestra_seating_pdf`.
     */
    public function handle(): never
    {
        $projectId = \absint(\is_string($_GET['project'] ?? null) ? $_GET['project'] : 0);
        $project = $this->projects->project($projectId);
        if ($project === null || !self::allowed() || !$this->tab->visible($project)) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-seating'), 403);
        }

        Pdf::download(
            $this->pdf->html($project),
            Pdf::fileName($project->title, \__('Seating', 'orchestra-seating')),
            Pdf::LANDSCAPE
        );
    }
}
