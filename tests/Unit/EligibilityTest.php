<?php

declare(strict_types=1);

namespace Orchestra\Seating\Tests\Unit;

use Orchestra\Core\Authorization\Roles;
use Orchestra\Seating\Seating\Eligibility;
use PHPUnit\Framework\TestCase;

final class EligibilityTest extends TestCase
{
    public function testSeatsMembersAndOptionallyOrganizers(): void
    {
        $roles = static fn (int $userId): ?array => [
            1 => [Roles::MEMBER],
            2 => [Roles::ORGANIZER],
            3 => [Roles::CONDUCTOR],
            4 => null, // inactive or no orchestra role
            5 => [Roles::MEMBER, Roles::ORGANIZER],
            6 => [Roles::LIBRARIAN],
        ][$userId] ?? null;

        $this->assertSame([1, 2, 5, 6], Eligibility::people([1, 2, 3, 4, 5, 6], $roles, true));
        $this->assertSame([1, 6], Eligibility::people([1, 2, 3, 4, 5, 6], $roles, false));
    }
}
