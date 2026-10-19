<?php

declare(strict_types=1);

namespace Orchestra\Seating\Export;

use Orchestra\Core\Pdf\Pdf;
use Orchestra\Projects\Project\Project;
use Orchestra\Seating\Seating\Person;
use Orchestra\Seating\Seating\Seat;
use Orchestra\Seating\Seating\SeatingService;

/**
 * The seating of a project as a landscape PDF: the grid with the front row at the bottom, next to
 * the conductor, like on the page, with the row letters on the left and the column numbers below.
 * It shows names only, because the voices depend on the piece. The seats shrink with the size of
 * the grid, so that it fits on one page.
 */
final class SeatingPdf
{
    /** Width and height of the printable area of a landscape A4 page, minus title and footer, in mm. */
    private const AREA_WIDTH = 270;
    private const AREA_HEIGHT = 140;
    private const MAX_SEAT = 28;
    private const MIN_SEAT = 10;
    /** Space between the seats and padding inside them, in mm; both count towards the seat size. */
    private const SPACING = 1.0;
    private const PADDING = 0.8;
    /** Width of the row letters and height of the column numbers, in mm. */
    private const LABEL = 5.0;

    public function __construct(private readonly SeatingService $seating)
    {
    }

    public function html(Project $project): string
    {
        $plan = $this->seating->plan($project);
        $people = [];
        foreach ($this->seating->people($plan) as $person) {
            $people[$person->userId] = $person;
        }

        $size = self::seatSize($plan->grid->rows, $plan->grid->columns);
        $rows = '';
        for ($row = $plan->grid->rows; $row >= 1; $row--) {
            $rows .= '<tr><td class="label">' . Seat::rowName($row) . '</td>';
            for ($column = 1; $column <= $plan->grid->columns; $column++) {
                $seat = $plan->at($row, $column);
                $person = $seat === null ? null : ($people[$seat->userId] ?? null);
                $rows .= $person === null ? '<td class="seat empty"></td>' : self::seat($person);
            }
            $rows .= '</tr>';
        }
        $rows .= '<tr><td class="label"></td>';
        for ($column = 1; $column <= $plan->grid->columns; $column++) {
            $rows .= '<td class="label number">' . $column . '</td>';
        }
        $rows .= '</tr>';

        $width = $size * $plan->grid->columns + self::SPACING;
        $inner = \round($size - self::SPACING - 2 * self::PADDING, 1);
        $body = '<table class="grid">' . $rows . '</table>'
            . '<div class="front" style="width: ' . $width . 'mm; padding-left: ' . (self::LABEL + self::SPACING)
            . 'mm">' . \esc_html__('Conductor', 'orchestra-seating')
            . '</div>';

        $nameSize = \max(5.5, \min(10.0, $size * 0.36));

        return Pdf::document(
            \sprintf(
                /* translators: %s: project name */
                \__('Seating: %s', 'orchestra-seating'),
                $project->title
            ),
            $body,
            '.grid { width: auto; margin: 4mm auto 0; border-collapse: separate; border-spacing: '
                . self::SPACING . 'mm; }'
                . '.seat { width: ' . $inner . 'mm; height: ' . $inner . 'mm; padding: ' . self::PADDING . 'mm;'
                . ' text-align: center; vertical-align: middle; border: 0.6pt solid #8da2d8;'
                . ' background: #e6ebf7; overflow: hidden; }'
                . '.label { width: ' . self::LABEL . 'mm; padding: 0; text-align: center; vertical-align: middle;'
                . ' font-size: 7.5pt; font-weight: bold; color: #6b6b6b; border: 0; background: none; }'
                . '.label.number { height: ' . self::LABEL . 'mm; width: auto; }'
                . '.seat.empty { background: #fff; border: 0.6pt dashed #c8c8c8; }'
                . '.name { font-weight: bold; font-size: ' . $nameSize . 'pt; line-height: 1.15; }'
                . '.front { margin: 2mm auto 0; padding-top: 1mm; border-top: 1.5pt solid #b8b8b8;'
                . ' text-align: center; font-size: 7.5pt; letter-spacing: 1pt; color: #6b6b6b;'
                . ' text-transform: uppercase; }'
        );
    }

    /**
     * The width and height of a seat in mm.
     */
    public static function seatSize(int $rows, int $columns): float
    {
        $size = \min(
            self::MAX_SEAT,
            (self::AREA_WIDTH - self::LABEL) / \max(1, $columns),
            (self::AREA_HEIGHT - self::LABEL) / \max(1, $rows)
        );

        return \round(\max(self::MIN_SEAT, $size), 1);
    }

    private static function seat(Person $person): string
    {
        return '<td class="seat"><div class="name">' . \esc_html($person->name) . '</div></td>';
    }
}
