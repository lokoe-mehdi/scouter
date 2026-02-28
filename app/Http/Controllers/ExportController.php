<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use PDO;

/**
 * Controller pour les exports CSV
 * 
 * Gère l'export des pages et des liens au format CSV.
 * 
 * @package    Scouter
 * @subpackage Http\Controllers
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class ExportController extends Controller
{
    /**
     * Connexion PDO à la base de données
     * 
     * @var PDO
     */
    private PDO $db;

    /**
     * Constructeur
     * 
     * @param \App\Auth\Auth $auth Instance d'authentification
     */
    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Exporte les pages crawlées au format CSV
     * 
     * Applique les filtres et colonnes sélectionnées.
     * 
     * @param Request $request Requête HTTP (project, filters, search, columns)
     * 
     * @return void
     */
    public function csv(Request $request): void
    {
        $projectDir = $request->get('project');
        
        if (empty($projectDir)) {
            $this->error('Projet non spécifié');
        }
        
        if (is_numeric($projectDir)) {
            $this->auth->requireCrawlAccessById((int)$projectDir, false);
            $crawlRecord = CrawlDatabase::getCrawlById((int)$projectDir);
        } else {
            $this->auth->requireCrawlAccess($projectDir, false);
            $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        }
        
        if (!$crawlRecord) {
            Response::notFound('Projet non trouvé');
        }
        
        $crawlId = $crawlRecord->id;
        $filters = $request->get('filters') ? json_decode($request->get('filters'), true) : [];
        $search = $request->get('search', '');
        $selectedColumns = $request->get('columns') ? json_decode($request->get('columns'), true) : ['url'];
        
        // Charger les catégories
        $categoriesMap = [];
        $stmt = $this->db->prepare("SELECT id, cat FROM categories WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $crawlId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categoriesMap[$row['id']] = $row['cat'];
        }
        
        // Construction de la requête
        $whereConditions = ["c.crawl_id = " . intval($crawlId), "c.crawled = true"];
        $params = [];
        
        if (!empty($search)) {
            $whereConditions[] = "c.url LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($filters) && isset($filters['items'])) {
            $filterConditions = $this->buildFilterConditions($filters['items'], $params);
            if (!empty($filterConditions)) {
                $logic = $filters['logic'] ?? 'AND';
                $whereConditions[] = '(' . implode(' ' . $logic . ' ', $filterConditions) . ')';
            }
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $query = "SELECT c.* FROM pages c WHERE " . $whereClause . " ORDER BY c.pri DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        $filename = $crawlRecord->domain . '_export_' . date('Y-m-d') . '.csv';
        
        Response::csv($filename, function($output) use ($stmt, $selectedColumns, $categoriesMap) {
            fputcsv($output, $selectedColumns, ';');
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $line = [];
                foreach ($selectedColumns as $col) {
                    if ($col === 'category') {
                        $line[] = $categoriesMap[$row['cat_id']] ?? 'Non catégorisé';
                    } else {
                        $line[] = $row[$col] ?? '';
                    }
                }
                fputcsv($output, $line, ';');
            }
        });
    }

    /**
     * Exporte les liens entre pages au format CSV
     * 
     * Inclut source, target, anchor, type et nofollow.
     * 
     * @param Request $request Requête HTTP (project, columns)
     * 
     * @return void
     */
    public function linksCsv(Request $request): void
    {
        $projectDir = $request->get('project');
        
        if (empty($projectDir)) {
            $this->error('Projet non spécifié');
        }
        
        if (is_numeric($projectDir)) {
            $this->auth->requireCrawlAccessById((int)$projectDir, false);
            $crawlRecord = CrawlDatabase::getCrawlById((int)$projectDir);
        } else {
            $this->auth->requireCrawlAccess($projectDir, false);
            $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
        }
        
        if (!$crawlRecord) {
            Response::notFound('Projet non trouvé');
        }
        
        $crawlId = $crawlRecord->id;
        $selectedColumns = $request->get('columns') ? json_decode($request->get('columns'), true) : ['source_url', 'target_url'];
        
        $query = "
            SELECT 
                cs.url as source_url,
                ct.url as target_url,
                l.anchor,
                l.type,
                l.nofollow
            FROM links l
            JOIN pages cs ON l.src = cs.id AND cs.crawl_id = :crawl_id
            JOIN pages ct ON l.target = ct.id AND ct.crawl_id = :crawl_id2
            WHERE l.crawl_id = :crawl_id3
            ORDER BY cs.url
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':crawl_id' => $crawlId, ':crawl_id2' => $crawlId, ':crawl_id3' => $crawlId]);
        
        $filename = $crawlRecord->domain . '_links_' . date('Y-m-d') . '.csv';
        
        Response::csv($filename, function($output) use ($stmt, $selectedColumns) {
            fputcsv($output, $selectedColumns, ';');
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $line = [];
                foreach ($selectedColumns as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($output, $line, ';');
            }
        });
    }

    /**
     * Construit les conditions SQL à partir des filtres
     * 
     * Supporte les opérateurs: contains, not_contains, starts_with, ends_with,
     * is_empty, is_not_empty, =, !=, >, <, >=, <=
     * 
     * @param array<int, array> $items  Liste des filtres
     * @param array             $params Paramètres SQL (par référence)
     * 
     * @return array<int, string> Conditions SQL
     */
    private function buildFilterConditions(array $items, array &$params): array
    {
        static $counter = 0;
        $conditions = [];
        
        foreach ($items as $item) {
            if (isset($item['type']) && $item['type'] === 'group') {
                $subConditions = $this->buildFilterConditions($item['items'], $params);
                if (!empty($subConditions)) {
                    $conditions[] = '(' . implode(' ' . $item['logic'] . ' ', $subConditions) . ')';
                }
            } else {
                $field = $item['field'] ?? '';
                $operator = $item['operator'] ?? '=';
                $value = $item['value'] ?? '';
                
                if (empty($field)) continue;
                
                $counter++;
                $paramName = ':p' . $counter;
                
                switch ($operator) {
                    case 'contains':
                        $conditions[] = "c.$field LIKE $paramName";
                        $params[$paramName] = '%' . $value . '%';
                        break;
                    case 'not_contains':
                        $conditions[] = "c.$field NOT LIKE $paramName";
                        $params[$paramName] = '%' . $value . '%';
                        break;
                    case 'starts_with':
                        $conditions[] = "c.$field LIKE $paramName";
                        $params[$paramName] = $value . '%';
                        break;
                    case 'ends_with':
                        $conditions[] = "c.$field LIKE $paramName";
                        $params[$paramName] = '%' . $value;
                        break;
                    case 'is_empty':
                        $conditions[] = "(c.$field IS NULL OR c.$field = '')";
                        break;
                    case 'is_not_empty':
                        $conditions[] = "(c.$field IS NOT NULL AND c.$field != '')";
                        break;
                    case '>':
                    case '<':
                    case '>=':
                    case '<=':
                    case '=':
                    case '!=':
                        $conditions[] = "c.$field $operator $paramName";
                        $params[$paramName] = $value;
                        break;
                }
            }
        }
        
        return $conditions;
    }
}
