<?php

declare(strict_types=1);

namespace Orchestra\Seating\Privacy;

use Orchestra\Projects\ProjectsService;
use Orchestra\Seating\Seating\Seat;
use Orchestra\Seating\Seating\SeatingRepository;
use WP_User;

/**
 * Connects the seats to the WordPress privacy tools (Tools → Export / Erase Personal Data).
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
                        'value' => $this->projects->project($seat['project_id'])->title ?? '#' . $seat['project_id'],
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
