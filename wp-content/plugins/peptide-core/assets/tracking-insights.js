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
 function aiText(t) { return esc(String(t || '')).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>'); }
 function aiShell(state, body) { aiBox.innerHTML = '<h3>' + aiTitle + '</h3><span>' + esc(state) + '</span>' + body; }
 async function aiStatus() {
  if (!aiBox) return;
  aiShell('正在检查…', '');
  let st;
  try { st = await window.wp.apiFetch({path:'/peptide/v1/track/admin/ai/status'}); } catch (e) { st = {enabled:false, error:(e && e.message) || ''}; }
  const persona = subject ? st.customer : st.business;
  if (!st.enabled) { aiShell('未开启', '<p>' + esc(st.error ? '暂时连不上总后台：' + st.error : '总后台“AI 设置”里还没有开启这家店的 AI 分析。') + '</p>'); return; }
  if (!persona) { aiShell('缺少人设', '<p>总后台“AI 设置”里还没有导入“' + (subject ? '客户分析' : '全站分析师') + '”的人设。</p>'); return; }
  aiShell('人设：' + persona, '<p>' + (subject ? '根据这位客户的浏览、加购和结账行为，分析他在看什么、有多大可能下单、该怎么跟进。' : '根据所选时间段全站访客的行为和经营数据，分析大家对什么感兴趣、问题出在哪、该怎么做。') + '</p><p class="pc-ai-actions"><button type="button" class="button button-primary" data-ai-run>AI 分析</button><span class="pc-ai-meta">今天已用 ' + n(st.used_today) + ' / ' + n(st.daily_cap) + ' 次</span></p><div class="pc-ai-out" aria-live="polite"></div>');
 }
 async function aiRun(refresh) {
  const out = aiBox.querySelector('.pc-ai-out'), btns = aiBox.querySelectorAll('[data-ai-run],[data-ai-again]');
  btns.forEach(b => { b.disabled = true; });
  out.innerHTML = '<p class="pc-ai-wait">AI 正在分析，大约需要 10–40 秒…</p>';
  try {
   const r = await window.wp.apiFetch({path:'/peptide/v1/track/admin/ai/analyse', method:'POST', data:{scope: subject ? 'visitor' : 'site', visitor_id: subject, visit_id: visit, period: period, date: date.value, refresh: refresh ? 1 : 0}});
   out.innerHTML = '<div class="pc-ai-text">' + aiText(r.text) + '</div><p class="pc-ai-meta">' + esc((r.persona ? '人设：' + r.persona + ' · ' : '') + (r.cached ? '30 分钟内的结果 · ' : '') + r.at + (r.daily_cap ? ' · 今天已用 ' + r.used_today + ' / ' + r.daily_cap + ' 次' : '')) + ' <button type="button" class="button-link" data-ai-again>重新分析</button></p>';
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
