<?php

declare(strict_types=1);

namespace Orchestra\Seating\Seating;

use Orchestra\Core\Database\Row;
use Orchestra\Seating\Database\Schema;
use wpdb;

final class SeatingRepository
{
    public function __construct(private readonly wpdb $wpdb, private readonly Schema $schema)
    {
    }

    public function find(int $projectId): ?SeatingPlan
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare('SELECT * FROM %i WHERE project_id = %d', $this->schema->plansTable(), $projectId),
            ARRAY_A
        );
        if (!\is_array($row)) {
            return null;
        }

        $seats = \array_map(static fn (array $seat): Seat => new Seat(
            Row::int($seat, 'user_id'),
            Row::int($seat, 'seat_row'),
            Row::int($seat, 'seat_column'),
        ), Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE project_id = %d ORDER BY seat_row, seat_column',
                $this->schema->seatsTable(),
                $projectId
            ),
            ARRAY_A
        )));

        return new SeatingPlan(
            $projectId,
            new GridSize(\max(1, Row::int($row, 'grid_rows')), \max(1, Row::int($row, 'grid_columns'))),
            Row::int($row, 'seat_organizers') === 1,
            $seats,
            true
        );
    }

    public function save(SeatingPlan $plan): void
    {
        $this->wpdb->replace($this->schema->plansTable(), [
            'project_id' => $plan->projectId,
            'grid_rows' => $plan->grid->rows,
            'grid_columns' => $plan->grid->columns,
            'seat_organizers' => $plan->seatOrganizers ? 1 : 0,
            'updated_at' => \current_time('mysql', true),
        ], ['%d', '%d', '%d', '%d', '%s']);

        $this->wpdb->delete($this->schema->seatsTable(), ['project_id' => $plan->projectId], ['%d']);
        foreach ($plan->seats as $seat) {
            $this->wpdb->insert($this->schema->seatsTable(), [
                'project_id' => $plan->projectId,
                'user_id' => $seat->userId,
                'seat_row' => $seat->row,
                'seat_column' => $seat->column,
            ], ['%d', '%d', '%d', '%d']);
        }
    }

    /**
     * @return list<int> the projects with a saved seating
     */
    public function projectIds(): array
    {
        return \array_map('intval', $this->wpdb->get_col(
            $this->wpdb->prepare('SELECT project_id FROM %i', $this->schema->plansTable())
        ));
    }

    public function deleteProject(int $projectId): void
    {
        $this->wpdb->delete($this->schema->seatsTable(), ['project_id' => $projectId], ['%d']);
        $this->wpdb->delete($this->schema->plansTable(), ['project_id' => $projectId], ['%d']);
    }

    public function deleteUser(int $userId): void
    {
        $this->wpdb->delete($this->schema->seatsTable(), ['user_id' => $userId], ['%d']);
    }

    /**
     * @return list<array{project_id: int, row: int, column: int}> the seats of a user, for the
     *         personal data export
     */
    public function seatsOfUser(int $userId): array
    {
        return \array_map(static fn (array $row): array => [
            'project_id' => Row::int($row, 'project_id'),
            'row' => Row::int($row, 'seat_row'),
            'column' => Row::int($row, 'seat_column'),
        ], Row::all($this->wpdb->get_results(
            $this->wpdb->prepare('SELECT * FROM %i WHERE user_id = %d', $this->schema->seatsTable(), $userId),
            ARRAY_A
        )));
    }
}
