'use strict';
const $ = selector => document.querySelector(selector);
const escapeText = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const mode = () => $('input[name=mode]:checked').value;
let config = null;
let previewVariant = 'A';
const isDirty = () => config && ($('#a-url').value.trim() !== config.aUrl || $('#b-url').value.trim() !== config.bUrl);
function requireSaved() {
  if (!config) throw Error('请等待配置加载完成。');
  if (isDirty()) throw Error('网址已修改，请先点击“保存网址”。');
}
function previewPage(variant) {
  requireSaved();
  previewVariant = variant;
  const url = new URL('preview/'+variant.toLowerCase(), config.entryUrl).href;
  $('#preview').src = url;
  $('#open-page').href = url;
  $('#preview-address').textContent = `预览 ${variant} · ${config[variant.toLowerCase()+'Url'] || '内置页面'}`;
  $('#version').textContent = variant;
  $('#decision-icon').textContent = variant;
  $('#decision-title').textContent = `正在预览 ${variant} 的已保存配置`;
  $('#decision-reason').textContent = '预览不新增访问记录；外部页面是否允许嵌入，由目标网站决定。';
  $('#visit-id').textContent = 'PREVIEW';
}
function applyConfig(data) {
  config = data;
  $('#a-url').value = data.aUrl;
  $('#b-url').value = data.bUrl;
  $('#admin-address').textContent = data.adminUrl;
  $('#entry-address').textContent = data.entryUrl;
  $('#entry-address').href = data.entryUrl;
  $('#entry-link').href = data.entryUrl;
  $('#lan-info').hidden = !data.lanEntryUrl;
  if (data.lanEntryUrl) {
    $('#lan-address').textContent = data.lanEntryUrl;
    $('#lan-address').href = data.lanEntryUrl;
  }
}
$('#url-form').addEventListener('submit', async event => {
  event.preventDefault();
  $('#url-fields').disabled = true;
  $('#config-error').hidden = true;
  $('#save-status').textContent = '正在保存…';
  try {
    const response = await fetch('/api/config', {method:'POST',headers:{'Content-Type':'application/json','X-Local-Demo':'1'},body:JSON.stringify({aUrl:$('#a-url').value,bUrl:$('#b-url').value})});
    const data = await response.json();
    if (!response.ok) throw Error(data.error || '保存失败。');
    applyConfig(data);
    $('#save-status').textContent = '已保存 · 入口下次访问立即生效';
    previewPage(previewVariant);
  } catch (error) {
    $('#config-error').hidden = false;
    $('#config-error').textContent = error.message;
    $('#save-status').textContent = '未保存';
  } finally {$('#url-fields').disabled = false;}
});
['#a-url','#b-url'].forEach(selector => $(selector).addEventListener('input', () => {
  $('#save-status').textContent = isDirty() ? '有修改尚未保存' : '当前为已保存配置';
}));
document.querySelectorAll('[data-preview]').forEach(button => button.addEventListener('click', () => {
  $('#config-error').hidden = true;
  try {previewPage(button.dataset.preview);}
  catch (error) {$('#config-error').hidden = false; $('#config-error').textContent = error.message;}
}));
function syncControls() {
  $('#role-settings').hidden = mode() !== 'role';
  $('#random-settings').hidden = mode() !== 'random';
  $('#device-settings').hidden = mode() !== 'device';
  const weight = Number($('#weight').value);
  $('#ratio').textContent = `A ${weight}% · B ${100-weight}%`;
}
document.querySelectorAll('input[name=mode]').forEach(input => input.addEventListener('change', syncControls));
$('#weight').addEventListener('input', syncControls);
async function refreshHistory() {
  const response = await fetch('/api/history');
  if (!response.ok) throw Error('读取记录失败，请检查本地服务。');
  const data = await response.json();
  $('#count-a').textContent = data.counts.A;
  $('#count-b').textContent = data.counts.B;
  $('#history').innerHTML = data.entries.length ? data.entries.map(entry => {
    const label = entry.mode === 'device' ? '自动设备分流' : (entry.mode === 'role' ? '模拟身份分流' : '随机 A/B 测试');
    const persona = entry.mode === 'device' ? (entry.persona === 'mobile' ? '手机' : '电脑 / 非手机') : (entry.mode === 'role' ? (entry.persona === 'reviewer' ? '模拟审核访客' : '模拟普通访客') : (entry.returning ? '同一浏览器再次访问' : '新访客'));
    return `<tr><td>#${entry.id}<span class="entry-time">${escapeText(new Date(entry.time).toLocaleTimeString('zh-CN',{hour12:false}))}</span></td><td>${label}<span class="history-sub">${persona}</span></td><td><span class="tag ${entry.variant === 'B' ? 'b' : ''}">${escapeText(entry.variant)}</span></td><td>${escapeText(entry.reason)}</td></tr>`;
  }).join('') : '<tr><td colspan="4" class="empty">暂无演示记录，发起第一次访问吧。</td></tr>';
}
function showError(error) {$('#error').hidden = false; $('#error').textContent = error.message;}
$('#run').addEventListener('click', async () => {
  $('#run').disabled = true; $('#error').hidden = true;
  try {
    requireSaved();
    const response = await fetch('/api/visit', {method:'POST',headers:{'Content-Type':'application/json','X-Local-Demo':'1'},body:JSON.stringify({mode:mode(),persona:$('#persona').value,weight:Number($('#weight').value),fresh:$('#fresh').checked})});
    const data = await response.json();
    if (!response.ok) throw Error(data.error || '演示访问失败。');
    $('#preview').src = data.previewUrl;
    previewVariant = data.variant;
    $('#open-page').href = data.mode === 'device' ? data.entryUrl : data.previewUrl;
    $('#preview-address').textContent = `${data.variant} · ${data.targetUrl || '内置页面'}`;
    $('#version').textContent = data.variant;
    $('#decision-icon').textContent = data.variant;
    $('#decision-title').textContent = `已分配 ${data.variant} 页面 · ${data.targetUrl ? '嵌入已保存网址' : '内置演示'}`;
    $('#decision-reason').textContent = data.reason;
    $('#visit-id').textContent = `VISIT #${data.id}`;
    $('#fresh').checked = false;
    await refreshHistory();
  } catch (error) {showError(error);}
  finally {$('#run').disabled = false;}
});
$('#refresh').addEventListener('click', () => refreshHistory().catch(showError));
syncControls();
fetch('/api/config').then(async response => {
  if (!response.ok) throw Error('配置加载失败，请刷新后台页面重试。');
  applyConfig(await response.json());
  $('#url-fields').disabled = false;
  $('#save-status').textContent = '配置已载入';
  previewPage('A');
}).catch(error => {$('#config-error').hidden = false; $('#config-error').textContent = error.message;});
refreshHistory().catch(showError);
fetch('/api/device').then(async response => {
  if (!response.ok) throw Error('读取设备信息失败，请确认服务已经更新。');
  const data = await response.json();
  $('#device-label').textContent = (data.device === 'mobile' ? '手机' : '电脑 / 非手机') + ' → ' + data.variant;
  $('#device-source').textContent = '判断依据：' + data.source;
}).catch(showError);
