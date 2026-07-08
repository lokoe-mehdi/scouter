<?php
/**
 * Rebuild the GSC ClickHouse tables + re-backfill every connector.
 *
 * DESTRUCTIVE. Run this once after deploying the schema change that adds the
 * `country` / `device` dimensions and the URL-fragment stripping. It:
 *   1. DROPs + recreates the 4 gsc_* tables (the ORDER BY changed → can't ALTER
 *      in place). All current GSC data is wiped.
 *   2. Resets every active connector's cursor and enqueues a full backfill job,
 *      which the existing worker processes day by day (resumable).
 *
 * Usage (inside the app container):
 *   php /app/scripts/gsc-rebuild-schema.php            # recreate + re-backfill all
 *   php /app/scripts/gsc-rebuild-schema.php --schema   # recreate tables only
 *   php /app/scripts/gsc-rebuild-schema.php --dry-run  # list connectors, change nothing
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Gsc\GscSchema;
use App\Gsc\ConnectorRepository;
use App\Gsc\GscJobRunner;

$schemaOnly = in_array('--schema', $argv, true);
$dryRun     = in_array('--dry-run', $argv, true);

$repo = new ConnectorRepository();
$connectors = $repo->getActive();

fwrite(STDOUT, "GSC schema rebuild\n==================\n");
fwrite(STDOUT, 'Active connectors: ' . count($connectors) . "\n");
foreach ($connectors as $c) {
    fwrite(STDOUT, "  #{$c->id}  project={$c->project_id}  site={$c->site_url}\n");
}

if ($dryRun) {
    fwrite(STDOUT, "\n--dry-run: nothing changed.\n");
    exit(0);
}

fwrite(STDOUT, "\n[1/2] Recreating the 4 gsc_* ClickHouse tables (DROP + CREATE)…\n");
GscSchema::recreate();
fwrite(STDOUT, "      done — tables are empty.\n");

if ($schemaOnly) {
    fwrite(STDOUT, "\n--schema: tables recreated, no backfill enqueued.\n");
    exit(0);
}

fwrite(STDOUT, "\n[2/2] Enqueuing a full re-backfill per connector…\n");
$n = 0;
foreach ($connectors as $c) {
    $repo->resetBackfill((int) $c->id);
    $jobId = GscJobRunner::enqueueBackfill((int) $c->id, (string) $c->site_url);
    fwrite(STDOUT, "      #{$c->id} ({$c->site_url}) → job #{$jobId}\n");
    $n++;
}
fwrite(STDOUT, "\nDone. {$n} backfill job(s) enqueued; the worker will fill day by day.\n");
