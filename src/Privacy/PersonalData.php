<?php

declare(strict_types=1);

namespace Orchestra\Seating\Privacy;

use Orchestra\Projects\ProjectsService;
use Orchestra\Seating\Seating\Seat;
use Orchestra\Seating\Seating\SeatingPlan;
use Orchestra\Seating\Seating\SeatingRepository;
use WP_User;

/**
 * Connects the seats to the WordPress privacy tools (Tools → Export / Erase Personal Data) and
 * suggests a text for the privacy policy.
 */
final class PersonalData
{
    private const ID = 'orchestra-seating';

    public function __construct(
        private readonly SeatingRepository $seatings,
        private readonly ProjectsService $projects,
    ) {
    }

    public function register(): void
    {
        \add_filter('wp_privacy_personal_data_exporters', [$this, 'addExporter']);
        \add_filter('wp_privacy_personal_data_erasers', [$this, 'addEraser']);
        \add_action('admin_init', [self::class, 'addPolicyText']);
    }

    /**
     * @internal Hooked to `admin_init`.
     */
    public static function addPolicyText(): void
    {
        if (!\function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $text = \__(
            'Conductors and organizers arrange where the participants of a project sit. Once the parts are'
                . ' assigned, all members can see the seating with names and parts. People who no longer take part'
                . ' leave the seating; their seats are deleted when their account is deleted or erased.',
            'orchestra-seating'
        );

        \wp_add_privacy_policy_content(
            \__('Orchestra Seating', 'orchestra-seating'),
            \wp_kses_post('<p>' . \esc_html($text) . '</p>')
        );
    }

    /**
     * @internal Hooked to `wp_privacy_personal_data_exporters`.
     * @param array<mixed> $exporters
     * @return array<mixed>
     */
    public function addExporter(array $exporters): array
    {
        $exporters[self::ID] = [
            'exporter_friendly_name' => \__('Orchestra seating', 'orchestra-seating'),
            'callback' => [$this, 'export'],
        ];

        return $exporters;
    }

    /**
     * @internal Hooked to `wp_privacy_personal_data_erasers`.
     * @param array<mixed> $erasers
     * @return array<mixed>
     */
    public function addEraser(array $erasers): array
    {
        $erasers[self::ID] = [
            'eraser_friendly_name' => \__('Orchestra seating', 'orchestra-seating'),
            'callback' => [$this, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $user = \get_user_by('email', $email);
        if (!$user instanceof WP_User) {
            return ['data' => [], 'done' => true];
        }

        $items = [];
        foreach ($this->seatings->seatsOfUser($user->ID) as $seat) {
            $items[] = [
                'group_id' => self::ID,
                'group_label' => \__('Orchestra seating', 'orchestra-seating'),
                'item_id' => 'orchestra-seat-' . $seat['project_id'],
                'data' => [
                    [
                        'name' => \__('Project', 'orchestra-seating'),
                        'value' => $seat['project_id'] === SeatingPlan::MASTER
                            ? \__('Master seating', 'orchestra-seating')
                            : $this->projects->project($seat['project_id'])->title ?? '#' . $seat['project_id'],
                    ],
                    ['name' => \__('Row', 'orchestra-seating'), 'value' => Seat::rowName($seat['row'])],
                    ['name' => \__('Seat', 'orchestra-seating'), 'value' => (string) $seat['column']],
                ],
            ];
        }

        return ['data' => $items, 'done' => true];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $user = \get_user_by('email', $email);
        $seats = $user instanceof WP_User ? $this->seatings->seatsOfUser($user->ID) : [];
        if ($user instanceof WP_User) {
            $this->seatings->deleteUser($user->ID);
        }

        return ['items_removed' => $seats !== [], 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
