# orchestra-seating
Seating plans for the projects of the Orchestra plugin suite.

Part of the Orchestra plugin suite. Requires
[orchestra-core](https://github.com/TheRacoonOfDeath/orchestra-core),
[orchestra-projects](https://github.com/TheRacoonOfDeath/orchestra-projects) 0.14 or later,
[orchestra-library](https://github.com/TheRacoonOfDeath/orchestra-library) and
[orchestra-members](https://github.com/TheRacoonOfDeath/orchestra-members).

## Features

- A **Seating** tab on every project, on the projects page and in wp-admin
- A grid of seats of equal size; each person shows their name and their part in a piece of the
  program, and arrows step through the pieces of the program
- Each voice has its own light color, and a thick line outlines every section of people with the same voice
- Conductors and organizers arrange the seating by drag and drop (mouse, touch or keyboard), or
  with a select per person without JavaScript; members see it once it is arranged and the parts are
  assigned (status "Parts assigned" of orchestra-projects 0.28; before, "Sitzplan noch nicht
  abgeschlossen")
- Conductors and organizers change a person's voice right on their seat with a dropdown of the
  piece's parts; it changes the assignment in the project (with orchestra-projects 0.27 or later,
  while the project is neither a draft nor completed). The part a person played last
  time is green, a part they played before yellow
- A **Seating** area with the master seating of all active members and organizers, for conductors
  and organizers
- "Apply master seating" seats a project's participants in their row of the master seating,
  closed up to the left, without empty rows and columns
- The grid starts with a seat for everyone; "+" and "×" buttons around the grid insert and delete
  rows and columns anywhere, and people on a deleted row or column are no longer seated
- Dropping a person on the edge of a seat puts them between two people; the others move aside
- Rows are labelled A, B, … from the front, columns 1, 2, … from the left
- Conductors and organizers download the seating as a PDF with names (with orchestra-core 0.8 or
  later)
- Accepted participants are seated, conductors never; organizers by default, which can be
  switched off per project

## Installation

1. Install and activate orchestra-core, orchestra-library, orchestra-members and
   orchestra-projects 0.14 or later.
2. Upload `orchestra-seating-{VERSION}.zip` under **Plugins → Add New → Upload Plugin** and
   activate it.

Updates are installed from the GitHub releases of this repository. The plugin reads
`update.json` from the latest release, so the GitHub API and its rate limit are not involved and
no token is needed.

## Documentation

- [Seating: rules, storage and capabilities](docs/seating.md)

The domain model, use cases and capabilities of the whole suite are documented in orchestra-core.

## Development

```bash
composer install
composer check      # coding standard, static analysis and unit tests
composer e2e        # end-to-end test with WordPress and the other Orchestra plugins (tests/e2e/run.sh)
```
