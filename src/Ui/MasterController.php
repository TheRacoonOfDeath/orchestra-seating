<?php

declare(strict_types=1);

namespace Orchestra\Seating\Ui;

use Orchestra\Core\Ui\LoginPageRedirect;
use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Seating\Capabilities;
use Orchestra\Seating\Seating\SeatingInput;
use Orchestra\Seating\Seating\SeatingService;

/**
 * Saves the master seating form (admin-post.php?action=orchestra_seating_master_save). Every button
 * of the form posts here; the `op` field says which one.
 */
final class MasterController
{
    public const ACTION = 'orchestra_seating_master_save';

    public function __construct(private readonly SeatingService $seating)
    {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, [LoginPageRedirect::class, 'requireLogin']);
    }

    /**
     * @internal Hooked to `admin_post_orchestra_seating_master_save`.
     */
    public function handle(): never
    {
        \check_admin_referer(self::ACTION);
        if (!\current_user_can(Capabilities::PLAN_MANAGE)) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-seating'), 403);
        }

        // Everyone of the master seating is seated, organizers included.
        $input = [SeatingInput::FIELD_SEAT_ORGANIZERS => '1'] + \wp_unslash($_POST);
        [$plan, $errors] = SeatingInput::apply(
            $input,
            $this->seating->masterPlan(),
            fn (): array => $this->seating->masterPeopleIds(),
            null
        );
        if ($errors !== []) {
            Notices::errors($errors);
            Redirect::back();
        }

        $this->seating->save($plan);
        if (($input[SeatingInput::FIELD_OPERATION] ?? SeatingInput::SAVE) === SeatingInput::SAVE) {
            Notices::success(\__('The master seating has been saved.', 'orchestra-seating'));
        }
        Redirect::back();
    }
}
