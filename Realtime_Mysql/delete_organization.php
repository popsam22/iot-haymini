<?php
/**
 * Delete an organization and all its data from the database.
 *
 * Usage:
 *   php delete_organization.php --id=<org_id>
 *   php delete_organization.php --name="<org_name>"
 *   php delete_organization.php --id=<org_id> --dry-run
 *   php delete_organization.php --id=<org_id> --force    (skip confirmation prompt)
 */

if (php_sapi_name() !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/config.php';

// ── Parse args ────────────────────────────────────────────────────────────────

$opts = getopt('', ['id:', 'name:', 'dry-run', 'force']);

$orgId   = $opts['id']   ?? null;
$orgName = $opts['name'] ?? null;
$dryRun  = isset($opts['dry-run']);
$force   = isset($opts['force']);

if (!$orgId && !$orgName) {
    exit("Error: provide --id=<org_id> or --name=\"<org_name>\"\n");
}

// ── Lookup organization ───────────────────────────────────────────────────────

$pdo = getValidConnection();

if ($orgId) {
    $stmt = $pdo->prepare('SELECT id, name FROM organizations WHERE id = ?');
    $stmt->execute([$orgId]);
} else {
    $stmt = $pdo->prepare('SELECT id, name FROM organizations WHERE name = ?');
    $stmt->execute([$orgName]);
}

$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    $label = $orgId ? "id={$orgId}" : "name=\"{$orgName}\"";
    exit("Error: no organization found with {$label}\n");
}

$orgId   = (int) $org['id'];
$orgName = $org['name'];

echo "\nOrganization: [{$orgId}] {$orgName}\n";
echo str_repeat('-', 50) . "\n";

// ── Count rows to be deleted per table ───────────────────────────────────────

// user_device_assignments has no organization_id — scope it via users in the org
$counts = [];

$counts['user_device_assignments (via users)'] = fetchCount($pdo,
    'SELECT COUNT(*) FROM user_device_assignments uda
     JOIN users u ON uda.user_id = u.id
     WHERE u.organization_id = ?', [$orgId]);

$simpleOrgTables = [
    'absence_records',
    'attendance_logs',
    'daily_attendance',
    'tblt_timesheet',
    'organization_punch_settings',
    'admins',
    'users',
    'devices',
];

foreach ($simpleOrgTables as $table) {
    $counts[$table] = fetchCount($pdo,
        "SELECT COUNT(*) FROM `{$table}` WHERE organization_id = ?", [$orgId]);
}

$counts['organizations'] = 1;

echo "Rows to be deleted:\n";
$totalRows = 0;
foreach ($counts as $table => $count) {
    printf("  %-45s %d\n", $table, $count);
    $totalRows += $count;
}
printf("  %-45s %d\n", 'TOTAL', $totalRows);
echo str_repeat('-', 50) . "\n";

if ($dryRun) {
    echo "[DRY RUN] No changes made.\n\n";
    exit(0);
}

// ── Confirmation ──────────────────────────────────────────────────────────────

if (!$force) {
    echo "\nThis will PERMANENTLY delete the organization and all its data.\n";
    echo "Type \"yes\" to confirm: ";
    $input = trim(fgets(STDIN));
    if ($input !== 'yes') {
        exit("Aborted.\n");
    }
}

// ── Delete inside a transaction ───────────────────────────────────────────────

echo "\nDeleting...\n";

try {
    $pdo->beginTransaction();

    // 1. user_device_assignments (no org column — scope via users)
    $deleted = $pdo->prepare(
        'DELETE uda FROM user_device_assignments uda
         JOIN users u ON uda.user_id = u.id
         WHERE u.organization_id = ?'
    );
    $deleted->execute([$orgId]);
    echo "  user_device_assignments  deleted: {$deleted->rowCount()}\n";

    // 2–9. Tables with organization_id
    $orderedTables = [
        'absence_records',
        'attendance_logs',
        'daily_attendance',
        'tblt_timesheet',
        'organization_punch_settings',
        'admins',
        'users',
        'devices',
    ];

    foreach ($orderedTables as $table) {
        $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE organization_id = ?");
        $stmt->execute([$orgId]);
        printf("  %-40s deleted: %d\n", $table, $stmt->rowCount());
    }

    // 10. The organization itself
    $stmt = $pdo->prepare('DELETE FROM organizations WHERE id = ?');
    $stmt->execute([$orgId]);
    echo "  organizations            deleted: {$stmt->rowCount()}\n";

    $pdo->commit();
    echo "\nDone. Organization [{$orgId}] \"{$orgName}\" and all its data have been removed.\n\n";

} catch (PDOException $e) {
    $pdo->rollBack();
    echo "\nError during deletion — transaction rolled back.\n";
    echo "Details: " . $e->getMessage() . "\n\n";
    exit(1);
}

// ── Helper ────────────────────────────────────────────────────────────────────

function fetchCount(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}
