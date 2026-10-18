<?php

declare(strict_types=1);

namespace Orchestra\Seating;

use Orchestra\Core\Authorization\CapabilityRegistry;
use Orchestra\Core\Authorization\Roles;

/**
 * The capabilities owned by orchestra-seating. See orchestra-core's docs/capabilities.md.
 */
final class Capabilities
{
    /** See the seating of projects that are visible to members. */
    public const PLAN_READ = 'orchestra_seating_plan_read';

    /** Change the seating of every project. */
    public const PLAN_MANAGE = 'orchestra_seating_plan_manage';

    /**
     * @internal Hooked to `orchestra_core_register_capabilities`.
     */
    public static function register(CapabilityRegistry $registry): void
    {
        $registry
            ->register(self::PLAN_READ, [Roles::MEMBER, Roles::CONDUCTOR, Roles::ORGANIZER])
            ->register(self::PLAN_MANAGE, [Roles::CONDUCTOR, Roles::ORGANIZER]);
    }
}
