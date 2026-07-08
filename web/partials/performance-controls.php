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
// The window is relative to the crawl date and never past it → cap the picker at
// maxAllowed = min(crawl date, last GSC day).
$perfMax    = $perf['maxAllowed'] ?: ($perf['maxDate'] ?: $perfTo);
$perfCrawl  = $perf['crawlDate'] ?? '';
$perfProp   = $perf['connector']->site_url ?? '';
$perfLocale = class_exists('I18n') ? I18n::getInstance()->getLocale() : 'en-US';
// Cookie value persisted client-side so the window survives page navigation.
$perfCookie = ($perfPreset === 'custom') ? ('custom|' . $perfFrom . '|' . $perfTo) : $perfPreset;

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
.perf-dp-menu-head{font-size:.72rem;color:var(--text-secondary);font-weight:600;padding:.1rem .2rem .5rem;border-bottom:1px solid var(--border-color);margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem;}
.perf-dp-custom-title{font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);font-weight:600;margin-bottom:.5rem;}
.perf-dp-apply{padding:.45rem .9rem;background:var(--primary-color);color:#fff;border:none;border-radius:8px;font-weight:600;font-size:.85rem;cursor:pointer;}
.perf-dp-apply:hover{filter:brightness(1.05);}
/* custom range calendar */
.perf-cal{width:252px;}
.perf-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.45rem;}
.perf-cal-month{font-weight:700;font-size:.85rem;color:var(--text-primary);text-transform:capitalize;}
.perf-cal-nav{border:1px solid var(--border-color);background:#fff;border-radius:6px;width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-secondary);}
.perf-cal-nav:hover{border-color:var(--primary-color);color:var(--primary-color);}
.perf-cal-nav .material-symbols-outlined{font-size:1.05rem;}
.perf-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
.perf-cal-wd{text-align:center;font-size:.64rem;color:var(--text-secondary);font-weight:700;padding:.15rem 0;text-transform:uppercase;}
.perf-cal-day{border:none;background:none;aspect-ratio:1;border-radius:6px;cursor:pointer;font-size:.78rem;color:var(--text-primary);display:flex;align-items:center;justify-content:center;font-family:inherit;padding:0;}
.perf-cal-day.empty{visibility:hidden;}
.perf-cal-day:not(.disabled):not(.sel):hover{background:#EAF7F6;}
.perf-cal-day.inrange{background:#E8F8F5;border-radius:0;}
.perf-cal-day.sel{background:var(--primary-color);color:#fff;font-weight:700;}
.perf-cal-day.disabled{color:#C9D2D9;cursor:default;}
.perf-cal-foot{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--border-color);}
.perf-cal-range{font-size:.72rem;color:var(--text-secondary);font-weight:600;}
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
        <span class="material-symbols-outlined">event_available</span>
        <?php if ($perfProp): ?><code><?= htmlspecialchars($perfProp) ?></code><?php endif; ?>
        <span><?= __('performance.window_relative', ['date' => htmlspecialchars($perfCrawl ?: $perfMax)]) ?></span>
    </div>
    <div class="perf-daterange" id="perfDaterange">
        <button type="button" class="perf-daterange-btn" onclick="perfToggleDateMenu(event)">
            <span class="material-symbols-outlined">calendar_month</span>
            <span><?= htmlspecialchars($perf['label']) ?></span>
            <span class="material-symbols-outlined perf-caret">expand_more</span>
        </button>
        <div class="perf-datemenu" id="perfDateMenu">
            <div class="perf-dp-menu-head"><?= __('performance.window_before_crawl', ['date' => htmlspecialchars($perfCrawl ?: $perfMax)]) ?></div>
            <div class="perf-dp-presets">
                <?php foreach (\App\Gsc\PerformanceReport::PRESETS as $months): ?>
                    <a class="perf-dp-preset <?= ($perfPreset === (string) $months) ? 'active' : '' ?>"
                       href="<?= htmlspecialchars($perfUrl((string) $months)) ?>"><?= __('performance.range_' . $months) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="perf-dp-sep"></div>
            <div class="perf-dp-custom-title"><?= __('performance.custom_range') ?></div>
            <!-- Styled range calendar (no native input), bounded [first GSC day … crawl date] -->
            <div class="perf-cal" id="perfCal">
                <div class="perf-cal-head">
                    <button type="button" class="perf-cal-nav" id="perfCalPrev"><span class="material-symbols-outlined">chevron_left</span></button>
                    <span class="perf-cal-month" id="perfCalMonth"></span>
                    <button type="button" class="perf-cal-nav" id="perfCalNext"><span class="material-symbols-outlined">chevron_right</span></button>
                </div>
                <div class="perf-cal-grid" id="perfCalGrid"></div>
                <div class="perf-cal-foot">
                    <span class="perf-cal-range" id="perfCalRange">—</span>
                    <button type="button" class="perf-dp-apply" id="perfCalApply"><?= __('performance.apply') ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Persist the chosen window so it survives page navigation (the sidebar links
// don't carry the params). PerformanceReport reads this `perf_win` cookie when
// no ?pf is present. Runs on every render → always reflects the current window.
document.cookie = 'perf_win=' + encodeURIComponent(<?= json_encode($perfCookie) ?>) + ';path=/;max-age=' + (86400 * 180) + ';SameSite=Lax';

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

// Custom-range calendar (styled, bounded [first GSC day … crawl date]). Re-init
// each render since the menu DOM is rebuilt on every (htmx) navigation.
(function(){
    var grid = document.getElementById('perfCalGrid');
    var menu = document.getElementById('perfDateMenu');
    if (!grid || !menu) return;
    // Clicks inside the menu must not bubble to the outside-close handler — a day
    // click re-renders the grid so its target detaches and would wrongly close it.
    menu.addEventListener('click', function(e){ e.stopPropagation(); });

    var cfg = <?= json_encode(['min' => $perfMin, 'max' => $perfMax, 'from' => $perfFrom, 'to' => $perfTo, 'locale' => $perfLocale]) ?>;
    function ymd(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
    function parse(s){ return new Date(s+'T00:00:00'); }
    var fMonth = new Intl.DateTimeFormat(cfg.locale, {month:'long', year:'numeric'});
    var WD = []; for (var i=0;i<7;i++){ WD.push(new Intl.DateTimeFormat(cfg.locale,{weekday:'narrow'}).format(new Date(2024,0,1+i))); } // 2024-01-01 = Monday
    var st = { start: cfg.from || null, end: cfg.to || null, view: parse(cfg.to || cfg.max) };
    st.view = new Date(st.view.getFullYear(), st.view.getMonth(), 1);

    function render(){
        var y=st.view.getFullYear(), m=st.view.getMonth();
        var mn = fMonth.format(new Date(y,m,1));
        document.getElementById('perfCalMonth').textContent = mn.charAt(0).toUpperCase()+mn.slice(1);
        var startWd=(new Date(y,m,1).getDay()+6)%7, days=new Date(y,m+1,0).getDate();
        var html = WD.map(function(d){return '<span class="perf-cal-wd">'+d+'</span>';}).join('');
        for (var i=0;i<startWd;i++) html+='<span class="perf-cal-day empty"></span>';
        for (var day=1; day<=days; day++){
            var ds=y+'-'+String(m+1).padStart(2,'0')+'-'+String(day).padStart(2,'0');
            var off = (ds<cfg.min || ds>cfg.max);
            var cls='perf-cal-day';
            if (off) cls+=' disabled';
            else if (ds===st.start || ds===st.end) cls+=' sel';
            else if (st.start && st.end && ds>st.start && ds<st.end) cls+=' inrange';
            html+='<button type="button" class="'+cls+'" data-d="'+ds+'"'+(off?' disabled':'')+'>'+day+'</button>';
        }
        grid.innerHTML=html;
        document.getElementById('perfCalRange').textContent = st.start ? (st.start+(st.end?(' → '+st.end):' → …')) : '—';
    }
    function pick(ds){
        if (!st.start || (st.start && st.end)) { st.start=ds; st.end=null; }
        else if (ds>=st.start) { st.end=ds; }
        else { st.end=st.start; st.start=ds; }
        render();
    }
    grid.addEventListener('click', function(e){ var b=e.target.closest('.perf-cal-day[data-d]'); if (b && !b.disabled) pick(b.getAttribute('data-d')); });
    document.getElementById('perfCalPrev').addEventListener('click', function(){ st.view=new Date(st.view.getFullYear(),st.view.getMonth()-1,1); render(); });
    document.getElementById('perfCalNext').addEventListener('click', function(){ st.view=new Date(st.view.getFullYear(),st.view.getMonth()+1,1); render(); });
    document.getElementById('perfCalApply').addEventListener('click', function(){
        if (!st.start) return;
        var from=st.start, to=st.end||st.start;
        var u=new URL(window.location);
        u.searchParams.set('pf','custom'); u.searchParams.set('pfrom',from); u.searchParams.set('pto',to);
        window.location = u.toString();
    });
    render();
})();
</script>
