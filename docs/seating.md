# Seating

orchestra-seating plans who sits where in a project. It owns the seating data and adds a
**Seating** tab to the project page of orchestra-projects, with the tab filters of
orchestra-projects 0.14 (`ProjectsService::TABS_FILTER`, `TAB_CONTENT_FILTER`). It also adds the
**Seating** area `[orchestra_seating]` (menu item at position 50, for conductors and organizers)
with the **master seating**, the standing seating of the orchestra that projects start from. It reads
projects, participation, program and assignments through `ProjectsService`, members through
`MembersService` and parts through `LibraryService`, never their tables.

## Rules

- **Who is seated:** the accepted participants of the project who are active and have the role
  Member or Organizer. Conductors are never seated. Organizers are seated by default; the switch
  "Seat organizers" leaves them out for the project.
- **Voices:** the seating does not depend on a piece. Every seat shows the person's part in one
  piece of the program; the arrows above the grid step through the pieces (in program order,
  starting with the first), and only the voices change. People without a part in that piece show
  "—". Members and editors can both step through the pieces.
- **Changing voices:** in the editor, the voice of every person is a select of the parts of the
  shown piece, with "—" for none. Changing it saves right away and changes the assignment in
  orchestra-projects (`ProjectsService::setPart()`, orchestra-projects 0.27), with the rules of
  the assignments tab: only while the project is neither a draft nor completed, and
  only for users who may manage assignments (`orchestra_projects_assignment_manage`). Otherwise,
  and with an older orchestra-projects, the voices are shown as text. In the select, the part the
  person played last time in another project is green and listed first, then the parts they
  played before that in yellow, then the others, each group by name
  (`ProjectsService::playedParts()`, orchestra-projects 0.30; only for users with
  `orchestra_projects_assignment_history_read`). Browsers that show their own picker may ignore
  the colors.
- **Changed parts:** in "Parts assigned", the editor shows the notice of the assignments tab
  above the seating: "The parts of N members changed since the members were last informed.",
  with "Inform members about changes" (the review, which replaces the seating until it is sent
  or closed with "Back") and "Don't inform members" (`ProjectsService::partChanges()`,
  orchestra-projects 0.35, with orchestra-email 0.10; only for users with
  `orchestra_projects_assignment_manage`). Only part changes count, wherever they were made;
  moving people to other seats is no change.
- **Voice colors:** in the shown piece, every voice has a light color, chosen by the position of the
  part in the piece (eight colors, repeated after the eighth part); people with the same voice have
  the same color, people without a voice are grey. Every seated person has a thick line on all four
  sides, except towards a neighbour (side by side or one behind the other) with the same voice, so
  every section is outlined as a whole; people without a voice form sections of their own. Colors and lines
  change with the piece and follow the people while they are moved.
- **Grid:** every seat has the same size and holds one person. A new seating starts with a seat for
  everyone, wider than deep (e.g. 3 rows of 5 for 12 people); after that the grid never grows by
  itself, and people without a seat wait in "Not seated". "+" buttons between the rows (left of the
  grid) and between the columns (above it), and at both ends, insert a row or column there; the
  "×" button left of every row and above every column deletes it. The people on a deleted row or
  column are no longer seated; the rows behind or the columns to the right move up. Deleting a row
  or column with people asks first. The last row and column cannot be deleted. At most 30 rows and
  30 columns. The "×" buttons are faint until pointed at, so that they are not mistaken for the
  "+" next to them. "Clear seating" stands at the right end, away from "Save seating", and asks
  first. While everyone has a seat, "Not seated" only says so.
- **Size on screen:** the seats shrink with the width of the page (from 7rem to 4.5rem, names and
  voices in a smaller font), so that the whole grid usually fits; only a grid that still does not
  fit, e.g. on phones, scrolls sideways. Then its cut edges fade out and a hint below it says so
  (`seating.js` marks the scroll box with `is-scrollable`, `is-more-left` and `is-more-right`).
- **Rows** count from the front and are labelled with letters: row A is next to the conductor,
  shown at the bottom (after Z come AA, AB, …). **Columns** are numbered from 1 on the left. The
  labels appear beside and below the grid, in the PDF, in the seat selects ("Row A, seat 3") and
  in the personal data export. They are display only: the form and the database keep numbers.
- **Between two people:** dropping a person on the left or right edge of a taken seat puts them
  between the two people there. The people between that spot and the nearest empty seat of the
  row move one seat towards it (to the right if both sides are equally near); nobody changes
  rows. If the row is full, a column is added at the right and the people right of the spot move
  one seat to the right.
- **Master seating:** one seating (project ID 0) of every active member and organizer, edited in
  the Seating area with the same editor as a project, without voices (and so without the lines
  around the sections), without the organizers switch and without PDF. People who become inactive or lose the role leave it; new members wait
  in "Not seated".
- **Applying the master seating:** in a project, "Apply master seating" seats the people of the
  project (respecting "Seat organizers") in their row of the saved master seating. Every row is
  closed up to the left (gaps of people who do not take part disappear), and rows and columns
  nobody sits in are removed. People who are not in the master seating are not seated. It replaces
  the current seating and is saved right away; later changes of the master seating do not change
  the project. New projects start empty; the seating of another project cannot be imported.
- **People who no longer take part** (declined, inactive, organizers switched off, deleted) are
  not shown, and their seats are dropped the next time the seating is saved.

## Who can do what

| Capability | Member | Conductor | Organizer |
|---|:-:|:-:|:-:|
| `orchestra_seating_plan_read`: see the seating of projects visible to members, once it is arranged | ✓ | ✓ | ✓ |

Members see the seating only once the parts are assigned (status "Parts assigned" or "Completed"
of orchestra-projects 0.28, `ProjectStatus::assignmentsVisibleToMembers()`). Before, the Seating
tab only says "The seating has not been finished yet." ("Sitzplan noch nicht abgeschlossen").
With an older orchestra-projects, they see it once it is arranged, as before.
| `orchestra_seating_plan_manage`: arrange the seating of every project and the master seating, download it as PDF | | ✓ | ✓ |

The seating of completed projects cannot be changed.

## Editing

The editor of a project posts to `admin-post.php?action=orchestra_seating_save` with a nonce per
project, the master seating to `admin-post.php?action=orchestra_seating_master_save`. Every button
submits all seats, so changes are never lost; `op` says which one: `save`, `clear`, `apply_master`,
`insert_row-N` / `insert_column-N` (in front of row N, left of column N; N = size + 1 adds one at
the back or right), `delete_row-N` / `delete_column-N`, and `insert_between` with `insert_person`
(user ID) and `insert_at` (`row-column`: in front of that seat). Each of them reloads the page. The form contains one select per person (`seat[userId]` = `row-column`); without JavaScript
these are the editor. With JavaScript (`assets/seating.js`) they are hidden and kept in sync with
the grid:

- drag people between the seats and "Not seated" with mouse, touch or pen; dropping on a taken
  seat swaps the two, dropping on its left or right quarter puts the person between two people
  (in the browser if the row has an empty seat, otherwise with `op=insert_between`)
- keyboard: Enter or Space picks a person up, the arrow keys move between the seats, Enter or
  Space places them, Delete or Backspace unseats them, Escape cancels
- changing "Seat organizers" saves right away
- the number of seated people above the grid ("3 of 12 people seated", `data-seating-seated`)
  follows every move, before saving

Every person carries their voice in every piece of the program (`data-seating-voice`, hidden
except for the shown piece, with `data-seating-tone` and `data-seating-color`). When the voices
can be changed, these are selects (`voice[pieceId][userId]` = part ID or ""), each with a hidden
`voice_was[pieceId][userId]` holding the part shown when the page was loaded. Every submit of the
form changes the assignments whose select differs from `voice_was`, so a page loaded earlier does
not undo assignments made elsewhere; a changed select submits the form with `op=voices` right
away. Pressing a select never starts dragging its person, and the keys of the editor do not act
on it. The server renders
the colors and lines (`is-line-back`, `is-line-front`, `is-line-left`, `is-line-right`) of the first piece; `seating.js` redraws
them when the piece changes or someone is moved. With JavaScript the arrows switch the voices in place; without it they
are links to the tab with `seating_piece=ID`. The shown piece is kept in the URL and in the form's
`redirect_to`, so saving returns to the same piece. Moving people between two others is only
possible by dragging; the keyboard and the selects place and swap.

## PDF

"Download PDF" on the tab (`admin-post.php?action=orchestra_seating_pdf&project=ID`) creates a
landscape A4 page of the saved seating, for conductors and organizers
(`orchestra_seating_plan_manage`). It shows names only, because the voices depend on the piece.
Members see the seating but cannot download it. The seats shrink with the grid (at most 28 mm, at least 10 mm), so that up to 30 × 30 seats fit on one page. It is created by
orchestra-core 0.8 (`Orchestra\Core\Pdf\Pdf`); with an older core the button is hidden. Changes in
the editor must be saved before they appear in the PDF.

## Storage

| Table | Content |
|---|---|
| `{prefix}orchestra_seating_plans` | per project (`project_id` 0: the master seating): `grid_rows`, `grid_columns`, `seat_organizers`, `updated_at` |
| `{prefix}orchestra_seating_seats` | `project_id`, `user_id`, `seat_row`, `seat_column`; one seat per person and cell |

The schema version is stored in the option `orchestra_seating_schema_version`. Version 2 dropped
the column `piece_id` of version 1, when the seating stopped storing a piece.

- Deleting a project or a user deletes their seating or seats.
- **Tools → Export / Erase Personal Data** export and delete a user's seats.
- Deactivating keeps the data; deleting the plugin drops both tables and the option.
