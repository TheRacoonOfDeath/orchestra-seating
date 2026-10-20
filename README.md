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
  with a select per person without JavaScript; members see it once it is arranged
- The grid starts with a seat for everyone; rows and columns can be added and removed
- Rows are labelled A, B, … from the front, columns 1, 2, … from the left
- The seating of another project can be imported: people in both projects keep their seat, new
  people are not seated
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
