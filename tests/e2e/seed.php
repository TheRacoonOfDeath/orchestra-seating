<?php

/**
 * Test data for e2e.py, run with `wp eval-file`: two pieces with parts in orchestra-library, and an
 * open project with both pieces, accepted participants and assignments in orchestra-projects.
 * Prints the IDs as JSON.
 */

declare(strict_types=1);

use Orchestra\Library\Database\Schema as LibrarySchema;
use Orchestra\Library\Part\PartRepository;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Database\Schema as ProjectsSchema;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;

global $wpdb;

$user = static fn (string $login): int => get_user_by('login', $login)->ID;
$ids = [];

$parts = new PartRepository($wpdb, new LibrarySchema($wpdb));
$pieces = [
    'pirates' => ['Pirates of the Caribbean', ['Horn 1', 'Horn 2']],
    'dance' => ['Lord of the Dance', ['Violin 1']],
];
foreach ($pieces as $key => [$title, $names]) {
    $ids[$key] = wp_insert_post(
        ['post_type' => 'orchestra_piece', 'post_status' => 'publish', 'post_title' => $title]
    );
    foreach ($names as $position => $name) {
        $ids[$key . ':' . $name] = $parts->insert($ids[$key], $name, '', $position)->id;
    }
}

$schema = new ProjectsSchema($wpdb);
$schema->migrateIfOutdated();
$project = wp_insert_post(
    ['post_type' => 'orchestra_project', 'post_status' => 'publish', 'post_title' => 'Spring Concert']
);
update_post_meta($project, 'orchestra_projects_status', 'open');
$ids['project'] = $project;

$program = new ProgramRepository($wpdb, $schema);
$program->add($project, $ids['pirates']);
$program->add($project, $ids['dance']);

$participations = new ParticipationRepository($wpdb, $schema);
$participations->createMissing($project, array_map($user, ['organizer', 'mia', 'max', 'mo', 'lena']));
$responses = [
    'organizer' => 'accepted',
    'mia' => 'accepted',
    'max' => 'accepted',
    'lena' => 'accepted',
    'mo' => 'declined',
];
foreach ($responses as $login => $status) {
    $participation = $participations->findFor($project, $user($login));
    $participations->setStatus($participation->id, ParticipationStatus::from($status), 1, true);
}

$assignments = new AssignmentRepository($wpdb, $schema);
$assignments->insert($project, $ids['pirates'], $ids['pirates:Horn 1'], $user('mia'), 1);
$assignments->insert($project, $ids['pirates'], $ids['pirates:Horn 2'], $user('max'), 1);
$assignments->insert($project, $ids['dance'], $ids['dance:Violin 1'], $user('mia'), 1);

echo json_encode($ids);
