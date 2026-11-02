<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

use Orchestra\Core\Authorization\Roles;
use Orchestra\Library\LibraryService;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\ProjectsService;

/**
 * The seating of a project, put together from the participation and assignments of
 * orchestra-projects, the members of orchestra-members and the parts of orchestra-library.
 */
final class SeatingService
{
    public function __construct(
        private readonly SeatingRepository $repository,
        private readonly ProjectsService $projects,
        private readonly MembersService $members,
        private readonly LibraryService $library,
    ) {
    }

    /**
     * The saved seating, or a new one with a grid large enough for everyone. Seats of people who no
     * longer take part are left out.
     */
    public function plan(Project $project): SeatingPlan
    {
        $plan = $this->repository->find($project->id);
        if ($plan === null) {
            $plan = SeatingPlan::initial($project->id, \count($this->peopleIds($project->id, true)));
        }

        return $plan->arrangedFor($this->peopleIds($project->id, $plan->seatOrganizers));
    }

    /**
     * @return list<int> the people to seat
     */
    public function peopleIds(int $projectId, bool $seatOrganizers): array
    {
        return Eligibility::people(
            $this->projects->participantIds($projectId, ParticipationStatus::Accepted),
            function (int $userId): ?array {
                $member = $this->members->member($userId);

                return $member === null || !$member->active ? null : $member->roles;
            },
            $seatOrganizers
        );
    }

    /**
     * @return list<Person> the people to seat with their voices in the pieces of the program,
     *         ordered by name
     */
    public function people(SeatingPlan $plan): array
    {
        $parts = [];
        foreach ($this->projects->programPieceIds($plan->projectId) as $pieceId) {
            foreach ($this->library->parts($pieceId) as $position => $part) {
                $parts[$part->id] = [$part->name, $position];
            }
        }
        $voices = [];
        $tones = [];
        foreach ($this->projects->assignments($plan->projectId) as $assignment) {
            if ($assignment->userId !== null && isset($parts[$assignment->partId])) {
                [$voices[$assignment->userId][$assignment->pieceId], $tones[$assignment->userId][$assignment->pieceId]]
                    = $parts[$assignment->partId];
            }
        }

        return $this->named($this->peopleIds($plan->projectId, $plan->seatOrganizers), $voices, $tones);
    }

    /**
     * @param list<int> $userIds
     * @param array<int, array<int, string>> $voices user ID => piece ID => voice
     * @param array<int, array<int, int>> $tones user ID => piece ID => tone
     * @return list<Person> ordered by name
     */
    private function named(array $userIds, array $voices, array $tones): array
    {
        $people = [];
        foreach ($userIds as $userId) {
            $member = $this->members->member($userId);
            if ($member !== null) {
                $people[] = new Person($userId, $member->displayName, $voices[$userId] ?? [], $tones[$userId] ?? []);
            }
        }
        \usort($people, static fn (Person $a, Person $b): int => \strcasecmp($a->name, $b->name));

        return $people;
    }

    /**
     * @return array<int, string> piece ID => title, in program order
     */
    public function programPieces(int $projectId): array
    {
        $pieces = [];
        foreach ($this->projects->programPieceIds($projectId) as $pieceId) {
            $pieces[$pieceId] = $this->library->piece($pieceId)->title ?? '#' . $pieceId;
        }

        return $pieces;
    }

    /**
     * The saved master seating, or a new one with a grid large enough for everyone. Seats of people
     * who are no longer active members or organizers are left out.
     */
    public function masterPlan(): SeatingPlan
    {
        $people = $this->masterPeopleIds();
        $plan = $this->repository->find(SeatingPlan::MASTER)
            ?? SeatingPlan::initial(SeatingPlan::MASTER, \count($people));

        return $plan->arrangedFor($people);
    }

    /**
     * The saved master seating as it was arranged, or null if it has never been saved.
     */
    public function savedMaster(): ?SeatingPlan
    {
        return $this->repository->find(SeatingPlan::MASTER);
    }

    /**
     * @return list<int> the people of the master seating: every active member and organizer
     */
    public function masterPeopleIds(): array
    {
        return $this->members->activeMemberIds([Roles::MEMBER, Roles::ORGANIZER]);
    }

    /**
     * @return list<Person> the people of the master seating, without voices, ordered by name
     */
    public function masterPeople(): array
    {
        return $this->named($this->masterPeopleIds(), [], []);
    }

    public function save(SeatingPlan $plan): void
    {
        $this->repository->save($plan);
    }

    /**
     * Seatings exist only for projects, so nothing is deleted for other posts; the master seating
     * has the ID 0, which is never deleted with a post. Projects in the trash are not found by orchestra-projects any
     * more, hence no check of the post type.
     *
     * @internal Hooked to `before_delete_post`.
     */
    public function deleteProject(int $postId): void
    {
        if ($postId !== SeatingPlan::MASTER) {
            $this->repository->deleteProject($postId);
        }
    }

    /**
     * @internal Hooked to `deleted_user`.
     */
    public function deleteUser(int $userId): void
    {
        $this->repository->deleteUser($userId);
    }
}
