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
 root.innerHTML = '<section class="pc-insights"><header class="pc-insights-head"><div><h2>' + (subject ? '客户完整访问记录' : '全站行为概览') + '</h2><p>查看保留期内的页面停留与行为明细</p></div><div class="pc-insights-actions"><label>统计日期<input type="date" name="insight_date" max="' + esc(cfg.today) + '" value="' + esc(cfg.today) + '"></label><button type="button" class="button" data-insight-export disabled>导出分析数据</button></div></header><div class="pc-insights-toolbar"><div class="pc-insights-period" role="group" aria-label="统计周期">' + (subject ? [['all','全部记录'],['day','每日'],['week','每周'],['month','每月']] : [['day','每日'],['week','每周'],['month','每月']]).map(([key,label]) => '<button type="button" data-insight-period="' + key + '" aria-pressed="' + (key === period) + '">' + label + '</button>').join('') + '</div><span class="pc-insights-range"></span><button type="button" class="button" data-insight-retry>刷新数据</button></div><div class="pc-insights-status" role="status" aria-live="polite"></div><div class="pc-insights-results"></div><section class="pc-interest-ai"><h3>' + (subject ? '客户分析 AI' : '全站分析 AI') + '</h3><span>尚未接入</span><p>' + (subject ? '独立客户人设，分析当前访客的兴趣与访问行为。' : '独立经营人设，分析全站流量、商品表现及真实成交数据。') + ' 日、周、月数据已准备好；接入服务后再开启自动分析。</p></section><details class="pc-insights-method"><summary>统计口径与 AI 分析</summary><p>停留为页面可见且活跃时，相邻信号间隔不超过 30 秒的观测时长；总时长去除同一访客多标签页重叠。没有信号的时间不补算，页面和商品时长不能直接相加。全部记录仅包括当前保留期内已采集的数据；已清理的历史无法恢复。点击下方访问记录可查看该次访问的页面统计和时间线，点击“全部记录”返回客户全程汇总。</p><p>一个访客编号对应一个浏览器，不能据此认定同一自然人。浏览和点击是兴趣线索，不代表购买意愿或个人属性。</p><p>AI 服务尚未接入，不会自动发送客户数据。可先导出日、周、月事实数据；接入后分别配置客户分析与全站经营分析。</p></details></section>';
 const results = root.querySelector('.pc-insights-results'), status = root.querySelector('.pc-insights-status'), date = root.querySelector('input'), exportButton = root.querySelector('[data-insight-export]');
 const labels = {'demo-catalog':'商品目录','carousel':'轮播','faq':'常见问题','media-text':'图文介绍','report-feature':'检测报告','product-grid':'商品区','footer':'页脚','header':'导航'};
 function table(title, columns, rows, empty) {
  return '<section class="pc-interest-section"><h3>' + title + '</h3>' + (rows.length && columns.length > 4 ? '<p class="pc-interest-scroll-hint">左右滑动可查看全部字段，含成功加购。</p>' : '') + (rows.length ? '<div class="pc-interest-table-wrap" tabindex="0" role="region" aria-label="' + title + '"><table><thead><tr>' + columns.map(x => '<th scope="col">' + x + '</th>').join('') + '</tr></thead><tbody>' + rows.map(row => '<tr>' + row.map((cell,i) => '<' + (i ? 'td' : 'th scope="row"') + '>' + cell + '</' + (i ? 'td' : 'th') + '>').join('') + '</tr>').join('') + '</tbody></table></div>' : '<p class="pc-interest-empty">' + empty + '</p>') + '</section>';
 }
 function paint(data) {
  const s = data.summary;
  results.innerHTML = '<dl class="pc-interest-summary">' + [['观测活跃时长',seconds(s.active_seconds)],['页面浏览次数',n(s.pageviews)],['访问次数',n(s.visits)],['观测事件',n(s.observed_events)]].map(([label,value]) => '<div><dt>' + label + '</dt><dd>' + value + '</dd></div>').join('') + '</dl><div class="pc-interest-columns">' +
   table('页面停留',['页面','浏览次数','观测活跃时长'],data.pages.map(x => [esc(x.path),n(x.views),seconds(x.active_seconds)]),'所选周期还没有页面观测。') +
   table('模块点击',['模块','点击次数'],data.modules.map(x => [esc(labels[x.module_type] || x.module_type || x.module_id) + '<small>' + esc(x.module_id) + '</small>',n(x.clicks)]),'所选周期尚未采集到模块点击，新采集开始后显示。') + '</div>' +
   table('商品兴趣',['商品','商品浏览','商品点击','观测活跃时长','成功加购'],data.products.map(x => [esc(x.name),n(x.views),n(x.clicks),seconds(x.active_seconds),n(x.cart_additions)]),'所选周期尚未采集到商品行为。');
  root.querySelector('.pc-insights-range').textContent = (data.period.retained_history ? (visit ? '所选访问 · ' : '全部保留记录 · ') : '') + data.period.from + ' 至 ' + data.period.to + ' · ' + data.period.timezone;
  status.textContent = data.coverage.schema_ready === false ? '此站点尚未初始化追踪，暂时没有可分析的记录。' : (data.coverage.partial || data.coverage.ranking_truncated) ? '事件超过单次读取上限，以下为部分数据，不适合作为完整周期结论。' : !data.coverage.tracking_enabled ? '当前追踪已关闭，以下仅为已有记录。' : '已更新。停留为观测值，缺失信号不补算。';
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
 window.addEventListener('pagehide',() => { destroyed = true; generation++; controller?.abort(); });
 window.addEventListener('pageshow',event => { if (event.persisted) { destroyed = false; refresh(); } });
 refresh();
}());
