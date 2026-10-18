<?php

declare(strict_types=1);

namespace Orchestra\Seating;

use LogicException;
use Orchestra\Core\Authorization\CapabilityRegistry;
use Orchestra\Core\Ui\Html;
use Orchestra\Library\Plugin as LibraryPlugin;
use Orchestra\Members\Plugin as MembersPlugin;
use Orchestra\Projects\Plugin as ProjectsPlugin;
use Orchestra\Projects\ProjectsService;
use Orchestra\Seating\Database\Schema;
use Orchestra\Seating\Export\SeatingPdf;
use Orchestra\Seating\Export\SeatingPdfController;
use Orchestra\Seating\Privacy\PersonalData;
use Orchestra\Seating\Seating\SeatingRepository;
use Orchestra\Seating\Seating\SeatingService;
use Orchestra\Seating\Ui\SeatingController;
use Orchestra\Seating\Ui\SeatingTab;
use wpdb;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Plugin
{
    /**
     * Update metadata, attached to every GitHub release by build-plugin.sh. This download link
     * always points to the latest release and, unlike the GitHub API, has no rate limit for
     * requests without a token.
     */
    private const UPDATE_METADATA_URL =
        'https://github.com/TheRacoonOfDeath/orchestra-seating/releases/latest/download/update.json';

    private static ?self $instance = null;

    private readonly Schema $schema;
    private readonly SeatingRepository $repository;

    private function __construct(private readonly string $pluginFile, wpdb $wpdb)
    {
        $this->schema = new Schema($wpdb);
        $this->repository = new SeatingRepository($wpdb, $this->schema);
    }

    public static function boot(string $pluginFile): self
    {
        if (self::$instance === null) {
            $wpdb = $GLOBALS['wpdb'] ?? null;
            if (!$wpdb instanceof wpdb) {
                throw new LogicException('orchestra-seating requires a WordPress database connection.');
            }

            self::$instance = new self($pluginFile, $wpdb);
            self::$instance->register();
        }

        return self::$instance;
    }

    private function register(): void
    {
        PucFactory::buildUpdateChecker(self::UPDATE_METADATA_URL, $this->pluginFile, 'orchestra-seating');
        \add_action('init', [$this, 'loadTextDomain'], 0);
        \add_action('plugins_loaded', [$this, 'registerFeatures']);
    }

    /**
     * The language is chosen in orchestra-core (`Orchestra\Core\Ui\Language`).
     *
     * @internal Hooked to `init`, before anything is translated.
     */
    public function loadTextDomain(): void
    {
        $directory = \dirname(\plugin_basename($this->pluginFile)) . '/languages';
        \load_plugin_textdomain('orchestra-seating', false, $directory);
    }

    /**
     * @internal Hooked to `plugins_loaded`, when the other Orchestra plugins are loaded if active.
     */
    public function registerFeatures(): void
    {
        // The project tabs of other plugins exist since orchestra-projects 0.14.
        if (
            !\class_exists(CapabilityRegistry::class)
            || !\class_exists(Html::class)
            || !\class_exists(LibraryPlugin::class)
            || !\class_exists(MembersPlugin::class)
            || !\class_exists(ProjectsPlugin::class)
            || !\defined(ProjectsService::class . '::TABS_FILTER')
        ) {
            \add_action('admin_notices', [self::class, 'renderMissingDependencyNotice']);
            return;
        }

        $projects = ProjectsPlugin::instance()->projects();
        $seating = new SeatingService(
            $this->repository,
            $projects,
            MembersPlugin::instance()->members(),
            LibraryPlugin::instance()->library()
        );

        \add_action('init', [$this->schema, 'migrateIfOutdated'], 1);
        \add_action('orchestra_core_register_capabilities', [Capabilities::class, 'register']);
        \add_action('before_delete_post', [$seating, 'deleteProject']);
        \add_action('deleted_user', [$seating, 'deleteUser']);
        (new PersonalData($this->repository, $projects))->register();
        $tab = new SeatingTab($seating, $this->pluginFile);
        $tab->register();
        (new SeatingPdfController($projects, $tab, new SeatingPdf($seating)))->register();
        (new SeatingController($seating, $projects))->register();
    }

    /**
     * @internal Hooked to `admin_notices`.
     */
    public static function renderMissingDependencyNotice(): void
    {
        echo '<div class="notice notice-error"><p>'
            . \esc_html__(
                'Orchestra Seating requires Orchestra Core 0.7, Orchestra Projects 0.14, Orchestra Library and'
                    . ' Orchestra Members to be active.',
                'orchestra-seating'
            )
            . '</p></div>';
    }
}
