<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

use Closure;
use Orchestra\Core\Authorization\Roles;

/**
 * Who is seated: the accepted participants of the project who are active members or organizers.
 * Conductors are never seated; organizers can be left out of the seating.
 */
final class Eligibility
{
    /**
     * @param list<int> $acceptedUserIds
     * @param Closure(int $userId): ?list<string> $roles The orchestra roles of an active member,
     *        null for users who are inactive or have no orchestra role
     * @return list<int>
     */
    public static function people(array $acceptedUserIds, Closure $roles, bool $seatOrganizers): array
    {
        $seated = static function (int $userId) use ($roles, $seatOrganizers): bool {
            $userRoles = $roles($userId);
            if ($userRoles === null) {
                return false;
            }
            $organizer = \in_array(Roles::ORGANIZER, $userRoles, true);

            return ($organizer && $seatOrganizers) || (!$organizer && \in_array(Roles::MEMBER, $userRoles, true));
        };

        return \array_values(\array_filter($acceptedUserIds, $seated));
    }
}
