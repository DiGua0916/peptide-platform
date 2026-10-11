'use strict';
const $ = selector => document.querySelector(selector);
const escapeText = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const mode = () => $('input[name=mode]:checked').value;
function syncControls() {
  $('#role-settings').hidden = mode() !== 'role';
  $('#random-settings').hidden = mode() !== 'random';
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
    const label = entry.mode === 'role' ? '模拟身份分流' : '随机 A/B 测试';
    const persona = entry.mode === 'role' ? (entry.persona === 'reviewer' ? '模拟审核访客' : '模拟普通访客') : (entry.returning ? '同一浏览器再次访问' : '新访客');
    return `<tr><td>#${entry.id}<span class="entry-time">${escapeText(new Date(entry.time).toLocaleTimeString('zh-CN',{hour12:false}))}</span></td><td>${label}<span class="history-sub">${persona}</span></td><td><span class="tag ${entry.variant === 'B' ? 'b' : ''}">${escapeText(entry.variant)}</span></td><td>${escapeText(entry.reason)}</td></tr>`;
  }).join('') : '<tr><td colspan="4" class="empty">暂无演示记录，发起第一次访问吧。</td></tr>';
}
function showError(error) {$('#error').hidden = false; $('#error').textContent = error.message;}
$('#run').addEventListener('click', async () => {
  $('#run').disabled = true; $('#error').hidden = true;
  try {
    const response = await fetch('/api/visit', {method:'POST',headers:{'Content-Type':'application/json','X-Local-Demo':'1'},body:JSON.stringify({mode:mode(),persona:$('#persona').value,weight:Number($('#weight').value),fresh:$('#fresh').checked})});
    const data = await response.json();
    if (!response.ok) throw Error(data.error || '演示访问失败。');
    $('#preview').src = data.previewUrl;
    $('#open-page').href = data.previewUrl;
    $('#preview-address').textContent = `本机 / pages / ${data.variant.toLowerCase()}.html`;
    $('#version').textContent = data.variant;
    $('#decision-icon').textContent = data.variant;
    $('#decision-title').textContent = `已进入 ${data.variant} 页面 · ${data.variant === 'A' ? '品牌介绍' : '产品目录'}`;
    $('#decision-reason').textContent = data.reason;
    $('#visit-id').textContent = `VISIT #${data.id}`;
    $('#fresh').checked = false;
    await refreshHistory();
  } catch (error) {showError(error);}
  finally {$('#run').disabled = false;}
});
$('#refresh').addEventListener('click', () => refreshHistory().catch(showError));
syncControls();
refreshHistory().catch(showError);
