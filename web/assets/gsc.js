/* Search Analytics (GSC) — client logic for search-analytics.php.
 * Reuses the shared FilterBar (URL Explorer engine) + the async export system.
 * Custom calendar date-range picker, dashboard-style table toolbars (top+bottom).
 */
(function () {
  'use strict';
  if (!window.GSC || !window.GSC.connected) return;

  // i18n: the stored anon sentinel is the French '(anonyme)' (a DATA value from
  // the ingester) — detect on it, but DISPLAY the localized label.
  var ANON = '(anonyme)';
  var LOCALE = (window.GSC && window.GSC.locale) || 'en-US';
  var t = (typeof window.__ === 'function') ? window.__ : function (k) { return k; };
  var PP_OPTIONS = [10, 25, 50, 100, 250, 500];

  // Localized month/weekday names via Intl (no hardcoded tables).
  var _fMonthLong = new Intl.DateTimeFormat(LOCALE, { month: 'long' });
  var _fMonthShort = new Intl.DateTimeFormat(LOCALE, { month: 'short' });
  var _fDate = new Intl.DateTimeFormat(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
  var WD = (function () { var r = []; for (var i = 0; i < 7; i++) { r.push(new Intl.DateTimeFormat(LOCALE, { weekday: 'narrow' }).format(new Date(2024, 0, 1 + i))); } return r; })(); // 2024-01-01 = Monday

  // Category name → colour (from the project's categorization rules) for badges.
  var CAT_COLORS = {};
  ((window.GSC && window.GSC.categories) || []).forEach(function (c) { CAT_COLORS[c.cat] = c.color; });
  var HAS_CATS = Object.keys(CAT_COLORS).length > 0;
  function textColorFor(hex) {
    var c = String(hex || '').replace('#', ''); if (c.length === 3) c = c[0] + c[0] + c[1] + c[1] + c[2] + c[2];
    var r = parseInt(c.substr(0, 2), 16), g = parseInt(c.substr(2, 2), 16), b = parseInt(c.substr(4, 2), 16);
    return ((0.299 * r + 0.587 * g + 0.114 * b) / 255) > 0.6 ? '#2C3E50' : '#fff';
  }
  function catBadge(name) {
    var uncat = !name || name === '';
    var n = uncat ? t('common.uncategorized') : name;
    // Uncategorized → a very light grey (dark text, readable on the light table).
    var color = uncat ? '#EBEEF2' : (CAT_COLORS[name] || '#EBEEF2');
    return '<span class="gsc-cat-badge" style="background:' + color + ';color:' + textColorFor(color) + '">' + esc(n) + '</span>';
  }

  // Custom-events accent colour — violet, deliberately outside the metric
  // palette (clicks #4ECDC4 / impressions #3498DB / ctr #2ECC71 / position
  // #F39C12) so event lines & flags never blend into a series. Mirrors the
  // #8E44AD used in gsc.css for the flags/panel.
  var EVENT_COLOR = '#8E44AD';

  // Chart metrics — toggled by clicking the KPI cards (GSC-style).
  var METRICS = {
    clicks:      { label: t('gsc.metric_clicks'),      color: '#4ECDC4', type: 'area' },
    impressions: { label: t('gsc.metric_impressions'), color: '#3498DB', type: 'line' },
    ctr:         { label: t('gsc.metric_ctr'),         color: '#2ECC71', type: 'line', pct: true },
    position:    { label: t('gsc.metric_position'),    color: '#F39C12', type: 'line', reversed: true }
  };

  var state = {
    projectId: window.GSC.projectId,
    mode: 'keywords', from: null, to: null, includeAnon: true, filters: [],
    sort: 'clicks', dir: 'desc', page: 1, perPage: 10, total: 0,
    gran: 'day', lastSeries: [],
    metrics: { clicks: true, impressions: true, ctr: false, position: false },
    compare: 'none', compareSeries: [], curKpis: null, dateBaseLabel: t('gsc.range_28d'),
    events: []
  };

  // ---- helpers -------------------------------------------------------------
  function $(id) { return document.getElementById(id); }
  function fmtInt(n) { return (Number(n) || 0).toLocaleString(LOCALE); }
  function fmtPct(x) { return (Number(x) || 0 ? (Number(x) * 100) : 0).toLocaleString(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %'; }
  function fmtPos(x) { return (Number(x) || 0) > 0 ? Number(x).toLocaleString(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—'; }
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function parseYmd(s) { return new Date(s + 'T00:00:00'); }
  function fmtFr(s) { return _fDate.format(parseYmd(s)); }
  function noop() {}
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function postJson(url, body) {
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }
  function payload() {
    var p = { project: state.projectId, mode: state.mode, from: state.from, to: state.to, filters: state.filters, include_anon: state.includeAnon, sort: state.sort, dir: state.dir, page: state.page, per_page: state.perPage, compare: state.compare };
    var cr = compareRange();
    if (cr) { p.cfrom = cr.cfrom; p.cto = cr.cto; }
    return p;
  }

  // ---- data load -----------------------------------------------------------
  function reload() {
    postJson('/api/gsc/query', payload()).then(function (res) { renderTableAndKpis(res); state.curKpis = res && res.kpis; updateKpiDeltas(); }).catch(noop);
    loadSeries();
  }
  function reloadTableOnly() { postJson('/api/gsc/query', payload()).then(renderTableAndKpis).catch(noop); }

  function loadSeries() {
    postJson('/api/gsc/timeseries', payload()).then(function (res) {
      state.lastSeries = (res && res.series) || [];
      var cr = compareRange();
      if (cr) {
        postJson('/api/gsc/timeseries', comparePayload(cr)).then(function (res2) {
          state.compareSeries = (res2 && res2.series) || [];
          renderChart(); updateKpiDeltas();
        }).catch(function () { state.compareSeries = []; renderChart(); });
      } else {
        state.compareSeries = [];
        renderChart(); updateKpiDeltas();
      }
    }).catch(noop);
  }

  // The comparison date range: previous period (same length) or year-over-year.
  function compareRange() {
    if (state.compare === 'none' || !state.from || !state.to) return null;
    var f = parseYmd(state.from), t = parseYmd(state.to);
    if (state.compare === 'year') {
      return { cfrom: ymd(new Date(f.getFullYear() - 1, f.getMonth(), f.getDate())), cto: ymd(new Date(t.getFullYear() - 1, t.getMonth(), t.getDate())) };
    }
    var len = Math.round((t - f) / 86400000) + 1;
    var pt = new Date(f); pt.setDate(pt.getDate() - 1);
    var pf = new Date(pt); pf.setDate(pf.getDate() - (len - 1));
    return { cfrom: ymd(pf), cto: ymd(pt) };
  }
  function comparePayload(cr) { var p = payload(); p.from = cr.cfrom; p.to = cr.cto; return p; }

  // Whole-range totals from a daily series (same weighting as the KPIs).
  function totals(series) {
    var c = 0, im = 0, pw = 0, pwi = 0;
    series.forEach(function (r) { var i = +r.impressions || 0, p = +r.position || 0; c += +r.clicks || 0; im += i; if (p > 0 && i > 0) { pw += p * i; pwi += i; } });
    return { clicks: c, impressions: im, ctr: im ? c / im : 0, position: pwi ? pw / pwi : 0 };
  }

  function updateKpiDeltas() {
    var comp = (state.compare !== 'none' && state.compareSeries.length) ? totals(state.compareSeries) : null;
    Object.keys(METRICS).forEach(function (m) {
      var el = document.querySelector('.gsc-kpi[data-kpi="' + m + '"] .gsc-kpi-delta');
      if (!el) return;
      if (!comp || !state.curKpis) { el.style.display = 'none'; return; }
      var cur = Number(state.curKpis[m]) || 0, prev = Number(comp[m]) || 0;
      el.style.display = '';
      el.innerHTML = deltaHtml(m, cur, prev);
    });
  }
  function deltaHtml(m, cur, prev) {
    if (cur === prev) return '<span class="gsc-delta gsc-delta--flat">–</span>';
    var better = (m === 'position') ? (cur < prev) : (cur > prev);
    var cls = better ? 'up' : 'down';
    var arrow = better ? '▲' : '▼';
    var val;
    if (m === 'position') { var d = cur - prev; val = (d >= 0 ? '+' : '') + d.toFixed(1); }
    else if (m === 'ctr') { var dp = (cur - prev) * 100; val = (dp >= 0 ? '+' : '') + dp.toFixed(2) + ' ' + t('gsc.pt'); }
    else { var pc = prev !== 0 ? ((cur - prev) / prev) * 100 : 100; val = (pc >= 0 ? '+' : '') + pc.toFixed(0) + ' %'; }
    return '<span class="gsc-delta gsc-delta--' + cls + '">' + arrow + ' ' + val + '</span>';
  }

  // ---- KPIs + table --------------------------------------------------------
  function renderTableAndKpis(res) {
    if (!res) return;
    var k = res.kpis || {};
    setKpi('clicks', fmtInt(k.clicks)); setKpi('impressions', fmtInt(k.impressions));
    setKpi('ctr', fmtPct(k.ctr)); setKpi('position', fmtPos(k.position));
    state.total = res.total || 0;
    state.comparing = !!res.comparing;
    state.lastRows = res.rows || [];
    renderTable(state.lastRows);
    renderToolbars();
  }
  function setKpi(name, val) { var el = document.querySelector('.gsc-kpi[data-kpi="' + name + '"] .gsc-kpi-val'); if (el) el.textContent = val; }

  // Metric columns follow the KPI-card toggles (hidden metric = hidden column).
  function columns() {
    var ALL = [
      { key: 'clicks', label: t('gsc.metric_clicks'), num: true }, { key: 'impressions', label: t('gsc.metric_impressions'), num: true },
      { key: 'ctr', label: t('gsc.metric_ctr'), num: true }, { key: 'position', label: t('gsc.metric_position'), num: true }
    ];
    var metrics = ALL.filter(function (m) { return state.metrics[m.key]; });
    if (!metrics.length) metrics = [ALL[0]];
    // First column = coloured category badge in the URL views (if rules exist).
    var cat = (HAS_CATS && state.mode !== 'keywords') ? [{ key: 'category', label: t('url_explorer.field_category'), catcol: true }] : [];
    if (state.mode === 'urls') return cat.concat([{ key: 'page', label: t('gsc.col_url'), dim: true, url: true }]).concat(metrics);
    if (state.mode === 'both') return cat.concat([{ key: 'page', label: t('gsc.col_url'), dim: true, url: true }, { key: 'query', label: t('gsc.col_keyword'), dim: true }]).concat(metrics);
    return [{ key: 'query', label: t('gsc.col_keyword'), dim: true }].concat(metrics);
  }

  function fmtMetric(key, v) { return key === 'ctr' ? fmtPct(v) : (key === 'position' ? fmtPos(v) : fmtInt(v)); }

  // A metric cell — plain value, or (in comparison mode) current value with the
  // previous value + the row-by-row difference (abs and %) below it.
  function metricCell(r, key) {
    var cur = Number(r[key]) || 0;
    if (!state.comparing || r[key + '_p'] === undefined) {
      return '<td class="gsc-num">' + fmtMetric(key, cur) + '</td>';
    }
    var prev = Number(r[key + '_p']) || 0;
    return '<td class="gsc-num gsc-cmp"><span class="gsc-cmp-cur">' + fmtMetric(key, cur) + '</span>'
      + '<span class="gsc-cmp-sub"><span class="gsc-cmp-prev">' + fmtMetric(key, prev) + '</span> ' + cellDelta(key, cur, prev) + '</span></td>';
  }
  function cellDelta(key, cur, prev) {
    if (cur === prev) return '<span class="gsc-delta gsc-delta--flat">–</span>';
    var better = (key === 'position') ? (cur < prev) : (cur > prev);
    var cls = better ? 'up' : 'down', arrow = better ? '▲' : '▼', txt;
    if (key === 'position') { var d = cur - prev; txt = (d >= 0 ? '+' : '') + d.toFixed(1); }
    else if (key === 'ctr') { var dp = (cur - prev) * 100; txt = (dp >= 0 ? '+' : '') + dp.toFixed(2) + ' ' + t('gsc.pt'); }
    else { var ab = cur - prev, pc = prev !== 0 ? Math.round((cur - prev) / prev * 100) : 100; txt = (ab >= 0 ? '+' : '') + fmtInt(ab) + ' (' + (pc >= 0 ? '+' : '') + pc + ' %)'; }
    return '<span class="gsc-delta gsc-delta--' + cls + '">' + arrow + ' ' + txt + '</span>';
  }

  function renderTable(rows) {
    var cols = columns(), thead = $('gscThead'), tbody = $('gscTbody'), empty = $('gscEmpty');
    var htr = '<tr>';
    cols.forEach(function (c) {
      if (c.catcol) { htr += '<th class="gsc-catcol">' + c.label + '</th>'; return; }
      var ind = state.sort === c.key ? '<span class="gsc-sort-ind">' + (state.dir === 'asc' ? '▲' : '▼') + '</span>' : '';
      htr += '<th class="gsc-sortable' + (c.num ? ' gsc-num' : '') + '" data-sort="' + c.key + '">' + c.label + ' ' + ind + '</th>';
    });
    thead.innerHTML = htr + '</tr>';

    if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; }
    else {
      empty.style.display = 'none';
      // Drill navigation is offered only in single-dimension views (not "both"),
      // and never on the synthetic (anonyme) row.
      var drillable = state.mode !== 'both';
      var html = '';
      rows.forEach(function (r) {
        var isAnon = (r.query === ANON);
        html += '<tr' + (isAnon ? ' class="gsc-anon"' : '') + '>';
        cols.forEach(function (c) {
          var raw = r[c.key] || '';
          // The anon row's query value is the stored sentinel — display the localized label.
          var disp = (raw === ANON) ? t('gsc.anon_label') : raw;
          var drill = (drillable && c.dim && !isAnon)
            ? ' gsc-drill" data-drill-field="' + (c.url ? 'url' : 'query') + '" data-drill-value="' + esc(raw) + '"'
            : '"';
          if (c.catcol) {
            html += '<td class="gsc-catcell">' + catBadge(r.category) + '</td>';
          } else if (c.url) {
            html += '<td class="gsc-dim' + drill + ' title="' + esc(raw) + '"><div class="gsc-urlcell"><span class="gsc-url-text">' + esc(disp) + '</span>'
              + (raw ? '<a class="gsc-url-open" href="' + esc(raw) + '" target="_blank" rel="noopener" title="' + esc(t('gsc.open_new_tab')) + '"><span class="material-symbols-outlined">open_in_new</span></a>' : '')
              + '</div></td>';
          } else if (c.dim) {
            html += '<td class="gsc-dim' + drill + '><span title="' + esc(disp) + '">' + esc(disp) + '</span></td>';
          } else { html += metricCell(r, c.key); }
        });
        html += '</tr>';
      });
      tbody.innerHTML = html;
    }

    Array.prototype.forEach.call(thead.querySelectorAll('th[data-sort]'), function (th) {
      th.addEventListener('click', function () {
        var key = th.getAttribute('data-sort');
        if (state.sort === key) state.dir = (state.dir === 'asc' ? 'desc' : 'asc');
        else { state.sort = key; state.dir = (key === 'query' || key === 'page') ? 'asc' : 'desc'; }
        state.page = 1; reloadTableOnly();
      });
    });
  }

  // ---- table toolbars (per-page + pagination, top AND bottom) --------------
  function toolbarHtml() {
    var pages = Math.max(1, Math.ceil(state.total / state.perPage));
    var start = state.total ? (state.page - 1) * state.perPage + 1 : 0;
    var end = Math.min(state.page * state.perPage, state.total);
    var ppItems = PP_OPTIONS.map(function (n) { return '<div class="gsc-perpage-item' + (n === state.perPage ? ' active' : '') + '" data-pp="' + n + '">' + n + '</div>'; }).join('');
    return '<div class="gsc-tb-left">'
      + '<span class="gsc-tb-label">' + esc(t('gsc.show')) + '</span>'
      + '<div class="gsc-perpage-ctl">'
        + '<button type="button" class="gsc-perpage-btn" data-act="pp-toggle">' + state.perPage + '<span class="material-symbols-outlined">expand_more</span></button>'
        + '<div class="gsc-perpage-menu">' + ppItems + '</div>'
      + '</div>'
      + '<span class="gsc-tb-label">' + esc(t('gsc.rows')) + '</span>'
      + '</div>'
      + '<div class="gsc-tb-right">'
        + '<span class="gsc-tb-info">' + (state.total ? t('gsc.pagination_info', { start: fmtInt(start), end: fmtInt(end), total: fmtInt(state.total) }) : t('gsc.no_result')) + '</span>'
        + '<button type="button" class="gsc-tb-nav" data-act="prev"' + (state.page <= 1 ? ' disabled' : '') + '><span class="material-symbols-outlined">chevron_left</span></button>'
        + '<span class="gsc-tb-page">' + state.page + ' / ' + pages + '</span>'
        + '<button type="button" class="gsc-tb-nav" data-act="next"' + (state.page >= pages ? ' disabled' : '') + '><span class="material-symbols-outlined">chevron_right</span></button>'
      + '</div>';
  }
  function renderToolbars() { var h = toolbarHtml(); $('gscToolbarTop').innerHTML = h; $('gscToolbarBottom').innerHTML = h; }

  function initToolbarEvents() {
    document.addEventListener('click', function (e) {
      var inToolbar = e.target.closest('.gsc-toolbar');
      if (!inToolbar) { closePerPageMenus(); return; }
      var pp = e.target.closest('[data-pp]');
      if (pp) { state.perPage = parseInt(pp.getAttribute('data-pp'), 10); state.page = 1; closePerPageMenus(); reloadTableOnly(); return; }
      var act = e.target.closest('[data-act]');
      if (!act) return;
      var a = act.getAttribute('data-act');
      if (a === 'pp-toggle') {
        var menu = act.parentNode.querySelector('.gsc-perpage-menu');
        var open = menu.classList.contains('open'); closePerPageMenus();
        if (!open) menu.classList.add('open');
      } else if (a === 'prev') { if (state.page > 1) { state.page--; reloadTableOnly(); } }
      else if (a === 'next') { var pages = Math.max(1, Math.ceil(state.total / state.perPage)); if (state.page < pages) { state.page++; reloadTableOnly(); } }
    });
  }
  function closePerPageMenus() { document.querySelectorAll('.gsc-perpage-menu.open').forEach(function (m) { m.classList.remove('open'); }); }

  // ---- chart (day/week/month, 4 toggleable metrics) ------------------------
  // clicks/impressions are additive; ctr = Σclicks/Σimpr; position is
  // impression-weighted (Σ(pos·impr)/Σimpr) so week/month buckets stay correct.
  function aggregate(series, gran) {
    function finalize(o) {
      o.ctr = o.impressions ? o.clicks / o.impressions : 0;
      o.position = o._pw ? o._posw / o._pw : 0;
      return o;
    }
    if (gran === 'day') {
      // _from/_to = the calendar span a bar covers, so a custom event's date can
      // be mapped to the right category index (see computeEventPlots).
      return series.map(function (r) {
        var impr = +r.impressions || 0, pos = +r.position || 0;
        return { label: fmtFr(r.date), clicks: +r.clicks || 0, impressions: impr, ctr: +r.ctr || 0, position: pos, _from: r.date, _to: r.date };
      });
    }
    var buckets = {}, order = [];
    series.forEach(function (r) {
      var d = parseYmd(r.date), key, label, bfrom, bto;
      if (gran === 'month') { key = r.date.slice(0, 7); label = _fMonthShort.format(d) + ' ' + d.getFullYear(); bfrom = key + '-01'; bto = ymd(new Date(d.getFullYear(), d.getMonth() + 1, 0)); }
      else { var day = (d.getDay() + 6) % 7; var mon = new Date(d); mon.setDate(d.getDate() - day); key = ymd(mon); label = t('gsc.week_prefix') + mon.getDate() + '/' + (mon.getMonth() + 1); bfrom = ymd(mon); var sun = new Date(mon); sun.setDate(mon.getDate() + 6); bto = ymd(sun); }
      if (!buckets[key]) { buckets[key] = { label: label, clicks: 0, impressions: 0, _posw: 0, _pw: 0, _from: bfrom, _to: bto }; order.push(key); }
      var b = buckets[key], impr = +r.impressions || 0, pos = +r.position || 0;
      b.clicks += +r.clicks || 0; b.impressions += impr;
      if (pos > 0 && impr > 0) { b._posw += pos * impr; b._pw += impr; }
    });
    return order.map(function (k) { return finalize(buckets[k]); });
  }

  function renderChart() {
    if (!window.Highcharts) return;
    var data = aggregate(state.lastSeries, state.gran);
    var cats = data.map(function (r) { return r.label; });
    var dataComp = (state.compare !== 'none' && state.compareSeries.length) ? aggregate(state.compareSeries, state.gran) : null;
    var cmpNote = ' (' + (state.compare === 'year' ? t('gsc.compare_col_suffix') : t('gsc.compare_col_suffix')) + ')';
    var active = Object.keys(METRICS).filter(function (m) { return state.metrics[m]; });
    if (!active.length) active = ['clicks'];

    var axes = [], series = [];
    active.forEach(function (m, i) {
      var def = METRICS[m];
      axes.push({
        title: { text: null }, labels: { enabled: false }, gridLineWidth: i === 0 ? 1 : 0,
        gridLineColor: '#F0F3F6', opposite: i % 2 === 1, reversed: !!def.reversed,
        min: def.pct ? 0 : (def.reversed ? undefined : 0)
      });
      var val = function (r) { return def.pct ? (r[m] * 100) : r[m]; };
      series.push({
        name: def.label, type: def.type, color: def.color, yAxis: i,
        data: data.map(val), fillOpacity: 0.15, marker: { enabled: false }, zIndex: 2,
        tooltip: { valueSuffix: def.pct ? ' %' : '', valueDecimals: (def.pct || def.reversed) ? 2 : 0 }
      });
      if (dataComp) {
        // Index-aligned comparison overlay: same colour, dashed, so the two
        // periods sit on top of each other (day i vs day i), like GSC.
        series.push({
          name: def.label + cmpNote, type: 'line', color: def.color, yAxis: i,
          dashStyle: 'ShortDash', lineWidth: 1.5, opacity: 0.65, marker: { enabled: false }, zIndex: 1,
          data: cats.map(function (_, idx) { return dataComp[idx] ? val(dataComp[idx]) : null; }),
          tooltip: { valueSuffix: def.pct ? ' %' : '', valueDecimals: (def.pct || def.reversed) ? 2 : 0 }
        });
      }
    });

    // Map custom events → category indices for this aggregation, then draw them
    // as vertical plot-lines + an HTML flag overlay (title + hover description).
    var evPlot = computeEventPlots(data);
    var chart = Highcharts.chart('gscChart', {
      chart: {
        // Room at the top for the event title flags.
        spacingTop: evPlot.length ? 34 : 12,
        // redraw fires on reflow/resize → keep the flags aligned.
        events: { redraw: function () { drawEventOverlay(this, evPlot); } }
      },
      title: { text: null }, credits: { enabled: false },
      xAxis: { categories: cats, tickPixelInterval: 90, lineColor: '#E1E8ED' },
      yAxis: axes, legend: { enabled: true }, tooltip: { shared: true },
      series: series
    });
    // Draw the title flags synchronously (the chart is fully laid out here) —
    // don't rely solely on the render event, which is what made them go missing.
    drawEventOverlay(chart, evPlot);
  }

  // ---- custom events on the chart ------------------------------------------
  // For each event whose date falls in the visible range, find the category
  // index of the bucket that covers it (nearest bucket as a fallback when the
  // exact day has no data point). Multiple events can share an index (stacked).
  function eventIndexForDate(data, d) {
    for (var i = 0; i < data.length; i++) { if (d >= data[i]._from && d <= data[i]._to) return i; }
    var best = -1, bestDiff = Infinity; // nearest bucket by start date
    for (var j = 0; j < data.length; j++) {
      var diff = Math.abs(parseYmd(data[j]._from) - parseYmd(d));
      if (diff < bestDiff) { bestDiff = diff; best = j; }
    }
    return best;
  }
  // Group events that fall on the SAME bar (same date in day view, same week/month
  // bucket otherwise) → one flag per bar, so overlapping events don't stack.
  function computeEventPlots(data) {
    if (!data.length || !state.events.length) return [];
    var byIndex = {};
    state.events.forEach(function (ev) {
      var d = ev.event_date;
      if (d < state.from || d > state.to) return;
      var idx = eventIndexForDate(data, d);
      if (idx < 0) return;
      (byIndex[idx] = byIndex[idx] || []).push(ev);
    });
    return Object.keys(byIndex).map(function (k) {
      var evs = byIndex[k].slice().sort(function (a, b) { return a.event_date < b.event_date ? -1 : (a.event_date > b.event_date ? 1 : a.id - b.id); });
      return { index: parseInt(k, 10), events: evs };
    });
  }

  function drawEventOverlay(chart, evPlot) {
    var host = chart.renderTo;
    var old = host.querySelector('.gsc-event-layer');
    if (old) old.parentNode.removeChild(old);
    if (!evPlot.length) return;

    var layer = document.createElement('div');
    layer.className = 'gsc-event-layer';
    var plotLeft = chart.plotLeft, plotRight = chart.plotLeft + chart.plotWidth;
    var plotTop = chart.plotTop, plotH = chart.plotHeight;
    var GAP = 6, EDGE = 130, flagTop = Math.max(0, plotTop - 22);
    var placed = [];

    evPlot.forEach(function (g) {
      var px;
      try { px = chart.xAxis[0].toPixels(g.index); } catch (_) { return; }
      if (px == null || isNaN(px)) return;
      px = Math.max(plotLeft, Math.min(plotRight, px));

      // Vertical line drawn by us → always spans the FULL plot height (top→bottom).
      var line = document.createElement('div');
      line.className = 'gsc-event-line';
      line.style.left = px + 'px'; line.style.top = plotTop + 'px'; line.style.height = plotH + 'px';
      layer.appendChild(line);

      var multi = g.events.length > 1;
      var flag = document.createElement('div');
      flag.className = 'gsc-event-flag' + (multi ? ' multi' : '');
      // ALWAYS centered on the line (CSS translateX(-50%)) → the pill sits above
      // the line and the pointer stays exactly under it, at every x position.
      flag.style.left = px + 'px';
      flag.style.top = flagTop + 'px';
      // Only the TOOLTIP flips near the edges so it never leaves the chart — the
      // pill/pointer are never shifted (that's what used to look detached).
      if (px > plotRight - EDGE) flag.classList.add('tip-right');
      else if (px < plotLeft + EDGE) flag.classList.add('tip-left');

      // Tooltip lists EVERY event on this bar (date + title + description).
      var items = g.events.map(function (ev) {
        return '<span class="gsc-event-pop-item">'
          + '<span class="gsc-event-pop-date">' + esc(fmtFr(ev.event_date)) + '</span>'
          + '<span class="gsc-event-pop-title">' + esc(ev.title) + '</span>'
          + (ev.description ? '<span class="gsc-event-pop-desc">' + esc(ev.description) + '</span>' : '')
          + '</span>';
      }).join('');
      var pop = '<span class="gsc-event-pop">' + (multi ? '<span class="gsc-event-pop-scroll">' + items + '</span>' : items) + '</span>';
      // Label: the single title, or "N événements" when several share the bar.
      var label = multi ? t('gsc.event_count', { n: g.events.length }) : g.events[0].title;
      flag.innerHTML = '<span class="material-symbols-outlined">flag</span>'
        + '<span class="gsc-event-flag-label">' + esc(label) + '</span>' + pop;
      if (multi) {
        flag.style.cursor = 'default'; // ambiguous which to edit → manage via the list
      } else {
        flag.addEventListener('click', function (ev2) { ev2.stopPropagation(); openEventEditor(g.events[0], flag); });
      }
      layer.appendChild(flag);
      placed.push({ el: flag, px: px });
    });
    host.appendChild(layer);
    if (!placed.length) return;

    // ---- de-overlap ---------------------------------------------------------
    placed.sort(function (a, b) { return a.px - b.px; });
    var hostRect = host.getBoundingClientRect();
    // (1) A flag keeps its TITLE only when it neither reaches a neighbour NOR
    //     overflows the chart edge; otherwise it collapses to the drapeau icon
    //     (full detail stays on hover). The icon is small and stays centered, so
    //     the pointer is always right under it.
    placed.forEach(function (p, i) {
      var r = p.el.getBoundingClientRect(), half = r.width / 2;
      var roomL = p.px - (i > 0 ? placed[i - 1].px : -1e9);
      var roomR = (i < placed.length - 1 ? placed[i + 1].px : 1e9) - p.px;
      var tight = Math.min(roomL, roomR) < half + GAP;
      var overflow = r.left < hostRect.left + 1 || r.right > hostRect.right - 1;
      if (tight || overflow) p.el.classList.add('icon-only');
    });
    // (2) Anything that STILL overlaps horizontally (dense clusters) is stacked
    //     into extra rows so the drapeaux never sit on top of each other.
    var rowsRight = [], ROW_H = 20;
    placed.forEach(function (p) {
      var r = p.el.getBoundingClientRect(), row = 0;
      while (row < rowsRight.length && r.left < rowsRight[row] + GAP) row++;
      rowsRight[row] = r.right;
      if (row > 0) p.el.style.top = (flagTop + row * ROW_H) + 'px';
    });
  }
  function findEvent(id) { for (var i = 0; i < state.events.length; i++) { if (state.events[i].id === id) return state.events[i]; } return null; }

  function renderKpiActive() {
    Object.keys(METRICS).forEach(function (m) {
      var card = document.querySelector('.gsc-kpi[data-kpi="' + m + '"]');
      if (card) card.classList.toggle('gsc-kpi--off', !state.metrics[m]);
    });
  }

  // ---- FilterBar (shared component) ----------------------------------------
  var fb = null;
  function initFilterBar() {
    if (typeof FilterBar === 'undefined') return;
    var TEXT_OPS = ['contains', 'not_contains', 'regex', 'not_regex'];
    var cats = (window.GSC && window.GSC.categories) || [];
    var fieldConfig = {
      query: { label: t('gsc.col_keyword'), type: 'text', operators: TEXT_OPS },
      url:   { label: t('gsc.col_url'), type: 'text', operators: TEXT_OPS }
    };
    // The project's URL categorization rules become a "category" filter on URLs.
    if (cats.length) {
      fieldConfig.category = { label: t('url_explorer.field_category'), type: 'category', operators: ['in', 'not_in'] };
    }
    fb = new FilterBar({
      fieldConfig: fieldConfig,
      availableCategories: cats,
      initialFilters: [],
      onApply: function () { state.filters = fbGroupsToFilters(fb.filterGroups); state.page = 1; reload(); }
    });
  }
  function fbGroupsToFilters(groups) {
    return (groups || []).map(function (group) { return { type: 'group', logic: 'OR', items: group.map(function (c) { return { field: c.field, operator: c.operator, value: c.value }; }) }; });
  }

  // ---- custom calendar date-range picker -----------------------------------
  var PRESET_LABELS = { 7: t('gsc.range_7d'), 28: t('gsc.range_28d'), 90: t('gsc.range_90d'), 180: t('gsc.range_180d'), 365: t('gsc.range_365d') };
  var cal = { view: null, start: null, end: null }; // view = first-of-month Date; start/end = 'ymd'

  // Latest day the ranges anchor on = the last day WITH data (last_synced_date),
  // matching GSC. Falls back to today-2 (freshness lag) before the first sync.
  function maxDate() {
    if (window.GSC.lastSynced) {
      var d = parseYmd(window.GSC.lastSynced);
      if (!isNaN(d.getTime())) return d;
    }
    var t = new Date(); t.setDate(t.getDate() - 2); return t;
  }

  function applyPreset(days, silent) {
    var to = maxDate(); var from = new Date(to); from.setDate(from.getDate() - (days - 1));
    state.from = ymd(from); state.to = ymd(to);
    cal.start = state.from; cal.end = state.to; cal.view = new Date(to.getFullYear(), to.getMonth(), 1);
    state.dateBaseLabel = PRESET_LABELS[days] || (fmtFr(state.from) + ' – ' + fmtFr(state.to));
    refreshDateLabel();
    markPreset(days);
    if (!silent) { state.page = 1; reload(); closeDate(); }
  }
  function refreshDateLabel() {
    var suffix = state.compare === 'previous' ? ('  ·  ' + t('gsc.compare_suffix_prev')) : (state.compare === 'year' ? ('  ·  ' + t('gsc.compare_suffix_year')) : '');
    $('gscDateLabel').textContent = state.dateBaseLabel + suffix;
  }
  function markPreset(days) {
    Array.prototype.forEach.call(document.querySelectorAll('#gscDpPresets .gsc-dp-preset'), function (b) {
      b.classList.toggle('active', days != null && String(b.getAttribute('data-days')) === String(days));
    });
  }

  function renderCalendar() {
    if (!cal.view) cal.view = new Date();
    var y = cal.view.getFullYear(), m = cal.view.getMonth();
    var _mn = _fMonthLong.format(new Date(y, m, 1)); $('gscDpMonth').textContent = _mn.charAt(0).toUpperCase() + _mn.slice(1) + ' ' + y;
    var first = new Date(y, m, 1), startWd = (first.getDay() + 6) % 7, days = new Date(y, m + 1, 0).getDate();
    var max = ymd(maxDate());
    var html = WD.map(function (d) { return '<span class="gsc-dp-wd">' + d + '</span>'; }).join('');
    for (var i = 0; i < startWd; i++) html += '<span class="gsc-dp-day empty"></span>';
    for (var day = 1; day <= days; day++) {
      var ds = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
      var cls = 'gsc-dp-day';
      if (ds > max) cls += ' disabled';
      if (ds === cal.start || ds === cal.end) cls += ' sel';
      else if (cal.start && cal.end && ds > cal.start && ds < cal.end) cls += ' inrange';
      html += '<button type="button" class="' + cls + '" data-d="' + ds + '"' + (ds > max ? ' disabled' : '') + '>' + day + '</button>';
    }
    $('gscDpGrid').innerHTML = html;
    $('gscDpRange').textContent = cal.start ? (fmtFr(cal.start) + (cal.end ? '  →  ' + fmtFr(cal.end) : '  →  …')) : t('gsc.pick_range');
  }

  function pickDay(ds) {
    if (!cal.start || (cal.start && cal.end)) { cal.start = ds; cal.end = null; }
    else if (ds >= cal.start) { cal.end = ds; }
    else { cal.end = cal.start; cal.start = ds; }
    markPreset(null);
    renderCalendar();
  }

  function openDate() { $('gscDateMenu').classList.add('open'); $('gscDaterange').classList.add('open'); renderCalendar(); }
  function closeDate() { $('gscDateMenu').classList.remove('open'); $('gscDaterange').classList.remove('open'); }

  function initDate() {
    applyPreset(28, true);
    $('gscDateBtn').addEventListener('click', function (e) { e.stopPropagation(); $('gscDateMenu').classList.contains('open') ? closeDate() : openDate(); });
    // Clicks INSIDE the picker must not bubble to the document "click-outside"
    // handler — a day click re-renders the grid, so its target detaches and the
    // outside-check would wrongly close the picker before the 2nd date.
    $('gscDateMenu').addEventListener('click', function (e) { e.stopPropagation(); });
    Array.prototype.forEach.call(document.querySelectorAll('#gscDpPresets .gsc-dp-preset'), function (b) {
      b.addEventListener('click', function () { applyPreset(parseInt(b.getAttribute('data-days'), 10)); });
    });
    $('gscDpPrev').addEventListener('click', function () { cal.view = new Date(cal.view.getFullYear(), cal.view.getMonth() - 1, 1); renderCalendar(); });
    $('gscDpNext').addEventListener('click', function () { cal.view = new Date(cal.view.getFullYear(), cal.view.getMonth() + 1, 1); renderCalendar(); });
    $('gscDpGrid').addEventListener('click', function (e) { var b = e.target.closest('.gsc-dp-day[data-d]'); if (b && !b.disabled) pickDay(b.getAttribute('data-d')); });
    $('gscDateApply').addEventListener('click', function () {
      if (!cal.start) return;
      state.from = cal.start; state.to = cal.end || cal.start;
      state.dateBaseLabel = fmtFr(state.from) + ' – ' + fmtFr(state.to);
      refreshDateLabel();
      state.page = 1; reload(); closeDate();
    });
    // Comparison mode (previous period / year-over-year) — keeps the picker open.
    $('gscCompare').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      setActive('#gscCompare button', b);
      state.compare = b.getAttribute('data-cmp');
      refreshDateLabel(); reload();
    });
    document.addEventListener('click', function (e) { if (!$('gscDaterange').contains(e.target)) closeDate(); });
  }

  // ---- segmented + switch --------------------------------------------------
  function setActive(sel, el) { Array.prototype.forEach.call(document.querySelectorAll(sel), function (b) { b.classList.remove('active'); }); if (el) el.classList.add('active'); }

  function setMode(mode) {
    state.mode = mode;
    var btn = document.querySelector('#gscModes button[data-mode="' + mode + '"]');
    setActive('#gscModes button', btn);
    if (btn) $('gscTableTitle').textContent = btn.textContent.trim();
    state.sort = 'clicks'; state.dir = 'desc';
  }

  // Drill: click a keyword → filter query=that + switch to URL view (its URLs);
  // click a URL → filter url=that + switch to keyword view (its keywords).
  // Resets any existing filters before adding the new one.
  function drill(field, value) {
    if (!fb || value === '' || value == null) return;
    fb.filterGroups = [[{ field: field, operator: '=', value: value, target: null }]];
    fb.renderChips();
    state.filters = fbGroupsToFilters(fb.filterGroups);
    setMode(field === 'query' ? 'urls' : 'keywords');
    state.page = 1;
    reload();
  }

  function initControls() {
    $('gscModes').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      setMode(b.getAttribute('data-mode')); state.page = 1; reload();
    });

    // KPI cards toggle chart metrics (keep at least one active).
    Array.prototype.forEach.call(document.querySelectorAll('.gsc-kpi'), function (card) {
      card.addEventListener('click', function () {
        var m = card.getAttribute('data-kpi');
        var activeCount = Object.keys(state.metrics).filter(function (k) { return state.metrics[k]; }).length;
        if (state.metrics[m] && activeCount <= 1) return; // don't turn off the last one
        state.metrics[m] = !state.metrics[m];
        renderKpiActive(); renderChart(); renderTable(state.lastRows || []);
      });
    });

    // Drill-down on dimension cells (delegated; tbody persists across renders).
    $('gscTbody').addEventListener('click', function (e) {
      if (e.target.closest('.gsc-url-open')) return; // the open-in-new link wins
      var cell = e.target.closest('.gsc-drill'); if (!cell) return;
      drill(cell.getAttribute('data-drill-field'), cell.getAttribute('data-drill-value'));
    });
    $('gscGranularity').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      setActive('#gscGranularity button', b); state.gran = b.getAttribute('data-gran'); renderChart();
    });
    $('gscIncludeAnon').addEventListener('change', function () { state.includeAnon = this.checked; state.page = 1; reload(); });
    $('gscExport').addEventListener('click', exportCsv);

    // Disconnect → custom confirm modal (same as the dashboard's project delete).
    var df = $('gscDisconnectForm');
    if (df) {
      df.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = t('gsc.disconnect_confirm');
        if (typeof window.customConfirm === 'function') {
          window.customConfirm(msg, t('gsc.disconnect_title'), t('gsc.disconnect'), 'danger').then(function (ok) { if (ok) df.submit(); });
        } else if (window.confirm(msg)) { df.submit(); }
      });
    }
  }

  // ---- export → async job (download center) --------------------------------
  function exportCsv() {
    if (typeof window.queueExport !== 'function') { alert(t('gsc.export_unavailable')); return; }
    var p = {
      type: 'gsc', project: state.projectId, mode: state.mode, from: state.from, to: state.to,
      include_anon: state.includeAnon ? '1' : '0', filters: state.filters,
      metrics: Object.keys(METRICS).filter(function (m) { return state.metrics[m]; }),
      compare: state.compare
    };
    var cr = compareRange();
    if (cr) { p.cfrom = cr.cfrom; p.cto = cr.cto; }
    window.queueExport(p);
  }

  // ---- status polling during backfill --------------------------------------
  function pollStatus() {
    fetch('/api/gsc/status?project=' + state.projectId, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (s) {
      if (!s || !s.connected) return;
      var badge = $('gscStatusBadge');
      if (badge && s.status) { badge.textContent = s.status; badge.className = 'gsc-badge gsc-badge--' + s.status; }
      if (s.last_synced_date) $('gscLastSync').textContent = t('gsc.data_until', { date: s.last_synced_date });
      if (s.status === 'backfilling' || s.status === 'connecting') { reload(); setTimeout(pollStatus, 15000); }
    }).catch(noop);
  }

  // ---- custom events: editor popover + CRUD --------------------------------
  var evState = { view: null, sel: null, editingId: null };

  function loadEvents() {
    fetch('/api/gsc/events?project=' + state.projectId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        state.events = (res && res.events) || [];
        renderEventList();
        if (state.lastSeries.length) renderChart(); // refresh flags
      }).catch(noop);
  }

  function openEventEditor(ev, anchor) {
    var form = $('gscEvForm'); if (!form) return;
    setEvError('');
    if (ev) {
      evState.editingId = ev.id; evState.sel = ev.event_date;
      $('gscEvTitle').value = ev.title; $('gscEvDesc').value = ev.description || '';
      $('gscEvFormTitle').textContent = t('gsc.event_edit');
      $('gscEvSave').textContent = t('gsc.event_update');
    } else {
      evState.editingId = null; evState.sel = state.to || ymd(new Date());
      $('gscEvTitle').value = ''; $('gscEvDesc').value = '';
      $('gscEvFormTitle').textContent = t('gsc.event_add');
      $('gscEvSave').textContent = t('gsc.event_save');
    }
    var d = parseYmd(evState.sel); evState.view = new Date(d.getFullYear(), d.getMonth(), 1);
    renderEvCalendar();
    positionEvPopover(anchor || $('gscEvAdd'));  // floats out of flow, under the trigger
    $('gscEvTitle').focus();
  }
  // Place the fixed popover just under its trigger, clamped to the viewport
  // (flips above if there isn't room below).
  function positionEvPopover(anchor) {
    var pop = $('gscEvForm'); if (!pop || !anchor) return;
    pop.style.visibility = 'hidden'; pop.style.display = 'block';
    var r = anchor.getBoundingClientRect();
    var pw = pop.offsetWidth, ph = pop.offsetHeight, vw = window.innerWidth, vh = window.innerHeight, gap = 6, m = 8;
    var left = r.left;
    if (left + pw > vw - m) left = Math.max(m, r.right - pw); // right-align to the trigger
    if (left < m) left = m;
    var top = r.bottom + gap;
    if (top + ph > vh - m) { var above = r.top - gap - ph; top = above > m ? above : Math.max(m, vh - ph - m); }
    pop.style.left = left + 'px'; pop.style.top = top + 'px';
    pop.style.visibility = '';
  }
  function closeEventForm() { var f = $('gscEvForm'); if (f) f.style.display = 'none'; }

  function renderEvCalendar() {
    if (!evState.view) evState.view = new Date();
    var y = evState.view.getFullYear(), m = evState.view.getMonth();
    var mn = _fMonthLong.format(new Date(y, m, 1));
    $('gscEvMonth').textContent = mn.charAt(0).toUpperCase() + mn.slice(1) + ' ' + y;
    // Days that already carry event(s) → light-violet + a small flag marker (adding
    // a second event on the same day stays allowed; this is purely informative).
    var evOnDay = {};
    state.events.forEach(function (ev) { evOnDay[ev.event_date] = (evOnDay[ev.event_date] || 0) + 1; });
    var startWd = (new Date(y, m, 1).getDay() + 6) % 7, days = new Date(y, m + 1, 0).getDate();
    var html = WD.map(function (d) { return '<span class="gsc-dp-wd">' + d + '</span>'; }).join('');
    for (var i = 0; i < startWd; i++) html += '<span class="gsc-dp-day empty"></span>';
    for (var day = 1; day <= days; day++) {
      var ds = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
      var n = evOnDay[ds] || 0;
      var cls = 'gsc-dp-day' + (ds === evState.sel ? ' sel' : '') + (n ? ' has-ev' : '');
      // A day that already has event(s): light-violet cell + a small dot UNDER the
      // number (CSS ::after, absolutely placed) so nothing overlaps the digit.
      var titleAttr = n ? ' title="' + esc(t('gsc.events') + ' (' + n + ')') + '"' : '';
      html += '<button type="button" class="' + cls + '"' + titleAttr + ' data-d="' + ds + '">' + day + '</button>';
    }
    $('gscEvGrid').innerHTML = html;
    $('gscEvSelected').textContent = evState.sel ? fmtFr(evState.sel) : '—';
  }

  function renderEventList() {
    var box = $('gscEvList'); if (!box) return;
    if (!state.events.length) { box.innerHTML = '<div class="gsc-ev-empty">' + esc(t('gsc.event_none')) + '</div>'; return; }
    // Newest → oldest (state.events comes back oldest-first for the chart).
    var sorted = state.events.slice().sort(function (a, b) {
      return a.event_date < b.event_date ? 1 : (a.event_date > b.event_date ? -1 : b.id - a.id);
    });
    box.innerHTML = sorted.map(function (e) {
      return '<div class="gsc-ev-item" data-id="' + e.id + '">'
        + '<div class="gsc-ev-item-main" data-edit="' + e.id + '">'
        +   '<div class="gsc-ev-item-date"><span class="material-symbols-outlined">event</span>' + esc(fmtFr(e.event_date)) + '</div>'
        +   '<div class="gsc-ev-item-title" title="' + esc(e.title) + '">' + esc(e.title) + '</div>'
        +   (e.description ? '<div class="gsc-ev-item-desc" title="' + esc(e.description) + '">' + esc(e.description) + '</div>' : '')
        + '</div>'
        + '<div class="gsc-ev-item-actions">'
        +   '<button type="button" class="gsc-ev-act" data-edit="' + e.id + '" title="' + esc(t('gsc.event_edit')) + '"><span class="material-symbols-outlined">edit</span></button>'
        +   '<button type="button" class="gsc-ev-act del" data-del="' + e.id + '" title="' + esc(t('gsc.event_delete')) + '"><span class="material-symbols-outlined">delete</span></button>'
        + '</div>'
        + '</div>';
    }).join('');
  }

  function setEvError(msg) { var e = $('gscEvError'); if (!e) return; e.textContent = msg || ''; e.style.display = msg ? 'block' : 'none'; }

  function saveEvent() {
    var title = ($('gscEvTitle').value || '').trim();
    if (!evState.sel) { setEvError(t('gsc.event_err_date')); return; }
    if (!title) { setEvError(t('gsc.event_err_title')); return; }
    setEvError('');
    var body = { project: state.projectId, date: evState.sel, title: title, description: ($('gscEvDesc').value || '').trim() };
    var url = '/api/gsc/events';
    if (evState.editingId) { url = '/api/gsc/events/update'; body.id = evState.editingId; }
    $('gscEvSave').disabled = true;
    postJson(url, body).then(function (res) {
      $('gscEvSave').disabled = false;
      if (res && res.error) { setEvError(res.error); return; }
      closeEventForm();
      loadEvents();
    }).catch(function () { $('gscEvSave').disabled = false; setEvError(t('gsc.event_err_generic')); });
  }

  // Ask before deleting — reuses the app's styled confirm modal (same as the
  // GSC disconnect action), with a plain-confirm fallback.
  function confirmDelete(id) {
    var ev = findEvent(id);
    var msg = t('gsc.event_delete_confirm', { title: ev ? ev.title : '' });
    if (typeof window.customConfirm === 'function') {
      window.customConfirm(msg, t('gsc.event_delete_title'), t('gsc.event_delete'), 'danger')
        .then(function (ok) { if (ok) deleteEvent(id); });
    } else if (window.confirm(msg)) { deleteEvent(id); }
  }

  function deleteEvent(id) {
    postJson('/api/gsc/events/delete', { project: state.projectId, id: id }).then(function () {
      if (evState.editingId === id) { evState.editingId = null; closeEventForm(); }
      loadEvents();
    }).catch(noop);
  }

  function initEvents() {
    var add = $('gscEvAdd'); if (!add) return;
    // Toggle: "+ Add" opens a blank editor under the button (or closes it).
    add.addEventListener('click', function (e) {
      e.stopPropagation();
      var f = $('gscEvForm');
      if (f.style.display === 'block' && !evState.editingId) closeEventForm();
      else openEventEditor(null, add);
    });
    // Clicks inside the popover stay inside (don't reach the outside-close handler;
    // day clicks re-render the grid so their target detaches, but bubbling still
    // passes through this container).
    $('gscEvForm').addEventListener('click', function (e) { e.stopPropagation(); });
    $('gscEvClose').addEventListener('click', closeEventForm);
    $('gscEvCancel').addEventListener('click', closeEventForm);
    $('gscEvPrev').addEventListener('click', function () { evState.view = new Date(evState.view.getFullYear(), evState.view.getMonth() - 1, 1); renderEvCalendar(); });
    $('gscEvNext').addEventListener('click', function () { evState.view = new Date(evState.view.getFullYear(), evState.view.getMonth() + 1, 1); renderEvCalendar(); });
    $('gscEvGrid').addEventListener('click', function (e) { var b = e.target.closest('.gsc-dp-day[data-d]'); if (b) { evState.sel = b.getAttribute('data-d'); renderEvCalendar(); } });
    $('gscEvSave').addEventListener('click', saveEvent);
    $('gscEvTitle').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveEvent(); } });
    // List: edit (row or pencil) / delete (delegated; the list re-renders often).
    // stopPropagation so opening the editor isn't immediately closed by the
    // document handler below.
    $('gscEvList').addEventListener('click', function (e) {
      var del = e.target.closest('[data-del]');
      if (del) { e.stopPropagation(); confirmDelete(parseInt(del.getAttribute('data-del'), 10)); return; }
      var ed = e.target.closest('[data-edit]');
      if (ed) { e.stopPropagation(); var ev = findEvent(parseInt(ed.getAttribute('data-edit'), 10)); if (ev) openEventEditor(ev, ed); }
    });
    // Close the floating editor on outside click / Escape / resize.
    document.addEventListener('click', closeEventForm);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeEventForm(); });
    window.addEventListener('resize', closeEventForm);
  }

  // ---- boot ----------------------------------------------------------------
  initFilterBar(); initDate(); initControls(); initToolbarEvents(); initEvents();
  renderKpiActive();
  reload();
  loadEvents();
  if (window.GSC.status === 'backfilling' || window.GSC.status === 'connecting') setTimeout(pollStatus, 8000);
})();
