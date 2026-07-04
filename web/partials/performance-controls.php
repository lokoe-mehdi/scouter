<?php
/**
 * Shared date-range control for the dashboard "Performance" sub-report.
 *
 * Server-side (no AJAX): presets are plain links and the custom range is a GET
 * form — both re-render the current page with the new window. Expects $perf (the
 * PerformanceReport::context array), $crawlId and $page in scope.
 *
 * Mirrors the Search Analytics date picker's affordances (presets + calendar
 * range) but WITHOUT the period-comparison — here the "comparison" is always
 * crawl vs. Search Console, not period vs. period.
 */

$perfPage   = $page;
$perfPreset = $perf['preset'];
$perfFrom   = $perf['from'];
$perfTo     = $perf['to'];
$perfMin    = $perf['minDate'] ?: $perfFrom;
$perfMax    = $perf['maxDate'] ?: $perfTo;
$perfProp   = $perf['connector']->site_url ?? '';

/** Build a preset link URL (helper is namespaced). */
$perfUrl = fn(string $pf) => \App\Gsc\PerformanceReport::url((int) $crawlId, $perfPage, $pf);

static $perfCssAdded = false;
?>
<?php if (!$perfCssAdded): ?>
<style>
.perf-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;}
.perf-toolbar-info{display:flex;align-items:center;gap:.5rem;color:var(--text-secondary);font-size:.875rem;flex-wrap:wrap;}
.perf-toolbar-info code{background:var(--background);padding:.15rem .5rem;border-radius:6px;font-size:.8rem;color:var(--text-primary);}
.perf-toolbar-info .material-symbols-outlined{font-size:1.1rem;}
.perf-daterange{position:relative;}
.perf-daterange-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem .9rem;background:var(--card-bg);border:1px solid var(--border-color);border-radius:10px;color:var(--text-primary);font-size:.9rem;font-weight:600;cursor:pointer;transition:border-color .2s,box-shadow .2s;}
.perf-daterange-btn:hover{border-color:var(--primary-color);box-shadow:0 2px 8px rgba(0,0,0,.06);}
.perf-daterange-btn .perf-caret{font-size:1.1rem;color:var(--text-secondary);}
.perf-datemenu{position:absolute;top:calc(100% + .5rem);right:0;z-index:60;min-width:280px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.15);padding:.75rem;display:none;}
.perf-datemenu.open{display:block;}
.perf-dp-presets{display:flex;flex-direction:column;gap:.15rem;margin-bottom:.5rem;}
.perf-dp-preset{display:block;text-align:left;padding:.5rem .65rem;border-radius:8px;color:var(--text-primary);text-decoration:none;font-size:.875rem;transition:background .15s;}
.perf-dp-preset:hover{background:var(--background);}
.perf-dp-preset.active{background:var(--primary-color);color:#fff;font-weight:600;}
.perf-dp-sep{height:1px;background:var(--border-color);margin:.5rem 0;}
.perf-dp-custom-title{font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);font-weight:600;margin-bottom:.5rem;}
.perf-dp-custom{display:flex;flex-direction:column;gap:.5rem;}
.perf-dp-dates{display:flex;gap:.5rem;}
.perf-dp-dates label{display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;color:var(--text-secondary);flex:1;}
.perf-dp-dates input{padding:.4rem .5rem;border:1px solid var(--border-color);border-radius:8px;background:var(--background);color:var(--text-primary);font-size:.85rem;}
.perf-dp-apply{align-self:flex-end;padding:.45rem .9rem;background:var(--primary-color);color:#fff;border:none;border-radius:8px;font-weight:600;font-size:.85rem;cursor:pointer;}
.perf-dp-apply:hover{filter:brightness(1.05);}
.perf-unavailable{max-width:640px;margin:3rem auto;text-align:center;background:var(--card-bg);border:1px solid var(--border-color);border-radius:16px;padding:3rem 2rem;}
.perf-unavailable-icon .material-symbols-outlined{font-size:3.5rem;color:var(--primary-color);}
.perf-unavailable h2{margin:1rem 0 .5rem;color:var(--text-primary);}
.perf-unavailable p{color:var(--text-secondary);margin-bottom:1.5rem;}
.perf-unavailable-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;background:var(--primary-color);color:#fff;border-radius:10px;text-decoration:none;font-weight:600;}
.perf-note{display:flex;align-items:flex-start;gap:.6rem;background:var(--background);border:1px solid var(--border-color);border-left:4px solid var(--primary-color);border-radius:10px;padding:.85rem 1rem;color:var(--text-secondary);font-size:.85rem;margin-bottom:1.5rem;}
.perf-note .material-symbols-outlined{font-size:1.2rem;color:var(--primary-color);}
.perf-section-title{margin:.5rem 0 -.25rem;font-size:1.15rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:.5rem;}
</style>
<?php $perfCssAdded = true; endif; ?>

<div class="perf-toolbar">
    <div class="perf-toolbar-info">
        <span class="material-symbols-outlined">insights</span>
        <?php if ($perfProp): ?><code><?= htmlspecialchars($perfProp) ?></code><?php endif; ?>
        <span><?= __('performance.data_until', ['date' => htmlspecialchars($perfMax)]) ?></span>
    </div>
    <div class="perf-daterange" id="perfDaterange">
        <button type="button" class="perf-daterange-btn" onclick="perfToggleDateMenu(event)">
            <span class="material-symbols-outlined">calendar_month</span>
            <span><?= htmlspecialchars($perf['label']) ?></span>
            <span class="material-symbols-outlined perf-caret">expand_more</span>
        </button>
        <div class="perf-datemenu" id="perfDateMenu">
            <div class="perf-dp-presets">
                <?php foreach (\App\Gsc\PerformanceReport::PRESETS as $days): ?>
                    <a class="perf-dp-preset <?= ($perfPreset === (string) $days) ? 'active' : '' ?>"
                       href="<?= htmlspecialchars($perfUrl((string) $days)) ?>"><?= __('performance.range_' . $days) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="perf-dp-sep"></div>
            <div class="perf-dp-custom-title"><?= __('performance.custom_range') ?></div>
            <form class="perf-dp-custom" method="get" action="">
                <input type="hidden" name="crawl" value="<?= (int) $crawlId ?>">
                <input type="hidden" name="page" value="<?= htmlspecialchars($perfPage) ?>">
                <?php if (!empty($_GET['project'])): ?>
                    <input type="hidden" name="project" value="<?= htmlspecialchars((string) $_GET['project']) ?>">
                <?php endif; ?>
                <input type="hidden" name="pf" value="custom">
                <div class="perf-dp-dates">
                    <label><?= __('performance.from') ?>
                        <input type="date" name="pfrom" value="<?= htmlspecialchars($perfFrom) ?>"
                               min="<?= htmlspecialchars($perfMin) ?>" max="<?= htmlspecialchars($perfMax) ?>">
                    </label>
                    <label><?= __('performance.to') ?>
                        <input type="date" name="pto" value="<?= htmlspecialchars($perfTo) ?>"
                               min="<?= htmlspecialchars($perfMin) ?>" max="<?= htmlspecialchars($perfMax) ?>">
                    </label>
                </div>
                <button type="submit" class="perf-dp-apply"><?= __('performance.apply') ?></button>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle the date menu (registered once; safe across htmx swaps).
if (typeof window.perfToggleDateMenu === 'undefined') {
    window.perfToggleDateMenu = function(e){
        e.stopPropagation();
        var m = document.getElementById('perfDateMenu');
        if (m) m.classList.toggle('open');
    };
    document.addEventListener('click', function(e){
        var dr = document.getElementById('perfDaterange');
        var m = document.getElementById('perfDateMenu');
        if (m && dr && !dr.contains(e.target)) m.classList.remove('open');
    });
}
</script>
