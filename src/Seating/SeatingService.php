<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

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
     * @return list<Person> the people to seat with their voice in the plan's piece, ordered by name
     */
    public function people(SeatingPlan $plan): array
    {
        $pieceId = $this->pieceId($plan);
        $voices = [];
        if ($pieceId !== null) {
            $parts = [];
            foreach ($this->library->parts($pieceId) as $part) {
                $parts[$part->id] = $part->name;
            }
            foreach ($this->projects->assignments($plan->projectId) as $assignment) {
                if ($assignment->pieceId === $pieceId && $assignment->userId !== null) {
                    $voices[$assignment->userId] = $parts[$assignment->partId] ?? '';
                }
            }
        }

        $people = [];
        foreach ($this->peopleIds($plan->projectId, $plan->seatOrganizers) as $userId) {
            $member = $this->members->member($userId);
            if ($member !== null) {
                $people[] = new Person($userId, $member->displayName, $voices[$userId] ?? '');
            }
        }
        \usort($people, static fn (Person $a, Person $b): int => \strcasecmp($a->name, $b->name));

        return $people;
    }

    /**
     * The piece whose parts are shown: the chosen one while it is in the program, otherwise the
     * first piece of the program.
     */
    public function pieceId(SeatingPlan $plan): ?int
    {
        $program = $this->projects->programPieceIds($plan->projectId);
        if ($plan->pieceId !== null && \in_array($plan->pieceId, $program, true)) {
            return $plan->pieceId;
        }

        return $program[0] ?? null;
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

    public function save(SeatingPlan $plan): void
    {
        $this->repository->save($plan);
    }

    /**
     * Seatings exist only for projects, so nothing is deleted for other posts. Projects in the
     * trash are not found by orchestra-projects any more, hence no check of the post type.
     *
     * @internal Hooked to `before_delete_post`.
     */
    public function deleteProject(int $postId): void
    {
        $this->repository->deleteProject($postId);
    }

    /**
     * @internal Hooked to `deleted_user`.
     */
    public function deleteUser(int $userId): void
    {
        $this->repository->deleteUser($userId);
    }
}
