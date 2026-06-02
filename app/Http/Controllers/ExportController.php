<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Export\ExportService;
use App\Storage\Storage;
use App\Storage\S3Storage;
use PDO;

/**
 * Exports CSV asynchrones + centre de téléchargements.
 *
 * Un export (SQL / URL / Link / Redirect explorer) ne se télécharge plus
 * directement : il crée un job qui génère le CSV côté serveur et l'envoie sur le
 * blob store (S3/local). L'icône « téléchargements » du header (downloads.js)
 * poll `/api/exports`, puis le lien `/api/exports/{id}/download` redirige vers
 * une URL S3 présignée (24h) ou streame le fichier en stockage local.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class ExportController extends Controller
{
    private const TYPES = ['urls', 'links', 'redirects', 'sql'];

    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * POST /api/exports — crée un export asynchrone et enfile son job.
     * Body: type (urls|links|redirects|sql), project, + params selon le type.
     */
    public function create(Request $request): void
    {
        $type = (string)$request->get('type', '');
        if (!in_array($type, self::TYPES, true)) {
            $this->error('Type d\'export invalide');
        }

        $crawl = $this->resolveCrawl($request);

        // Params spécifiques au type (stockés en JSON, rejoués par le worker).
        $params = [];
        switch ($type) {
            case 'urls':
                $params = [
                    'filters'      => $request->get('filters', ''),
                    'search'       => $request->get('search', ''),
                    'columns'      => $request->get('columns', ''),
                    'report_where' => $request->get('report_where', ''),
                ];
                break;
            case 'links':
                $params = ['columns' => $request->get('columns', '')];
                break;
            case 'redirects':
                $params = [];
                break;
            case 'sql':
                $sql = trim((string)$request->get('sql', ''));
                if ($sql === '') {
                    $this->error('Requête SQL manquante');
                }
                $params = ['sql' => $sql];
                break;
        }

        $export = (new ExportService())->create((int)$this->userId, $crawl, $type, $params);

        $this->success([
            'export_id' => (int)$export['id'],
            'status'    => $export['status'],
            'label'     => $export['label'],
        ]);
    }

    /**
     * GET /api/exports — liste des exports de l'utilisateur (récents/non périmés)
     * + nombre de téléchargements prêts non encore vus (pour la pastille).
     */
    public function index(Request $request): void
    {
        if (!$this->userId) {
            $this->error('Not authenticated', 401);
        }

        $stmt = $this->db->prepare("
            SELECT id, crawl_id, type, label, status, filename, row_count, size_bytes,
                   error, seen_at, created_at, ready_at, expires_at
            FROM exports
            WHERE user_id = :uid AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
            LIMIT 30
        ");
        $stmt->execute([':uid' => $this->userId]);
        $exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unseen = 0;
        foreach ($exports as &$e) {
            $e['id'] = (int)$e['id'];
            $e['row_count'] = $e['row_count'] !== null ? (int)$e['row_count'] : null;
            $e['size_bytes'] = $e['size_bytes'] !== null ? (int)$e['size_bytes'] : null;
            if ($e['status'] === 'ready' && $e['seen_at'] === null) {
                $unseen++;
            }
        }
        unset($e);

        $this->success(['exports' => $exports, 'unseen' => $unseen]);
    }

    /**
     * POST /api/exports/seen — marque tous les exports prêts comme vus (éteint
     * la pastille), comme /api/notifications/read pour la cloche.
     */
    public function seen(Request $request): void
    {
        if (!$this->userId) {
            $this->error('Not authenticated', 401);
        }
        $stmt = $this->db->prepare("UPDATE exports SET seen_at = NOW() WHERE user_id = :uid AND seen_at IS NULL");
        $stmt->execute([':uid' => $this->userId]);
        $this->success(['ok' => true]);
    }

    /**
     * GET /api/exports/{id}/download — vérifie propriété + fenêtre 24h, puis
     * redirige vers une URL S3 présignée (téléchargement direct depuis S3) ou
     * streame le fichier depuis le stockage local.
     */
    public function download(Request $request): void
    {
        $id = (int)$request->param('id');
        if ($id <= 0) {
            Response::notFound('Export introuvable');
        }

        $stmt = $this->db->prepare("SELECT * FROM exports WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $export = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$export || (int)$export['user_id'] !== (int)$this->userId) {
            Response::notFound('Export introuvable');
        }
        if ($export['status'] !== 'ready' || !$export['object_key']) {
            Response::error('Export pas encore prêt', 409);
        }

        $expiresTs = $export['expires_at'] ? strtotime($export['expires_at'] . ' UTC') : 0;
        if ($expiresTs && $expiresTs <= time()) {
            Response::error('Ce lien de téléchargement a expiré (24h).', 410);
        }

        $key = $export['object_key'];
        $filename = $export['filename'] ?: ('export_' . $id . '.csv');
        $store = Storage::instance();

        if ($store instanceof S3Storage) {
            // Direct-from-S3 download via a fresh presigned URL valid for the
            // export's remaining lifetime, named as an attachment.
            $remaining = $expiresTs ? max(60, $expiresTs - time()) : 3600;
            $url = $store->presignedGetUrl($key, $remaining, [
                'response-content-disposition' => 'attachment; filename="' . $filename . '"',
                'response-content-type'        => 'text/csv; charset=utf-8',
            ]);
            if ($url) {
                header('Location: ' . $url, true, 302);
                exit;
            }
        }

        // Local backend (or presign unavailable): stream the bytes through the app.
        $data = $store->get($key);
        if ($data === null) {
            Response::notFound('Fichier d\'export introuvable');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $data;
        exit;
    }

    /**
     * Résout le crawl ciblé (par id numérique ou par chemin) en vérifiant l'accès
     * en lecture. Retourne l'enregistrement de crawl.
     */
    private function resolveCrawl(Request $request): object
    {
        $project = $request->get('project');
        if (empty($project)) {
            $this->error('Projet non spécifié');
        }
        if (is_numeric($project)) {
            $this->auth->requireCrawlAccessById((int)$project, false);
            $crawl = CrawlDatabase::getCrawlById((int)$project);
        } else {
            $this->auth->requireCrawlAccess($project, false);
            $crawl = CrawlDatabase::getCrawlByPath($project);
        }
        if (!$crawl) {
            Response::notFound('Projet non trouvé');
        }
        return $crawl;
    }
}
