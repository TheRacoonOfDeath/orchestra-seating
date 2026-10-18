# Seating

orchestra-seating plans who sits where in a project. It owns the seating data and adds a
**Seating** tab to the project page of orchestra-projects, with the tab filters of
orchestra-projects 0.14 (`ProjectsService::TABS_FILTER`, `TAB_CONTENT_FILTER`). It reads
projects, participation, program and assignments through `ProjectsService`, members through
`MembersService` and parts through `LibraryService`, never their tables.

## Rules

- **Who is seated:** the accepted participants of the project who are active and have the role
  Member or Organizer. Conductors are never seated. Organizers are seated by default; the switch
  "Seat organizers" leaves them out for the project.
- **Voices:** every seat shows the person's part in one piece of the program, chosen in the editor
  (default: the first piece). People without a part in that piece show "—".
- **Grid:** every seat has the same size and holds one person. A new seating starts with a seat for
  everyone, wider than deep (e.g. 3 rows of 5 for 12 people). "+ Row" and "+ Column" add a row at
  the back or a seat at the right of every row; "− Row" and "− Column" remove the last row or
  column if it is empty. The grid keeps a seat for everyone: when more people accept, rows are
  added. At most 30 rows and 30 columns.
- **Rows** count from the front: row 1 is next to the conductor, shown at the bottom.
- **People who no longer take part** (declined, inactive, organizers switched off, deleted) are
  not shown, and their seats are dropped the next time the seating is saved.

## Who can do what

| Capability | Member | Conductor | Organizer |
|---|:-:|:-:|:-:|
| `orchestra_seating_plan_read`: see the seating of projects visible to members, once it is arranged | ✓ | ✓ | ✓ |
| `orchestra_seating_plan_manage`: arrange the seating of every project | | ✓ | ✓ |

The seating of completed projects cannot be changed.

## Editing

The editor posts to `admin-post.php?action=orchestra_seating_save` with a nonce per project.
Every button (save, add or remove a row or column, clear) submits all seats, so changes are never
lost. The form contains one select per person (`seat[userId]` = `row-column`); without JavaScript
these are the editor. With JavaScript (`assets/seating.js`) they are hidden and kept in sync with
the grid:

- drag people between the seats and "Not seated" with mouse, touch or pen; dropping on a taken
  seat swaps the two
- keyboard: Enter or Space picks a person up, the arrow keys move between the seats, Enter or
  Space places them, Delete or Backspace unseats them, Escape cancels
- changing the piece or "Seat organizers" saves right away

## PDF

"Download PDF" on the tab (`admin-post.php?action=orchestra_seating_pdf&project=ID`) creates a
landscape A4 page of the saved seating, for everyone who can see the tab. The seats shrink with the
grid (at most 28 mm, at least 10 mm), so that up to 30 × 30 seats fit on one page. It is created by
orchestra-core 0.8 (`Orchestra\Core\Pdf\Pdf`); with an older core the button is hidden. Changes in
the editor must be saved before they appear in the PDF.

## Storage

| Table | Content |
|---|---|
| `{prefix}orchestra_seating_plans` | per project: `grid_rows`, `grid_columns`, `piece_id`, `seat_organizers`, `updated_at` |
| `{prefix}orchestra_seating_seats` | `project_id`, `user_id`, `seat_row`, `seat_column`; one seat per person and cell |

The schema version is stored in the option `orchestra_seating_schema_version`.

- Deleting a project or a user deletes their seating or seats.
- **Tools → Export / Erase Personal Data** export and delete a user's seats.
- Deactivating keeps the data; deleting the plugin drops both tables and the option.
