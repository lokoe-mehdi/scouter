<?php

namespace App\Database;

use PDO;

/**
 * Repository pour les opérations CRUD sur les liens
 * 
 * Gère l'insertion et la récupération des liens entre pages.
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
class LinkRepository
{
    private PDO $db;
    private int $crawlId;

    public function __construct(int $crawlId)
    {
        $this->db = PostgresDatabase::getInstance()->getConnection();
        $this->crawlId = $crawlId;
    }

    /**
     * Convertit une valeur en string boolean pour PostgreSQL
     */
    private function toBool($value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_numeric($value)) return $value ? 'true' : 'false';
        if (is_string($value)) {
            if (in_array(strtolower($value), ['true', '1', 'yes', 't'])) return 'true';
            return 'false';
        }
        return 'false';
    }

    /**
     * Insère un lien
     */
    public function insert(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO links (crawl_id, src, target, anchor, type, external, nofollow)
            VALUES (:crawl_id, :src, :target, :anchor, :type, :external, :nofollow)
            ON CONFLICT (crawl_id, src, target) DO NOTHING
        ");
        
        $stmt->execute([
            ':crawl_id' => $this->crawlId,
            ':src' => $data['src'],
            ':target' => $data['target'],
            ':anchor' => $data['anchor'] ?? '',
            ':type' => $data['type'] ?? 'ahref',
            ':external' => $this->toBool($data['external'] ?? false),
            ':nofollow' => $this->toBool($data['nofollow'] ?? false)
        ]);
    }

    /**
     * Insère plusieurs liens en batch
     */
    public function insertBatch(array $links): void
    {
        if (empty($links)) return;
        
        $chunks = array_chunk($links, 100);
        
        foreach ($chunks as $chunk) {
            $values = [];
            $params = [];
            $i = 0;
            
            foreach ($chunk as $link) {
                $values[] = "(:crawl_id{$i}, :src{$i}, :target{$i}, :anchor{$i}, :type{$i}, :external{$i}, :nofollow{$i})";
                $params[":crawl_id{$i}"] = $this->crawlId;
                $params[":src{$i}"] = $link['src'];
                $params[":target{$i}"] = $link['target'];
                $params[":anchor{$i}"] = mb_substr($link['anchor'] ?? '', 0, 500);
                $params[":type{$i}"] = $link['type'] ?? 'ahref';
                $params[":external{$i}"] = $this->toBool($link['external'] ?? false);
                $params[":nofollow{$i}"] = $this->toBool($link['nofollow'] ?? false);
                $i++;
            }
            
            $sql = "INSERT INTO links (crawl_id, src, target, anchor, type, external, nofollow) VALUES " 
                 . implode(', ', $values) 
                 . " ON CONFLICT (crawl_id, src, target) DO NOTHING";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }
    }
}
