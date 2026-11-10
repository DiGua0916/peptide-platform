/* Facts stay local to the authorised store. No external AI request is made here. */
(function () {
 'use strict';
 const root = document.getElementById('pc-trk-insights'), cfg = window.pcTrackingAdmin;
 if (!root || !cfg || !window.wp?.apiFetch) return;
 const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const seconds = n => n === null ? '未采集' : n < 60 ? Math.round(n) + ' 秒' : Math.floor(n / 60) + ' 分 ' + Math.round(n % 60) + ' 秒';
 const n = value => value === null ? '—' : Number(value || 0).toLocaleString('zh-CN');
 let generation = 0, controller = null, accepted = null, destroyed = false;
 let subject = Number(cfg.visitor) || 0, period = subject ? 'all' : 'day', visit = 0;
 root.innerHTML = '<section class="pc-insights"><header class="pc-insights-head"><div><h2>' + (subject ? '客户完整访问记录' : '大家对什么感兴趣') + '</h2><p>' + (subject ? '查看保留期内的页面停留与行为明细' : '访客最关注的商品和最常点的版块，按热度排序') + '</p></div><div class="pc-insights-actions"><label>统计日期<input type="date" name="insight_date" max="' + esc(cfg.today) + '" value="' + esc(cfg.today) + '"></label><button type="button" class="button" data-insight-export disabled>导出分析数据</button></div></header><div class="pc-insights-toolbar"><div class="pc-insights-period" role="group" aria-label="统计周期">' + (subject ? [['all','全部记录'],['day','每日'],['week','每周'],['month','每月']] : [['day','每日'],['week','每周'],['month','每月']]).map(([key,label]) => '<button type="button" data-insight-period="' + key + '" aria-pressed="' + (key === period) + '">' + label + '</button>').join('') + '</div><span class="pc-insights-range"></span><button type="button" class="button" data-insight-retry>刷新数据</button></div><div class="pc-insights-status" role="status" aria-live="polite"></div><div class="pc-insights-results"></div>' + '<section class="pc-interest-ai" data-ai></section>' + '<details class="pc-insights-method"><summary>统计口径与 AI 分析</summary><p>停留为页面可见且活跃时，相邻信号间隔不超过 30 秒的观测时长；总时长去除同一访客多标签页重叠。没有信号的时间不补算，页面和商品时长不能直接相加。全部记录仅包括当前保留期内已采集的数据；已清理的历史无法恢复。点击下方访问记录可查看该次访问的页面统计和时间线，点击“全部记录”返回客户全程汇总。</p><p>一个访客编号对应一个浏览器，不能据此认定同一自然人。浏览和点击是兴趣线索，不代表购买意愿或个人属性。</p><p>AI 分析只在点“AI 分析”时进行：把上面这些行为数据（不含姓名、邮箱、电话）交给总后台的 AI，按总后台“AI 设置”里的人设分析，不会自动发送。客户分析和全站分析师各用各的人设。</p></details></section>';
 const results = root.querySelector('.pc-insights-results'), status = root.querySelector('.pc-insights-status'), date = root.querySelector('input'), exportButton = root.querySelector('[data-insight-export]');
 const labels = {'demo-catalog':'商品目录','carousel':'轮播','faq':'常见问题','media-text':'图文介绍','report-feature':'检测报告','product-grid':'商品区','footer':'页脚','header':'导航'};
 function visitorLinks(x) {
  const list = Array.isArray(x.visitors) ? x.visitors : [];
  if (!list.length) return '—';
  const base = (cfg.liveUrl || '') + '&visitor=';
  return list.slice(0, 12).map(v => '<a href="' + esc(base + v.id) + '">访客 #' + esc(v.id) + '</a><small>' + n(v.clicks) + ' 次</small>').join('<br>') + ((x.visitor_count || list.length) > 12 ? '<br><small>共 ' + n(x.visitor_count) + ' 位</small>' : '');
 }
 function table(title, columns, rows, empty) {
  return '<section class="pc-interest-section"><h3>' + title + '</h3>' + (rows.length && columns.length > 4 ? '<p class="pc-interest-scroll-hint">左右滑动可查看全部字段，含成功加购。</p>' : '') + (rows.length ? '<div class="pc-interest-table-wrap" tabindex="0" role="region" aria-label="' + title + '"><table><thead><tr>' + columns.map(x => '<th scope="col">' + x + '</th>').join('') + '</tr></thead><tbody>' + rows.map(row => '<tr>' + row.map((cell,i) => '<' + (i ? 'td' : 'th scope="row"') + '>' + cell + '</' + (i ? 'td' : 'th') + '>').join('') + '</tr>').join('') + '</tbody></table></div>' : '<p class="pc-interest-empty">' + empty + '</p>') + '</section>';
 }
 function bars(title, items, empty) {
  const max = items.reduce((m, x) => Math.max(m, x.value), 0) || 1;
  return '<section class="pc-hot-card"><h3>' + title + '</h3>' + (items.length ? '<ol class="pc-hot-list">' + items.map((x, i) => '<li><span class="pc-hot-rank">' + (i + 1) + '</span><div class="pc-hot-main"><div class="pc-hot-row"><span class="pc-hot-name">' + x.name + '</span><span class="pc-hot-val">' + x.text + '</span></div><div class="pc-hot-bar"><i style="width:' + Math.max(4, Math.round(x.value / max * 100)) + '%"></i></div>' + (x.sub ? '<small>' + x.sub + '</small>' : '') + '</div></li>').join('') + '</ol>' : '<p class="pc-interest-empty">' + empty + '</p>') + '</section>';
 }
 function paintHot(data) {
  const prods = data.products.map(x => ({ name: esc(x.name), value: Number(x.views || 0) * 2 + Number(x.clicks || 0) + Number(x.cart_additions || 0) * 5, text: Number(x.views) ? n(x.views) + ' 次浏览' : n(x.clicks) + ' 次点击', sub: [x.cart_additions ? n(x.cart_additions) + ' 次加购' : '', x.clicks ? n(x.clicks) + ' 次点击' : '', x.active_seconds ? '共看了 ' + seconds(x.active_seconds) : ''].filter(Boolean).join(' · ') })).filter(x => x.value > 0).sort((a, b) => b.value - a.value).slice(0, 10);
  const mods = data.modules.map(x => ({ name: esc(x.label || labels[x.module_type] || x.module_type || x.module_id), value: Number(x.clicks || 0), text: n(x.clicks) + ' 次点击', sub: (x.visitor_count || (x.visitors || []).length) ? n(x.visitor_count || x.visitors.length) + ' 位访客点过' + ((x.pages || []).length ? ' · ' + (x.pages || []).slice(0, 2).map(pg => esc(pg.path === '/' ? '首页' : pg.path)).join('、') : '') : '' })).filter(x => x.value > 0).sort((a, b) => b.value - a.value).slice(0, 8);
  const carts = data.products.filter(x => Number(x.cart_additions) > 0).sort((a, b) => b.cart_additions - a.cart_additions).slice(0, 6).map(x => ({ name: esc(x.name), value: Number(x.cart_additions), text: n(x.cart_additions) + ' 次', sub: '' }));
  results.innerHTML = '<div class="pc-hot-grid">' + bars('最受关注的商品', prods, '这段时间还没有人看商品。') + bars('最常点的版块', mods, '这段时间没有人点过页面版块。') + bars('被加进购物车最多', carts, '这段时间还没有加购。') + '</div>';
 }
 function paint(data) {
  if (!subject) { paintHot(data); range(data); return; }
  const s = data.summary;
  results.innerHTML = '<dl class="pc-interest-summary">' + [['实际停留时长',seconds(s.active_seconds)],['页面浏览次数',n(s.pageviews)],['访问次数',n(s.visits)],['记录到的动作数',n(s.observed_events)]].map(([label,value]) => '<div><dt>' + label + '</dt><dd>' + value + '</dd></div>').join('') + '</dl><div class="pc-interest-columns">' +
   table('页面停留',['页面','浏览次数','实际停留时长'],data.pages.map(x => [esc(x.path || '（未知页面）'),n(x.views),seconds(x.active_seconds)]),'这段时间还没有页面停留记录。') +
   table('模块点击',['点了哪个模块','在哪个页面','点击次数','是哪些访客点的'],data.modules.map(x => [esc(x.label || labels[x.module_type] || x.module_type || x.module_id), (x.pages || []).map(pg => esc(pg.path === '/' ? '首页 /' : (pg.path || '（未知页面）')) + ' <small>' + n(pg.clicks) + ' 次</small>').join('<br>') || '—', n(x.clicks), visitorLinks(x)]),'这段时间没有人点过页面模块。') + '</div>' +
   table('商品兴趣',['商品','商品浏览','商品点击','实际停留时长','成功加购'],data.products.map(x => [esc(x.name),n(x.views),n(x.clicks),seconds(x.active_seconds),n(x.cart_additions)]),'这段时间没有商品浏览记录。');
  range(data);
 }
 function range(data) {
  root.querySelector('.pc-insights-range').textContent = (data.period.retained_history ? (visit ? '所选访问 · ' : '全部保留记录 · ') : '') + data.period.from + ' 至 ' + data.period.to + ' · ' + data.period.timezone;
  status.textContent = data.coverage.schema_ready === false ? '这家店还没开始记录访客，暂时没有数据。' : (data.coverage.partial || data.coverage.ranking_truncated) ? '记录太多，一次没读完，下面只是一部分，不能当完整结论。' : !data.coverage.tracking_enabled ? '访客记录现在是关闭的，下面只是以前的记录。' : (subject ? '已更新。停留时长按实际收到的信号算，没信号的时间不算进去。' : '已更新。');
 }
 async function refresh() {
  const id = ++generation; controller?.abort(); controller = new AbortController(); accepted = null; exportButton.disabled = true;
  status.textContent = results.textContent ? '正在读取新周期数据… 下方暂留上次成功读取的结果，导出已暂停。' : '正在读取行为数据…'; root.setAttribute('aria-busy','true');
  date.disabled = period === 'all';
  const params = new URLSearchParams({period,date:date.value,visitor_id:String(subject),visit_id:String(visit)});
  const requestController = controller;
  const timeout = setTimeout(() => requestController.abort(),15000);
  try {
   const data = await window.wp.apiFetch({path:'/peptide/v1/track/admin/preferences?' + params,signal:controller.signal});
   if (destroyed || id !== generation) return;
   if (data.schema_version !== 1 || !data.summary || !Array.isArray(data.pages) || !Array.isArray(data.products) || !Array.isArray(data.modules)) throw Error('invalid');
   accepted = data; paint(data); exportButton.disabled = !!(data.coverage.schema_ready === false || data.coverage.partial || data.coverage.ranking_truncated);
  } catch (error) {
   if (!destroyed && id === generation ) { results.replaceChildren(); root.querySelector('.pc-insights-range').textContent = ''; status.textContent = '行为数据读取失败，请检查日期后点击刷新数据。'; }
  } finally { clearTimeout(timeout); if (!destroyed && id === generation) root.removeAttribute('aria-busy'); }
 }
 root.addEventListener('click', event => {
  const button = event.target.closest('button'); if (!button) return;
  if (button.hasAttribute('data-insight-period')) {
   visit = 0; period = button.dataset.insightPeriod; root.querySelectorAll('[data-insight-period]').forEach(b => b.setAttribute('aria-pressed',String(b === button))); refresh();
  } else if (button.hasAttribute('data-insight-retry')) refresh();
  else if (button === exportButton && accepted && !exportButton.disabled) {
   const url = URL.createObjectURL(new Blob([JSON.stringify(accepted,null,2)],{type:'application/json'}));
   const a = document.createElement('a'); a.href = url; a.download = 'behavior-' + period + '-' + date.value + '.json'; a.click(); setTimeout(() => URL.revokeObjectURL(url),1000);
  }
 });
 date.addEventListener('change',refresh);
 document.addEventListener('pc:visit-selected',event => {
  if (!subject || !Number.isSafeInteger(Number(event.detail)) || Number(event.detail)<1) return;
  visit = Number(event.detail); period = 'all';
  root.querySelectorAll('[data-insight-period]').forEach(b => b.setAttribute('aria-pressed',String(b.dataset.insightPeriod === 'all'))); refresh();
 });
 // ---------- AI 分析（2026-11-10：AI 和人设在总后台；这里只发起请求、显示结果） ----------
 const aiBox = root.querySelector('[data-ai]');
 const aiTitle = subject ? '客户分析 AI' : '全站分析师 AI';
 // AI 回答是 Markdown：表格、加粗标题、列表都排好版（店主 2026-11-10：原来表格显示成一串竖线）。
 function aiInline(t) { return esc(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>'); }
 function aiText(t) {
  const lines = String(t || '').replace(/\r/g, '').split('\n'); let html = '', i = 0;
  const cells = l => l.trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());
  while (i < lines.length) {
   const l = lines[i], tr = l.trim();
   if (!tr) { i++; continue; }
   if (/^\|/.test(tr)) {
    const rows = []; while (i < lines.length && /^\|/.test(lines[i].trim())) { rows.push(lines[i].trim()); i++; }
    const body = rows.filter(r => !/^\|?\s*:?-{2,}/.test(r.replace(/\|/g, '|').replace(/^\|\s*/, '')) || !/^[\s|:\-]+$/.test(r));
    const data = body.filter(r => !/^[\s|:\-]+$/.test(r));
    if (data.length) { html += '<div class="pc-ai-tablewrap"><table class="pc-ai-table"><thead><tr>' + cells(data[0]).map(c => '<th>' + aiInline(c) + '</th>').join('') + '</tr></thead><tbody>' + data.slice(1).map(r => '<tr>' + cells(r).map(c => '<td>' + aiInline(c) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>'; }
    continue;
   }
   if (/^#{1,4}\s/.test(tr) || /^\*\*[^*]+\*\*[：:]?$/.test(tr)) { html += '<h4 class="pc-ai-h">' + aiInline(tr.replace(/^#{1,4}\s*/, '').replace(/^\*\*|\*\*[：:]?$/g, '')) + '</h4>'; i++; continue; }
   if (/^[-*•]\s/.test(tr)) { let items = ''; while (i < lines.length && /^\s*[-*•]\s/.test(lines[i])) { const sub = /^\s{2,}/.test(lines[i]); items += '<li' + (sub ? ' class="is-sub"' : '') + '>' + aiInline(lines[i].trim().replace(/^[-*•]\s/, '')) + '</li>'; i++; } html += '<ul class="pc-ai-list">' + items + '</ul>'; continue; }
   if (/^\d+[.、)]\s?/.test(tr)) { let items = ''; while (i < lines.length && /^\s*\d+[.、)]\s?/.test(lines[i])) { items += '<li>' + aiInline(lines[i].trim().replace(/^\d+[.、)]\s?/, '')) + '</li>'; i++; } html += '<ol class="pc-ai-list">' + items + '</ol>'; continue; }
   if (/^-{3,}$/.test(tr)) { html += '<hr>'; i++; continue; }
   html += '<p>' + aiInline(tr) + '</p>'; i++;
  }
  return html;
 }
 // 数据图：KPI、漏斗（本期 / 上期）、渠道、商品、版块。一种蓝色，单系列不用图例；每根条有悬停说明。
 function aiNum(v, money, pct) { if (v === null || v === undefined || v === '') return '—'; const x = Number(v); if (isNaN(x)) return esc(String(v)); return (money || '') + x.toLocaleString('zh-CN', {maximumFractionDigits: pct ? 2 : 2}) + (pct ? '%' : ''); }
 function aiBars(title, rows, note) {
  if (!rows.length) return '';
  const max = Math.max(1, ...rows.map(r => Number(r.value) || 0));
  return '<section class="pc-ai-chart"><h5>' + esc(title) + '</h5>' + rows.map(r => '<div class="pc-ai-bar" title="' + esc(r.tip || (r.label + '：' + r.value)) + '"><span class="pc-ai-bar__label">' + esc(r.label) + '</span><span class="pc-ai-bar__track"><i style="width:' + Math.max(2, (Number(r.value) || 0) / max * 100).toFixed(1) + '%"></i></span><span class="pc-ai-bar__val">' + esc(r.text != null ? r.text : String(r.value)) + '</span></div>').join('') + (note ? '<p class="pc-ai-chart__note">' + esc(note) + '</p>' : '') + '</section>';
 }
 function aiCharts(c) {
  if (!c) return '';
  let html = '';
  if (c.kind === 'site') {
   html += '<div class="pc-ai-kpis">' + (c.kpis || []).map(k => { const money = (k.key === 'sales' || k.key === 'aov') ? (c.currency || '') : ''; const pct = k.key === 'conversion'; const d = (k.prev !== null && k.prev !== undefined && k.value !== null) ? Number(k.value) - Number(k.prev) : null; return '<div class="pc-ai-kpi"><span>' + esc(k.label) + '</span><b>' + aiNum(k.value, money, pct) + '</b><em class="' + (d > 0 ? 'is-up' : d < 0 ? 'is-down' : '') + '">' + (d === null ? '上期 —' : '上期 ' + aiNum(k.prev, money, pct) + (d ? (d > 0 ? ' ▲' : ' ▼') : '')) + '</em></div>'; }).join('') + '</div>';
   const cur = (c.funnel || {})['本期'] || {}, prev = (c.funnel || {})['上期'] || {}; const keys = Object.keys(cur);
   html += '<div class="pc-ai-charts">' + aiBars('转化漏斗（本期）', keys.map((k, i) => { const v = cur[k], p = i ? cur[keys[i - 1]] : null; const step = p ? Math.round(v / p * 1000) / 10 + '%' : ''; return {label: k, value: v, text: v + (step ? ' · ' + step : '') , tip: k + '：' + v + ' 人' + (step ? '，占上一步 ' + step : '') + '；上期 ' + (prev[k] ?? '—') + ' 人'}; }), '百分比 = 占上一步的比例；悬停看上期人数');
   html += aiBars('流量来源（访客）', (Array.isArray(c.channels) ? c.channels : []).map(r => ({label: r['渠道'], value: r['访客'], text: r['访客'] + ' 人 · ' + r['付款订单'] + ' 单', tip: r['渠道'] + '：' + r['访客'] + ' 位访客，' + r['付款订单'] + ' 笔付款，转化率 ' + (r['转化率%'] ?? 0) + '%'})));
   html += aiBars('商品热度（浏览 · 加购）', (c.products || []).map(p => ({label: p.name, value: p.views, text: p.views + ' 浏览 · ' + p.carts + ' 加购', tip: p.name + '：浏览 ' + p.views + ' 次，加购 ' + p.carts + ' 次，停留 ' + p.seconds + ' 秒'})));
   html += aiBars('版块点击', (c.modules || []).map(m => ({label: m.name, value: m.clicks, text: m.clicks + ' 次'}))) + '</div>';
  } else {
   const stages = ['只逛首页', '看了商品', '加了购物车', '进了结账', '下了单', '已付款'];
   html += '<div class="pc-ai-kpis"><div class="pc-ai-kpi"><span>走到哪一步</span><b>' + esc(stages[c.stage] || '—') + '</b></div><div class="pc-ai-kpi"><span>购买意向分</span><b>' + aiNum(c.intent) + '</b><em>满分 100</em></div><div class="pc-ai-kpi"><span>来访次数</span><b>' + aiNum(c.visits) + '</b></div><div class="pc-ai-kpi"><span>购物车</span><b>' + esc(c.cart || '—') + '</b></div></div>';
   html += '<div class="pc-ai-charts">' + aiBars('他感兴趣的商品（停留秒数）', (c.products || []).map(p => ({label: p.name, value: p.seconds, text: p.seconds + ' 秒' + (p.carts ? ' · 加购' : ''), tip: p.name + '：停留 ' + p.seconds + ' 秒，浏览 ' + p.views + ' 次' + (p.carts ? '，加购过' : '')}))) + aiBars('他点过的版块', (c.modules || []).map(m => ({label: m.name, value: m.clicks, text: m.clicks + ' 次'}))) + '</div>';
  }
  return html;
 }
 function aiShell(state, body) { aiBox.innerHTML = '<h3>' + aiTitle + '</h3><span>' + esc(state) + '</span>' + body; }
 async function aiStatus() {
  if (!aiBox) return;
  aiShell('正在检查…', '');
  let st;
  try { st = await window.wp.apiFetch({path:'/peptide/v1/track/admin/ai/status'}); } catch (e) { st = {enabled:false, error:(e && e.message) || ''}; }
  const persona = subject ? st.customer : st.business;
  if (!st.enabled) { aiShell('未开启', '<p>' + esc(st.error ? '暂时连不上总后台：' + st.error : '总后台“AI 设置”里还没有开启这家店的 AI 分析。') + '</p>'); return; }
  if (!persona) { aiShell('缺少人设', '<p>总后台“AI 设置”里还没有导入“' + (subject ? '客户分析' : '全站分析师') + '”的人设。</p>'); return; }
  aiShell('人设：' + persona, '<p>' + (subject ? '根据这位客户的浏览、加购和结账行为，分析他在看什么、有多大可能下单、该怎么跟进。' : '根据所选时间段全站访客的行为和经营数据，分析大家对什么感兴趣、问题出在哪、该怎么做。') + '</p><p class="pc-ai-actions"><button type="button" class="button button-primary" data-ai-run>AI 分析</button><span class="pc-ai-meta">今天已分析 ' + n(st.used_today) + ' 次</span></p><div class="pc-ai-out" aria-live="polite"></div>');
  // 已有的分析结果（自动或之前手动）打开页面就显示。
  try {
   const saved = await window.wp.apiFetch({path:'/peptide/v1/track/admin/ai/result?scope=' + (subject ? 'visitor&visitor_id=' + subject : 'site')});
   if (saved && saved.result) aiShow(saved.result, true);
  } catch (e) {}
  if (st.auto_customer && subject || st.auto_site && !subject) {
   const note = aiBox.querySelector('.pc-ai-meta');
   if (note) note.textContent += subject ? ' · 已开启自动分析（离开 30 分钟后自动分析）' : ' · 已开启自动分析（每天分析前一天）';
  }
 }
 function aiShow(r, saved) {
  const out = aiBox.querySelector('.pc-ai-out'); if (!out) return;
  const what = r.source === 'auto' ? '自动分析' : '手动分析';
  const span = !subject && r.period ? ({day:'日',week:'周',month:'月'}[r.period] || r.period) + '报 · ' + (r.date || '') + ' · ' : '';
  out.innerHTML = aiCharts(r.charts) + '<div class="pc-ai-text">' + aiText(r.text) + '</div><p class="pc-ai-meta">' + esc(span + what + ' · ' + r.at + (r.persona ? ' · 人设：' + r.persona : '') + (r.cached ? ' · 30 分钟内的结果' : '') + (!saved && r.used_today ? ' · 今天已分析 ' + r.used_today + ' 次' : '')) + ' <button type="button" class="button-link" data-ai-again>重新分析</button></p>';
  const run = aiBox.querySelector('[data-ai-run]'); if (run) run.textContent = '重新分析';
 }
 async function aiRun(refresh) {
  const out = aiBox.querySelector('.pc-ai-out'), btns = aiBox.querySelectorAll('[data-ai-run],[data-ai-again]');
  btns.forEach(b => { b.disabled = true; });
  out.innerHTML = '<p class="pc-ai-wait">AI 正在分析，大约需要 10–40 秒…</p>';
  try {
   const r = await window.wp.apiFetch({path:'/peptide/v1/track/admin/ai/analyse', method:'POST', data:{scope: subject ? 'visitor' : 'site', visitor_id: subject, visit_id: visit, period: period, date: date.value, refresh: refresh ? 1 : 0}});
   aiShow(r, false);
  } catch (e) {
   out.innerHTML = '<p class="pc-ai-error">' + esc((e && e.message) || '分析失败，请稍后再试。') + '</p>';
  }
  btns.forEach(b => { b.disabled = false; });
 }
 if (aiBox) {
  aiBox.addEventListener('click', e => { if (e.target.closest('[data-ai-run]')) aiRun(false); else if (e.target.closest('[data-ai-again]')) aiRun(true); });
  aiStatus();
 }
 window.addEventListener('pagehide',() => { destroyed = true; generation++; controller?.abort(); });
 window.addEventListener('pageshow',event => { if (event.persisted) { destroyed = false; refresh(); } });
 refresh();
}());
