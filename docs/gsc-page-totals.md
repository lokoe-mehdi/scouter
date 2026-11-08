# Search Console page totals

Google can omit traffic from API requests grouped by `page,country,device`,
even when a request grouped by `page` returns it. Summing segmented rows is
therefore not a reliable way to obtain URL totals.

Scouter imports both datasets separately:

- `gsc_page_totals_daily`: page-only data for unfiltered URL reports, URL-filtered
  KPIs, crawl performance reports, SQL/MCP joins and per-URL anonymized remainders.
- `gsc_page_daily`: the existing country/device detail, used when those dimensions
  are selected or filtered. These results can still be incomplete at Google's end.
- `gsc_ingestion_days`: internal completion markers written after every dataset
  for a day succeeds, including zero-traffic days.

The SQL Explorer exposes `gsc_page_totals_daily` scoped to the current project.
It preserves legacy detail until each day has been repaired, then switches to
the page-only snapshot. Completion versions prevent partially imported or stale
URL rows from being included. Do not add the segmented table to these totals.

## Existing installations

The schema additions are idempotent and do not drop existing data. Rebuild and
restart as usual so the crawler/PHP schema creation runs. The normal GSC
reconciler detects old days without completion markers and reimports them in
bounded sync jobs. While this repair runs, unrepaired days retain their previous
figures. No reconnection or token replacement is required.

To trigger the reconciler immediately instead of waiting for cron:

```sh
docker compose -f docker-compose.local.yml exec scouter php app/bin/gsc-reconciler.php --verbose
```

Google retains a rolling history. Data outside Google's retention window cannot
be recovered by reimporting and remains available through the legacy fallback.

Page-level impressions may legitimately exceed property-level impressions,
because multiple URLs can appear in the same search. Compare URL totals with
the Search Console Pages export, not with the property's headline total.

References:

- https://developers.google.com/webmaster-tools/v1/how-tos/all-your-data
- https://support.google.com/webmasters/answer/17011364

## Regression tests

Run the ingestion, routing, coverage and schema unit tests with Pest. Integration
tests in `tests/Feature/GscPageTotalsTest.php` require a **disposable** ClickHouse
database named `scouter`, accessible as its `default` user:

```sh
GSC_TEST_CLICKHOUSE_URL=http://localhost:18123 vendor/bin/pest tests/Feature/GscPageTotalsTest.php
```

They cover omitted segmented traffic, legacy fallback, completed and empty days,
URL-filtered reports/exports, snapshot replacement and project isolation.
