<?php

declare(strict_types=1);

namespace Orchestra\Seating\Ui;

use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Seating\Capabilities;
use Orchestra\Seating\Seating\SeatingService;

/**
 * `[orchestra_seating]`: the master seating, the standing seating of every active member and
 * organizer, for conductors and organizers. Projects take it over with "Apply master seating".
 */
final class MasterShortcode
{
    public const TAG = 'orchestra_seating';

    public function __construct(private readonly SeatingService $seating, private readonly string $pluginFile)
    {
    }

    public function register(): void
    {
        \add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * @internal Shortcode callback.
     */
    public function render(): string
    {
        if (!\is_user_logged_in()) {
            return Html::loginRequired(\__('Please log in to see the seating.', 'orchestra-seating'));
        }
        if (!\current_user_can(Capabilities::PLAN_MANAGE)) {
            return Html::notAllowed(\__('You are not allowed to arrange the seating.', 'orchestra-seating'));
        }

        SeatingEditor::enqueue($this->pluginFile);
        $plan = $this->seating->masterPlan();
        $people = [];
        foreach ($this->seating->masterPeople() as $person) {
            $people[$person->userId] = $person;
        }

        return Html::page(
            '<h2>' . \esc_html__('Master seating', 'orchestra-seating') . '</h2>'
            . SeatingEditor::form(
                MasterController::ACTION,
                MasterController::ACTION,
                ['redirect_to' => Redirect::currentUrl()],
                '<p>' . \esc_html__(
                    'The usual seating of every active member and organizer. In a project, "Apply master seating"'
                        . ' seats everyone who takes part in their row of the master seating, closed up to the left.',
                    'orchestra-seating'
                ) . '</p>',
                '',
                $plan,
                $people,
                [],
                null,
                \__('There are no active members yet.', 'orchestra-seating')
            )
        );
    }
}
