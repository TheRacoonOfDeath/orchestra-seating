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

        $people = [];
        foreach ($this->peopleIds($plan->projectId, $plan->seatOrganizers) as $userId) {
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
     * The saved seating of a project as it was arranged, without the participation changes since.
     */
    public function savedPlan(int $projectId): ?SeatingPlan
    {
        return $this->repository->find($projectId);
    }

    /**
     * @return array<int, string> project ID => name of the other projects with a saved seating,
     *         newest first
     */
    public function importSources(int $projectId): array
    {
        $withSeating = $this->repository->projectIds();
        $sources = [];
        foreach ($this->projects->projects() as $project) {
            if ($project->id !== $projectId && \in_array($project->id, $withSeating, true)) {
                $sources[$project->id] = $project->title;
            }
        }

        return $sources;
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
