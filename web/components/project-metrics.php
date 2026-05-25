<?php
/**
 * Helpers de métriques pour les cartes projet de la homepage.
 * Tout est calculé à partir de la ligne `crawls` déjà chargée (aucune requête
 * ClickHouse au rendu) : score santé, donut SVG, sparkline SVG, deltas, durée.
 */

if (!function_exists('pcHealthScore')) {
    /**
     * Score de santé 0-100 d'un crawl, moyenne de 3 piliers disponibles sur la
     * ligne crawls : indexabilité, absence d'erreurs, unicité (non-dupliqué).
     * $stats = ['urls','crawled','compliant','duplicates','critical_errors']
     */
    function pcHealthScore(array $stats): int {
        $crawled   = max(0, (int)($stats['crawled'] ?? 0));
        $compliant = max(0, (int)($stats['compliant'] ?? 0));
        $errors    = max(0, (int)($stats['critical_errors'] ?? 0));
        $dups      = max(0, (int)($stats['duplicates'] ?? 0));
        if ($crawled <= 0) return 0;

        $indexability = min(1.0, $compliant / $crawled);                 // part indexable
        $errorFree    = max(0.0, 1.0 - $errors / $crawled);              // part sans 4xx/5xx
        $uniqueness   = $compliant > 0 ? max(0.0, 1.0 - $dups / max(1, $compliant)) : 1.0;

        $score = ($indexability + $errorFree + $uniqueness) / 3 * 100;
        return (int)round(max(0, min(100, $score)));
    }
}

if (!function_exists('chHealthScores')) {
    /**
     * Score de santé SEO 0-100 PAR crawl, calculé en direct dans ClickHouse —
     * 5 piliers équipondérés : (1) indexabilité, (2) on-page (title+h1 uniques),
     * (3) contenu non-thin (>500 mots), (4) distribution du PageRank interne vers
     * les pages conformes, (5) profondeur ≤ seuil géométrique (arbre en base 5).
     * UNE seule requête batchée pour tous les crawls passés. Retourne
     * [crawl_id => int]. Vide si ClickHouse est désactivé OU pour un crawl pas
     * encore migré (absent de CH) → l'appelant retombe alors sur pcHealthScore.
     * (Le seul calcul de score qui touche CH au rendu ; le reste reste pur-PHP.)
     */
    function chHealthScores(array $crawlIds): array {
        $crawlIds = array_values(array_unique(array_filter(array_map('intval', $crawlIds))));
        if (empty($crawlIds) || !\App\Database\ClickHouseDatabase::enabled()) return [];
        $idList = implode(',', $crawlIds);
        // pages est ReplacingMergeTree → on déduplique (LIMIT 1 BY crawl_id,id) avant
        // d'agréger ; title_status/h1_status/pri vivent dans page_metrics (dérivé).
        $sql = "
WITH per AS (
  SELECT crawl_id, countIf(compliant=1 AND is_html=1) AS idx
  FROM (SELECT crawl_id, id, compliant, is_html FROM pages WHERE crawl_id IN ($idList)
        ORDER BY date DESC LIMIT 1 BY crawl_id, id)
  GROUP BY crawl_id
),
thr AS (SELECT crawl_id, greatest(ceil(log((idx + 24) / 25.0) / log(5.0)), 1) AS max_depth FROM per)
SELECT p.crawl_id AS crawl_id,
  round((
      countIf(p.compliant=1 AND p.is_html=1)*100.0 / nullIf(countIf(p.crawled=1 AND p.is_html=1),0)
    + countIf(m.title_status='unique' AND m.h1_status='unique' AND p.compliant=1 AND p.is_html=1)*100.0 / nullIf(countIf(p.crawled=1 AND p.is_html=1),0)
    + countIf(p.compliant=1 AND p.is_html=1 AND p.word_count>500)*100.0 / nullIf(countIf(p.compliant=1 AND p.is_html=1),0)
    + sumIf(m.pri, p.compliant=1 AND p.is_html=1)*100.0 / nullIf(sum(m.pri),0)
    + countIf(p.compliant=1 AND p.is_html=1 AND p.depth <= t.max_depth)*100.0 / nullIf(countIf(p.compliant=1 AND p.is_html=1),0)
  ) / 5) AS score
FROM (SELECT crawl_id, id, compliant, is_html, crawled, word_count, depth FROM pages WHERE crawl_id IN ($idList)
      ORDER BY date DESC LIMIT 1 BY crawl_id, id) p
LEFT JOIN page_metrics m ON m.crawl_id = p.crawl_id AND m.id = p.id
JOIN thr t ON t.crawl_id = p.crawl_id
GROUP BY p.crawl_id, t.max_depth";
        $out = [];
        try {
            foreach (\App\Database\ClickHouseDatabase::getInstance()->select($sql) as $r) {
                if (($r['score'] ?? null) !== null && $r['score'] !== '') {
                    $out[(int)$r['crawl_id']] = (int)round(max(0, min(100, (float)$r['score'])));
                }
            }
        } catch (\Throwable $e) {
            error_log('chHealthScores CH query failed: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('pcScoreColor')) {
    function pcScoreColor(int $score): string {
        if ($score >= 75) return '#2ECC71';   // vert
        if ($score >= 50) return '#F39C12';   // orange
        return '#E74C3C';                      // rouge
    }
}

if (!function_exists('pcScoreClass')) {
    function pcScoreClass(int $score): string {
        if ($score >= 75) return 'good';
        if ($score >= 50) return 'mid';
        return 'bad';
    }
}

if (!function_exists('pcDonutSvg')) {
    /** Anneau SVG (donut) pour le score santé. r=15, circonférence ≈ 94.25. */
    function pcDonutSvg(int $score): string {
        $r = 15; $c = 2 * M_PI * $r;
        $off = $c * (1 - max(0, min(100, $score)) / 100);
        $color = pcScoreColor($score);
        return '<svg viewBox="0 0 40 40" aria-hidden="true">'
            . '<circle class="pc-health-track" cx="20" cy="20" r="' . $r . '"/>'
            . '<circle class="pc-health-arc" cx="20" cy="20" r="' . $r . '"'
            . ' stroke="' . $color . '" stroke-dasharray="' . round($c, 2) . '"'
            . ' stroke-dashoffset="' . round($off, 2) . '"/>'
            . '</svg>';
    }
}

if (!function_exists('pcSparklineSvg')) {
    /**
     * Sparkline SVG lissée (courbe de Bézier Catmull-Rom) à partir d'une série
     * (ancien→récent). Trait fin, remplissage très léger. Couleur douce.
     */
    function pcSparklineSvg(array $values, string $color = '#3DBE8B', ?array $domain = null): string {
        $values = array_values(array_filter($values, fn($v) => $v !== null));
        $n = count($values);
        if ($n === 0) return '<svg class="pc-spark" viewBox="0 0 120 36"></svg>';
        if ($n === 1) $values = [$values[0], $values[0]];
        $n = count($values);

        $w = 120; $h = 36; $pad = 4;
        // $domain = [min, max] force une échelle fixe (ex. [0,100] pour un score
        // /100) : la courbe reflète alors le NIVEAU réel de la métrique au lieu
        // d'être auto-zoomée sur son propre min/max — un score stable et haut rend
        // une ligne haute et ~plate, pas une fausse grosse vague. Sinon (null) :
        // auto-échelle, adaptée aux volumes (URLs, erreurs…).
        if ($domain !== null) {
            [$min, $max] = $domain;
        } else {
            $min = min($values); $max = max($values);
        }
        $range = ($max - $min) ?: 1;
        $stepX = ($w - 2 * $pad) / ($n - 1);

        // Points (fraction bornée à [0,1] pour rester dans le viewBox même si une
        // valeur déborde d'un domaine fixe).
        $P = [];
        foreach ($values as $i => $v) {
            $frac = max(0.0, min(1.0, ($v - $min) / $range));
            $x = $pad + $i * $stepX;
            $y = $h - $pad - $frac * ($h - 2 * $pad);
            $P[] = [$x, $y];
        }

        // Chemin lissé Catmull-Rom → Bézier cubique
        $d = 'M' . round($P[0][0], 1) . ',' . round($P[0][1], 1);
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $P[$i - 1] ?? $P[$i];
            $p1 = $P[$i];
            $p2 = $P[$i + 1];
            $p3 = $P[$i + 2] ?? $p2;
            $c1x = $p1[0] + ($p2[0] - $p0[0]) / 6;
            $c1y = $p1[1] + ($p2[1] - $p0[1]) / 6;
            $c2x = $p2[0] - ($p3[0] - $p1[0]) / 6;
            $c2y = $p2[1] - ($p3[1] - $p1[1]) / 6;
            $d .= 'C' . round($c1x, 1) . ',' . round($c1y, 1)
                . ' ' . round($c2x, 1) . ',' . round($c2y, 1)
                . ' ' . round($p2[0], 1) . ',' . round($p2[1], 1);
        }
        $area = $d . 'L' . round($w - $pad, 1) . ',' . ($h - $pad) . 'L' . $pad . ',' . ($h - $pad) . 'Z';

        return '<svg class="pc-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<path class="pc-spark-fill" d="' . $area . '" fill="' . $color . '"/>'
            . '<path class="pc-spark-line" d="' . $d . '" stroke="' . $color . '"/>'
            . '</svg>';
    }
}

if (!function_exists('pcDelta')) {
    /**
     * Span de variation en %. $goodWhenUp=false pour les métriques où baisser
     * est positif (erreurs critiques).
     */
    function pcDelta($current, $previous, bool $goodWhenUp = true): string {
        $current = (int)$current; $previous = (int)$previous;
        if ($previous <= 0) {
            if ($current === $previous) return '';
            // pas de base de comparaison fiable
            return '';
        }
        $pct = ($current - $previous) / $previous * 100;
        if (abs($pct) < 0.5) {
            return '<span class="pc-delta flat">±0%</span>';
        }
        $up = $pct > 0;
        $good = $goodWhenUp ? $up : !$up;
        $cls = $good ? 'up' : 'down';
        $sign = $up ? '+' : '';
        return '<span class="pc-delta ' . $cls . '">' . $sign . round($pct) . '%</span>';
    }
}

if (!function_exists('pcDuration')) {
    /** Durée HH:MM:SS entre deux timestamps SQL, ou '—'. */
    function pcDuration($startedAt, $finishedAt): string {
        if (empty($startedAt) || empty($finishedAt)) return '—';
        $s = strtotime($startedAt); $f = strtotime($finishedAt);
        if (!$s || !$f || $f < $s) return '—';
        $d = $f - $s;
        return sprintf('%02d:%02d:%02d', intdiv($d, 3600), intdiv($d % 3600, 60), $d % 60);
    }
}
