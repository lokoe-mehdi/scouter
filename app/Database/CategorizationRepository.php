<?php

namespace App\Database;

use PDO;

/**
 * Categorization config of a crawl (the URL-segmentation YAML).
 *
 * A crawl FREEZES the project's rules into its own `categorization_config` row at
 * creation; editing the segments upserts that row for every crawl of the project
 * (see CategorizationController). So "what a new crawl should start from" is a
 * three-step lookup, and getting it wrong means the user silently loses their
 * segmentation and lands back on the generic cat.yml template.
 *
 * This lives here because BOTH creation paths need it and used to disagree: the
 * UI inherited (ProjectController::create), while the public API / MCP
 * `create_crawl` always applied the blank template — same project, same domain,
 * config gone.
 *
 * @package    Scouter
 * @subpackage Database
 */
class CategorizationRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * The YAML a brand-new crawl of this project should start with:
     *
     *   1. the most recent OTHER crawl of the project that has one — the live
     *      state of the user's segmentation,
     *   2. else the project-level default (`projects.categorization_config`),
     *   3. else the cat.yml template with {dom} filled in (first crawl ever).
     *
     * Returns null only when even the template is unavailable.
     */
    public function inheritedConfig(int $projectId, string $domain, ?int $excludeCrawlId = null): ?string
    {
        // The exclusion is appended rather than bound as a nullable param: PDO
        // rewrites named parameters client-side, and `::int` casts next to them
        // are a known source of parser confusion.
        $sql = "SELECT cc.config
                FROM categorization_config cc
                JOIN crawls c ON c.id = cc.crawl_id
                WHERE c.project_id = :pid
                  AND cc.config IS NOT NULL AND cc.config <> ''";
        $params = [':pid' => $projectId];
        if ($excludeCrawlId !== null) {
            $sql .= " AND cc.crawl_id <> :cid";
            $params[':cid'] = $excludeCrawlId;
        }
        $sql .= " ORDER BY c.id DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row && !empty($row->config)) {
            return (string) $row->config;
        }

        $stmt = $this->db->prepare("SELECT categorization_config FROM projects WHERE id = :pid");
        $stmt->execute([':pid' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row && !empty($row->categorization_config)) {
            return (string) $row->categorization_config;
        }

        return self::template($domain);
    }

    /** The default cat.yml template with {dom} substituted, or null if missing. */
    public static function template(string $domain): ?string
    {
        $path = dirname(__DIR__, 2) . '/cat.yml';
        if (!file_exists($path)) {
            return null;
        }
        $tpl = file_get_contents($path);
        return $tpl ? str_replace('{dom}', $domain, $tpl) : null;
    }

    /** Store a crawl's categorization snapshot. */
    public function setForCrawl(int $crawlId, string $yaml): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO categorization_config (crawl_id, config)
            VALUES (:crawl_id, :config)
            ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
        ");
        $stmt->execute([':crawl_id' => $crawlId, ':config' => $yaml, ':config2' => $yaml]);
    }

    /**
     * Seed a freshly created crawl with the project's current segmentation.
     * No-op when there is nothing to inherit and no template on disk.
     */
    public function seedNewCrawl(int $crawlId, int $projectId, string $domain): void
    {
        $yaml = $this->inheritedConfig($projectId, $domain, $crawlId);
        if ($yaml !== null && $yaml !== '') {
            $this->setForCrawl($crawlId, $yaml);
        }
    }
}
