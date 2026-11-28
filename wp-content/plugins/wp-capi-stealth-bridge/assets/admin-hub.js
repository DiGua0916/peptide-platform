/* WP CAPI Stealth Bridge V3.5 - Admin Hub, Smart Dual-Pane SKU Matcher & RoxyBrowser Bridge */
(function (w, d) {
  'use strict';

  var BLOCKED_SKU_RE = /(?:^|[^a-z])(?:\d+\s*(?:mg|mcg|iu|ml)|vial)(?:[^a-z]|$)/i;
  var SENSITIVE_WORDS = [
    'semaglutide', 'tirzepatide', 'retatrutide', 'cagrilintide', 'mazdutide',
    'survodutide', 'ipamorelin', 'tesamorelin', 'sermorelin', 'melanotan',
    'bpc-157', 'bpc157', 'tb-500', 'tb500', 'ghk-cu', 'lyophilized', 'pep-'
  ];

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function parseCsv(text) {
    var clean = String(text || '').replace(/^\uFEFF/, '');
    var rows = [];
    var row = [];
    var cur = '';
    var inQ = false;
    for (var i = 0; i < clean.length; i++) {
      var ch = clean[i];
      if (inQ) {
        if (ch === '"') {
          if (clean[i + 1] === '"') {
            cur += '"';
            i++;
          } else {
            inQ = false;
          }
        } else {
          cur += ch;
        }
      } else {
        if (ch === '"') {
          inQ = true;
        } else if (ch === ',' || ch === '\t') {
          row.push(cur.trim());
          cur = '';
        } else if (ch === '\n' || ch === '\r') {
          if (ch === '\r' && clean[i + 1] === '\n') i++;
          row.push(cur.trim());
          if (row.some(function (c) { return c !== ''; })) rows.push(row);
          row = [];
          cur = '';
        } else {
          cur += ch;
        }
      }
    }
    row.push(cur.trim());
    if (row.some(function (c) { return c !== ''; })) rows.push(row);
    return rows;
  }

  function checkSafeSkuViolation(code) {
    var s = String(code || '').trim();
    if (!s) return '脱敏货号为空';
    if (!/^[A-Za-z0-9._-]{1,120}$/.test(s)) return '包含非法字符（仅允许字母、数字、横杠、下划线、点）';
    if (BLOCKED_SKU_RE.test(s)) return '违规包含剂量单位（mg/mcg/ml/iu）或 vial 字样';
    var low = s.toLowerCase();
    for (var i = 0; i < SENSITIVE_WORDS.length; i++) {
      if (low.indexOf(SENSITIVE_WORDS[i]) !== -1) {
        return '违规包含高危敏感词：' + SENSITIVE_WORDS[i];
      }
    }
    return '';
  }

  function priceKey(num) {
    var n = Math.round(parseFloat(num || 0) * 100) / 100;
    return isNaN(n) ? '0.00' : n.toFixed(2);
  }

  function extractChineseRemark(raw) {
    var s = String(raw || '').trim();
    if (!s || s === '-') return '-';
    var lines = s.split(/\r\n|\r|\n/);
    for (var i = 0; i < lines.length; i++) {
      var ln = lines[i].trim();
      if (!ln) continue;
      ln = ln.split(/[|;@]|c_user=|https?:\/\/|\d{7,}/i)[0].trim();
      if (!/[\u4e00-\u9fff]/.test(ln)) continue;
      var m = ln.match(/^([\u4e00-\u9fff]+(?:[\s\-–—_·/+()（）]*[\u4e00-\u9fffA-Za-z0-9]{1,6}|[\s\-–—_·/+]*BM\d*)*)/i);
      if (m && m[1]) {
        var cleaned = m[1].replace(/^[\s\-–—_·/+]+|[\s\-–—_·/+]+$/g, '');
        if (cleaned) return cleaned;
      }
    }
    return '-';
  }

  function isTrashRoxyGroup(groupName) {
    var g = String(groupName || '').trim();
    var gl = g.toLowerCase();
    if (!g || g === '默认项目' || g === '默认分组' || gl === 'default project' || gl === 'default') {
      return true;
    }
    // 新管理方案：无主蓄水「缓冲池」不在战区作战台展示，哪里缺直接在 Roxy 挑出移入对应库即可
    if (g.indexOf('缓冲池') !== -1) {
      return true;
    }
    return false;
  }

  function sniffBmIdFromText(raw) {
    var s = String(raw || '');
    var m = s.match(/(?:BM\s*ID|BM编号|绿盾\s*BM|BM)\s*[：:=\-]?\s*(\d{8,24})/i);
    if (m && m[1]) return m[1];
    return '';
  }

  function formatFbtCode(sortNum) {
    if (sortNum === null || sortNum === undefined || sortNum === '') return '';
    var n = parseInt(sortNum, 10);
    if (isNaN(n) || n <= 0) return String(sortNum);
    return 'FBT-' + (n < 10 ? '0' + n : String(n));
  }

  function extractRoxyLabels(item) {
    var raw = (item && (item.labelInfo || item.labelList || item.tags)) || [];
    if (!Array.isArray(raw)) return [];
    var out = [];
    raw.forEach(function (lb) {
      if (!lb) return;
      if (typeof lb === 'string') {
        out.push({ name: lb.trim(), color: '' });
      } else if (typeof lb === 'object') {
        var nm = String(lb.labelName || lb.name || '').trim();
        if (nm) out.push({ name: nm, color: String(lb.labelColor || lb.color || '') });
      }
    });
    return out;
  }

  function buildRoxyProxyUrl(p) {
    if (!p || typeof p !== 'object') return '';
    var info = p.proxyInfo || p.proxy || p;
    var ip = info.ip || info.host || info.proxyHost || '';
    var port = info.port || info.proxyPort || '';
    if (!ip || !port) return '';
    var catRaw = String(info.proxyCategory || '').toLowerCase();
    var protoRaw = String(info.protocol || '').toLowerCase();
    if (catRaw === 'noproxy' && (!protoRaw || protoRaw === 'noproxy')) return '';
    var protoCand = (protoRaw && protoRaw !== 'noproxy') ? protoRaw : '';
    var catCand = (catRaw && catRaw !== 'noproxy') ? catRaw : '';
    var rawScheme = String(protoCand || catCand || info.proxyType || info.type || 'http').toLowerCase();
    var scheme = rawScheme.indexOf('socks') !== -1 ? 'socks5' : 'http';
    var user = info.proxyUserName || info.username || info.user || '';
    var pass = info.password || info.proxyPassword || '';
    var auth = user ? (encodeURIComponent(user) + (pass ? ':' + encodeURIComponent(pass) : '') + '@') : '';
    return scheme + '://' + auth + ip + ':' + port;
  }

  var ALL_FUNNEL_EVENTS = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase'];

  w.StealthHubUI = {
    syncBmIdRequired: function (formEl) {
      if (!formEl) return;
      var fBmId = formEl.querySelector('input[name="bm_id"]');
      var reqLbl = formEl.querySelector('.scapi-bmid-req-label');
      if (fBmId) {
        fBmId.required = false;
        fBmId.placeholder = '选填：6~24 位纯数字 BM 编号（外部邮箱户 / 二三不限户可留空）';
      }
      if (reqLbl) {
        reqLbl.style.color = '#334155';
        reqLbl.textContent = 'BM ID 备注（选填 · 外部户可留空）：';
      }
    },

    syncSlotRoleDefaults: function (formEl, applyRoleDefaults) {
      if (!formEl) return;
      this.syncBmIdRequired(formEl);
      if (!applyRoleDefaults) return;
      var fRole = formEl.querySelector('select[name="slot_role"]');
      var rawRole = fRole ? String(fRole.value || '').toLowerCase() : 'primary';
      var isShadow = (rawRole === 'shadow' || rawRole === 'standby');
      var evBoxes = formEl.querySelectorAll('input[name="enabled_events[]"]');
      for (var i = 0; i < evBoxes.length; i++) {
        evBoxes[i].checked = isShadow ? (evBoxes[i].value === 'Purchase') : true;
      }
      var fStrip = formEl.querySelector('input[name="strip_fbc"]');
      if (fStrip) fStrip.checked = isShadow;
      var fDMin = formEl.querySelector('input[name="delay_min_sec"]');
      var fDMax = formEl.querySelector('input[name="delay_max_sec"]');
      if (fDMin) fDMin.value = isShadow ? 15 : 0;
      if (fDMax) fDMax.value = isShadow ? 60 : 0;
    },

    openReplaceModalFromBtn: function (btnEl) {
      if (!btnEl) return;
      var raw = btnEl.getAttribute('data-slot-cfg') || '{}';
      var cfg = {};
      try { cfg = JSON.parse(raw); } catch (e) {}
      this.openReplaceModal(cfg);
    },

    openReplaceModal: function (siteKeyOrCfg, slotId, slotRole, currentLabel, currentDomain, minJitterMin, maxJitterMin, currentDatasetId, currentTestCode, hasExistingProxy, currentBmId) {
      var cfg = (siteKeyOrCfg && typeof siteKeyOrCfg === 'object') ? siteKeyOrCfg : {
        site_key: siteKeyOrCfg,
        slot_id: slotId,
        slot_role: slotRole,
        bm_label: currentLabel,
        event_domain: currentDomain,
        delay_min_sec: minJitterMin != null ? (parseInt(minJitterMin, 10) || 0) : 0,
        delay_max_sec: maxJitterMin != null ? (parseInt(maxJitterMin, 10) || 0) : 0,
        dataset_id: currentDatasetId,
        test_event_code: currentTestCode,
        has_proxy: !!hasExistingProxy,
        bm_id: currentBmId,
        is_edit: !!(currentDatasetId && String(currentDatasetId).trim() && String(currentDatasetId) !== '-')
      };
      var modal = d.getElementById('scapi-replace-modal');
      if (!modal) return;
      var titleEl = d.getElementById('scapi-replace-modal-title');
      var fSite = modal.querySelector('[name="site_key"]');
      var fSlot = modal.querySelector('[name="slot_id"]');
      var fRole = modal.querySelector('select[name="slot_role"]');
      var fBmId = modal.querySelector('input[name="bm_id"]');
      var fLabel = modal.querySelector('input[name="bm_label"]');
      var fDs = modal.querySelector('input[name="dataset_id"]');
      var fTok = modal.querySelector('input[name="access_token"]');
      var fPrx = modal.querySelector('input[name="egress_proxy"]');
      var fTest = modal.querySelector('input[name="test_event_code"]');
      var fEdit = modal.querySelector('input[name="is_edit_existing"]');
      var fDomain = modal.querySelector('input[name="event_domain"]');
      var fDMin = modal.querySelector('input[name="delay_min_sec"]');
      var fDMax = modal.querySelector('input[name="delay_max_sec"]');
      var fStrip = modal.querySelector('input[name="strip_fbc"]');
      var fActive = modal.querySelector('input[name="is_active"]');
      var isEditing = !!(cfg.is_edit || (cfg.dataset_id && String(cfg.dataset_id).trim() && String(cfg.dataset_id) !== '-'));
      var rawRole = String(cfg.slot_role || 'primary').toLowerCase();
      var normRole = (rawRole === 'shadow' || rawRole === 'standby') ? 'shadow' : 'primary';

      if (titleEl) {
        titleEl.textContent = isEditing
          ? ('修改槽位参数 / 更换消耗户或代理（' + (cfg.slot_id || '') + '）')
          : '新增独立像素槽位（主归属 primary / 旁路养模 shadow）';
      }
      if (fSite && cfg.site_key) fSite.value = cfg.site_key;
      if (fSlot) {
        var targetSlot = cfg.slot_id || 'slot_01';
        if (fSlot.tagName === 'SELECT') {
          var hasOpt = false;
          for (var oi = 0; oi < fSlot.options.length; oi++) {
            if (fSlot.options[oi].value === targetSlot) { hasOpt = true; break; }
          }
          if (!hasOpt) {
            var opt = d.createElement('option');
            opt.value = targetSlot;
            opt.textContent = targetSlot;
            fSlot.appendChild(opt);
          }
        }
        fSlot.value = targetSlot;
      }
      if (fRole) fRole.value = normRole;
      if (fBmId) fBmId.value = cfg.bm_id || sniffBmIdFromText(cfg.bm_label) || '';
      if (fLabel) fLabel.value = cfg.bm_label || '';
      if (fDs) fDs.value = isEditing ? String(cfg.dataset_id || '').trim() : '';
      if (fTest) fTest.value = cfg.test_event_code || '';
      if (fEdit) fEdit.value = isEditing ? '1' : '0';
      if (fTok) {
        fTok.value = '';
        fTok.required = !isEditing;
        fTok.placeholder = isEditing
          ? '留空则保留当前已加密保存的访问令牌不变；如需换号或更新令牌请粘贴新 EAA...'
          : '必填：请粘贴该槽位像素对应的 EAA... 访问令牌';
      }
      if (fPrx) {
        fPrx.value = '';
        fPrx.required = !(isEditing && cfg.has_proxy);
        fPrx.placeholder = (isEditing && cfg.has_proxy)
          ? '留空则保留当前独享静态代理不变；如需更换代理请填写新 URL（严禁与同域名其他活跃槽共用 IP:端口）'
          : '必填，格式：http://账号:密码@代理IP:端口 或 socks5://账号:密码@代理IP:端口';
      }
      if (fDomain && cfg.event_domain !== undefined) fDomain.value = cfg.event_domain || '';

      var evSet = {};
      var evList = Array.isArray(cfg.enabled_events) && cfg.enabled_events.length
        ? cfg.enabled_events
        : (normRole === 'shadow' ? ['Purchase'] : ALL_FUNNEL_EVENTS);
      for (var ei = 0; ei < evList.length; ei++) evSet[evList[ei]] = true;
      var evBoxes = modal.querySelectorAll('input[name="enabled_events[]"]');
      for (var bi = 0; bi < evBoxes.length; bi++) {
        evBoxes[bi].checked = !!evSet[evBoxes[bi].value];
      }
      if (fStrip) fStrip.checked = cfg.strip_fbc != null ? !!cfg.strip_fbc : (normRole === 'shadow');
      if (fDMin) fDMin.value = cfg.delay_min_sec != null ? cfg.delay_min_sec : (normRole === 'shadow' ? 15 : 0);
      if (fDMax) fDMax.value = cfg.delay_max_sec != null ? cfg.delay_max_sec : (normRole === 'shadow' ? 60 : 0);
      if (fActive) fActive.checked = cfg.is_active != null ? !!cfg.is_active : true;

      var formEl = modal.querySelector('form');
      this.syncBmIdRequired(formEl);
      if (formEl && !formEl._scapiSubmitBound) {
        formEl._scapiSubmitBound = true;
        formEl.addEventListener('submit', function (ev) {
          var bVal = String((formEl.querySelector('input[name="bm_id"]') || {}).value || '').replace(/\s+/g, '');
          if (bVal && !/^\d{6,24}$/.test(bVal)) {
            ev.preventDefault();
            w.alert('格式提示：若填写 BM ID 备注，请输入 6~24 位纯数字编号（外部户可直接留空）！');
            return;
          }
          var checkedEvs = formEl.querySelectorAll('input[name="enabled_events[]"]:checked');
          if (evBoxes.length > 0 && (!checkedEvs || checkedEvs.length === 0)) {
            ev.preventDefault();
            w.alert('请至少勾选 1 个需要转发的事件（例如 Purchase）！');
            return;
          }
          var subBtn = formEl.querySelector('button[type="submit"]');
          if (subBtn) {
            subBtn.disabled = true;
            subBtn.textContent = '保存提交中，请稍候...';
          }
        });
      }
      this.populateModalRoxySelect();
      modal.classList.add('open');
    },
    closeReplaceModal: function () {
      var modal = d.getElementById('scapi-replace-modal');
      if (modal) modal.classList.remove('open');
    },
    confirmDeleteSlot: function (slotLabel) {
      return w.confirm('确认彻底删除【' + slotLabel + '】吗？删除后该槽位将立即停止接收事件。');
    },
    confirmClearTestData: function (siteLabel, count) {
      var cntDesc = count > 0 ? ('已识别的 ' + count + ' 条测试回传记录') : '测试回传缓存记录';
      return w.confirm(
        '确认清空【' + siteLabel + '】' + cntDesc + '吗？\n\n' +
        '• 仅清除通过「测试链接 / 测试事件码(TESTxxxxx)」产生的测试流量与 $0 测试客户记录；\n' +
        '• 正式真实订单、真实客户池与在投槽位配置 100% 保留不受影响；\n' +
        '• 如需同时关闭测试事件码，请点槽位右侧「修改 / 换号」删空测试码后保存。'
      );
    },
    copyTestLink: function (url, btnEl) {
      var done = function () {
        if (!btnEl) return;
        var orig = btnEl.getAttribute('data-orig-label') || btnEl.textContent;
        btnEl.setAttribute('data-orig-label', orig);
        btnEl.textContent = '✅ 已复制链接';
        w.setTimeout(function () {
          btnEl.textContent = orig;
        }, 1800);
      };
      if (w.navigator && w.navigator.clipboard && w.navigator.clipboard.writeText) {
        w.navigator.clipboard.writeText(url).then(done).catch(function () {
          w.prompt('请复制以下专属测试链接：', url);
        });
      } else {
        w.prompt('请复制以下专属测试链接：', url);
      }
    },
    confirmPromote: function (formEl, isColdLocked, warmupDays, minDays, currentBmId) {
      var bmInput = formEl ? formEl.querySelector('input[name="bm_id"]') : null;
      var curBm = String(currentBmId || (bmInput && bmInput.value) || '').replace(/\s+/g, '');
      if (!curBm) {
        var entered = w.prompt('【在投户必填校验】晋升为 1号在投主槽前，必须填写该绿盾 BM 的纯数字 BMID：', '');
        if (entered === null) return false;
        curBm = String(entered).replace(/\s+/g, '');
        if (!/^\d{6,24}$/.test(curBm)) {
          w.alert('晋升已取消：绿盾 BMID 必须为 6~24 位纯数字编号！');
          return false;
        }
        if (bmInput) bmInput.value = curBm;
      }
      if (!isColdLocked) {
        return w.confirm('确认将该热备槽位（BMID: ' + curBm + '）晋升为当前战区的 1号主力投流槽位吗？');
      }
      var ok = w.confirm(
        '【七天冷置保护锁警告】该槽位当前仅静默预热 ' +
          warmupDays +
          ' 天（未满标准 ' +
          minDays +
          ' 天）！\n提前接户投流存在较高风控概率，是否确认以超级管理员权限强制突破冷置锁晋升？'
      );
      if (ok) {
        var forceInput = formEl.querySelector('input[name="force_override"]');
        if (forceInput) forceInput.value = '1';
      }
      return ok;
    },

    populateModalRoxySelect: function () {
      var sel = d.getElementById('scapi-modal-roxy-select');
      if (!sel) return;
      var cached = [];
      try {
        cached = JSON.parse(w.localStorage.getItem('scapi_roxy_profiles_cache') || '[]');
      } catch (e) {}
      cached = cached.filter(function (p) { return p && !isTrashRoxyGroup(p.groupName); });
      var html = '<option value="">-- 若已在「第5页 Roxy 作战台」连接，可在此一键选窗口自动填入编号、备注与静态代理 --</option>';
      for (var i = 0; i < cached.length; i++) {
        var p = cached[i];
        var fbt = formatFbtCode(p.sortNum);
        var cnRem = extractChineseRemark(p.remark);
        var label = (fbt ? fbt + ' ' : '') + '[' + p.groupName + '] ' + (p.name || p.dirId) + (cnRem !== '-' ? ' · ' + cnRem : '') + (p.proxyUrl ? ' (' + p.proxyMasked + ')' : ' (未配代理)');
        html += '<option value="' + escHtml(String(i)) + '">' + escHtml(label) + '</option>';
      }
      sel.innerHTML = html;
      sel.onchange = function () {
        var idx = parseInt(sel.value, 10);
        if (isNaN(idx) || !cached[idx]) return;
        var chosen = cached[idx];
        var modal = d.getElementById('scapi-replace-modal');
        if (!modal) return;
        var fLabel = modal.querySelector('input[name="bm_label"]');
        var fProxy = modal.querySelector('input[name="egress_proxy"]');
        var fBmId = modal.querySelector('input[name="bm_id"]');
        var fbt2 = formatFbtCode(chosen.sortNum);
        if (fLabel) {
          fLabel.value = (fbt2 ? fbt2 + '｜' : '') + (chosen.groupName ? chosen.groupName + '｜' : '') + (chosen.name || chosen.dirId);
        }
        if (fProxy && chosen.proxyUrl) {
          fProxy.value = chosen.proxyUrl;
        }
        if (fBmId && !fBmId.value && chosen.sniffedBmId) {
          fBmId.value = chosen.sniffedBmId;
        }
      };
    },

    /* ======================================================================
       Dual-Pane Drag-and-Drop Smart SKU Auto-Matcher
       ====================================================================== */
    initDualSkuMatcher: function () {
      var dropzone = d.getElementById('scapi-sku-dropzone');
      if (!dropzone) return;
      var fileInput = dropzone.querySelector('input[type="file"]');
      var storeItems = Array.isArray(w.SCAPI_STORE_PRODUCTS) ? w.SCAPI_STORE_PRODUCTS : [];

      function handleFiles(files) {
        if (!files || !files.length) return;
        var file = files[0];
        var titleEl = d.getElementById('scapi-drop-filename');
        if (titleEl) titleEl.textContent = '已载入文件：' + file.name + '（正在自动交叉核验...）';
        var reader = new FileReader();
        reader.onload = function (ev) {
          runSmartMatch(String(ev.target.result || ''), file.name, storeItems);
        };
        reader.readAsText(file, 'utf-8');
      }

      ['dragenter', 'dragover'].forEach(function (evName) {
        dropzone.addEventListener(evName, function (e) {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.add('dragover');
        });
      });
      ['dragleave', 'dragend', 'drop'].forEach(function (evName) {
        dropzone.addEventListener(evName, function (e) {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.remove('dragover');
        });
      });
      dropzone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files) handleFiles(e.dataTransfer.files);
      });
      if (fileInput) {
        fileInput.addEventListener('change', function () {
          handleFiles(fileInput.files);
        });
      }
    },

    /* ======================================================================
       RoxyBrowser Local API Bridge & 2+3天 SOP Lifecycle Tracker (Tab 5)
       ====================================================================== */
    initRoxyHub: function () {
      var root = d.getElementById('scapi-roxy-root');
      if (!root) return;
      var urlInput = d.getElementById('scapi-roxy-url');
      var keyInput = d.getElementById('scapi-roxy-key');
      var btnConn = d.getElementById('scapi-roxy-connect');
      var statusBox = d.getElementById('scapi-roxy-status');
      var wsContainer = d.getElementById('scapi-roxy-workspaces');
      var tblBody = d.getElementById('scapi-roxy-tbody');
      var tableWrap = d.getElementById('scapi-roxy-table-wrap');
      var zonesBoard = d.getElementById('scapi-roxy-zones-board');
      var zoneStrip = d.getElementById('scapi-zone-summary-strip');
      var deadDrawer = d.getElementById('scapi-roxy-dead-drawer');
      var btnViewCards = d.getElementById('scapi-view-cards-btn');
      var btnViewTable = d.getElementById('scapi-view-table-btn');
      var buyerFilter = d.getElementById('scapi-roxy-buyer-filter');
      var activeZoneFilter = 'ALL';
      var roxyViewMode = 'cards';
      var deadDrawerOpen = false;
      var zonesCatalog = Array.isArray(w.SCAPI_ZONES_CATALOG) && w.SCAPI_ZONES_CATALOG.length
        ? w.SCAPI_ZONES_CATALOG
        : [
            { site_key: 'site_03', zone_code: 'S1', buyer: '光辉', label: 'S1 · 光辉', domain: 'www.lumornea.com' },
            { site_key: 'site_04', zone_code: 'S2', buyer: '老毛', label: 'S2 · 老毛', domain: 'www.korgavex.com' },
            { site_key: 'site_05', zone_code: 'S3', buyer: '芒果', label: 'S3 · 芒果', domain: 'www.dalhoben.com' },
            { site_key: 'site_01', zone_code: 'S4', buyer: '李军', label: 'S4 · 李军', domain: 'www.sarnovea.com' },
            { site_key: 'site_02', zone_code: 'S5', buyer: '阳', label: 'S5 · 阳', domain: 'www.rovkazar.com' },
            { site_key: 'site_06', zone_code: 'S6', buyer: '地瓜', label: 'S6 · 地瓜', domain: 'www.vialcovepeptides.com' }
          ];

      // Body-level floating instant tooltip bubble (never clipped by table overflow)
      var tipEl = d.getElementById('scapi-floating-tip');
      if (!tipEl) {
        tipEl = d.createElement('div');
        tipEl.id = 'scapi-floating-tip';
        d.body.appendChild(tipEl);
        var moveTip = function (ev) {
          var trg = ev.target && ev.target.closest ? ev.target.closest('[data-tip]') : null;
          if (!trg) {
            tipEl.style.display = 'none';
            return;
          }
          var txt = trg.getAttribute('data-tip') || '';
          if (!txt) {
            tipEl.style.display = 'none';
            return;
          }
          if (tipEl.textContent !== txt) tipEl.textContent = txt;
          tipEl.style.display = 'block';
          var rect = trg.getBoundingClientRect();
          var tw = tipEl.offsetWidth || 260;
          var th = tipEl.offsetHeight || 60;
          var left = Math.max(10, Math.min(rect.left, w.innerWidth - tw - 14));
          var top = rect.top - th - 8;
          if (top < 10) top = rect.bottom + 8;
          tipEl.style.left = left + 'px';
          tipEl.style.top = top + 'px';
        };
        d.addEventListener('mouseover', moveTip);
        d.addEventListener('mousemove', moveTip);
        d.addEventListener('scroll', function () { tipEl.style.display = 'none'; }, true);
      }

      var savedCfg = {};
      try {
        savedCfg = JSON.parse(w.localStorage.getItem('scapi_roxy_cfg') || '{}');
      } catch (e) {}
      var serverSaved = w.SCAPI_ROXY_SAVED || {};
      var sopStateMap = (w.SCAPI_ROXY_SOP_STATE && typeof w.SCAPI_ROXY_SOP_STATE === 'object') ? w.SCAPI_ROXY_SOP_STATE : {};
      var initProbeWrap = (w.SCAPI_ROXY_PROBE_CACHE && typeof w.SCAPI_ROXY_PROBE_CACHE === 'object') ? w.SCAPI_ROXY_PROBE_CACHE : {};
      var probeMap = (initProbeWrap.probes && typeof initProbeWrap.probes === 'object') ? initProbeWrap.probes : {};
      var btnProbe = d.getElementById('scapi-roxy-probe-btn');
      var isProbingNow = false;
      var initUrl = savedCfg.url || serverSaved.url || '';
      var initKey = savedCfg.key || serverSaved.key || '';
      if (urlInput && initUrl) urlInput.value = initUrl;
      if (keyInput && initKey) keyInput.value = initKey;

      var allProfiles = [];
      var activeWorkspaceId = '';
      var isPointerDownInTable = false;
      var deferredRenderTimer = null;

      function findProfileByDirId(dirId) {
        if (!dirId) return null;
        for (var i = 0; i < allProfiles.length; i++) {
          if (String(allProfiles[i].dirId) === String(dirId)) return allProfiles[i];
        }
        return null;
      }

      function hasRoxyTag(p, kw) {
        var lbs = (p && Array.isArray(p.labels)) ? p.labels : [];
        var low = String(kw || '').toLowerCase();
        for (var i = 0; i < lbs.length; i++) {
          if (String(lbs[i].name || '').toLowerCase().indexOf(low) !== -1) return true;
        }
        return false;
      }

      function classifyCombatRole(p) {
        if (!p) return 'hot';
        var g = String(p.groupName || '').trim();
        var nm = String(p.name || '').trim();
        var prInfo = probeMap[p.dirId] || null;
        var entry = sopStateMap[p.dirId] || {};
        if (g === '窗口转移' || hasRoxyTag(p, '已挂') || entry.alive_override === 'dead' || (prInfo && prInfo.status === 'dead')) {
          return 'dead';
        }
        if (nm.indexOf('【保') !== -1 || /^保[\-_\s(（]/.test(nm) || g.indexOf('保号') !== -1 || hasRoxyTag(p, '保号')) {
          return 'guard';
        }
        if (g.indexOf('辅跑') !== -1 || g.indexOf('辅助') !== -1 || nm.indexOf('【辅') !== -1 || /^辅(?:跑|助)?[\-_\s(（]/.test(nm) || hasRoxyTag(p, '辅跑') || hasRoxyTag(p, '辅助')) {
          return 'aux';
        }
        if (nm.indexOf('【主') !== -1 || hasRoxyTag(p, '投放中') || (g.indexOf('🚀') !== -1 && !hasRoxyTag(p, '静置') && nm.indexOf('【热') === -1)) {
          return 'main';
        }
        return 'hot';
      }

      function matchZoneFromText(txt) {
        var s = String(txt || '').trim();
        if (!s) return '';
        if (s.indexOf('光辉') !== -1) return 'S1';
        if (s.indexOf('老毛') !== -1) return 'S2';
        if (s.indexOf('芒果') !== -1) return 'S3';
        if (s.indexOf('李军') !== -1 || s.indexOf('军儿') !== -1) return 'S4';
        if (s.indexOf('阳') !== -1) return 'S5';
        if (s.indexOf('地瓜') !== -1) return 'S6';
        var mCode = s.match(/(?:^|[^A-Za-z0-9])ss?([1-6])(?:[^0-9]|$)|站\s*([1-6])/i);
        if (mCode) {
          var idx = mCode[1] || mCode[2];
          if (idx) return 'S' + idx;
        }
        return '';
      }

      function classifyCombatZone(p) {
        if (!p) return 'UNASSIGNED';
        // 优先级 1：Roxy 标签最高优先（支持 `辅跑-芒果`、`芒果`、`ss3` 等，直接覆盖旧窗口名里的残留编号如 `_S4_`）
        var lbs = Array.isArray(p.labels) ? p.labels : [];
        for (var i = 0; i < lbs.length; i++) {
          var zFromTag = matchZoneFromText(lbs[i] && lbs[i].name);
          if (zFromTag) return zFromTag;
        }
        // 优先级 2：Roxy 所属项目/分组名（如 `辅跑-芒果`、`23-热备库-站3-芒果`）
        var zFromGroup = matchZoneFromText(p.groupName);
        if (zFromGroup) return zFromGroup;

        // 优先级 3：窗口名称兜底（仅当标签和项目名都未指定投手时才读取窗口名里的 S1~S6 或投手名）
        var zFromName = matchZoneFromText(p.name);
        if (zFromName) return zFromName;
        return 'UNASSIGNED';
      }

      function findZoneMetaByCode(zCode) {
        for (var i = 0; i < zonesCatalog.length; i++) {
          if (zonesCatalog[i].zone_code === zCode) return zonesCatalog[i];
        }
        return null;
      }

      function findGatewaySlotsForSite(siteKey) {
        var gatewaySlots = Array.isArray(w.SCAPI_ALL_SLOTS) ? w.SCAPI_ALL_SLOTS : [];
        var res = { primary: null, standby: null };
        for (var i = 0; i < gatewaySlots.length; i++) {
          var gs = gatewaySlots[i];
          if (gs.site_key !== siteKey || !(gs.is_active !== false && gs.is_enabled !== false && gs.enabled !== false)) continue;
          var rl = String(gs.slot_role || gs.role || '').toLowerCase();
          if (rl === 'primary' && !res.primary) res.primary = gs;
          else if ((rl === 'shadow' || rl === 'standby') && !res.standby) res.standby = gs;
        }
        return res;
      }

      function resolveWindowBmId(p, matchedSlot, zonePrimSlot, cRole) {
        if (matchedSlot && matchedSlot.bm_id) return String(matchedSlot.bm_id).trim();
        var entry = (p && sopStateMap[p.dirId]) ? sopStateMap[p.dirId] : {};
        if (entry && entry.bm_id) return String(entry.bm_id).trim();
        if (cRole === 'main' && zonePrimSlot && zonePrimSlot.bm_id) return String(zonePrimSlot.bm_id).trim();
        if (p && p.sniffedBmId) return String(p.sniffedBmId).trim();
        return '';
      }

      function findNextFreeSlotForSite(siteKey, preferSecondary) {
        var gatewaySlots = Array.isArray(w.SCAPI_ALL_SLOTS) ? w.SCAPI_ALL_SLOTS : [];
        var used = {};
        for (var i = 0; i < gatewaySlots.length; i++) {
          var gs = gatewaySlots[i];
          if (gs && gs.site_key === siteKey && gs.slot_id) {
            used[gs.slot_id] = true;
          }
        }
        var order = preferSecondary
          ? ['slot_02', 'slot_03', 'slot_01', 'slot_04']
          : ['slot_01', 'slot_02', 'slot_03', 'slot_04'];
        for (var j = 0; j < order.length; j++) {
          if (!used[order[j]]) return order[j];
        }
        return preferSecondary ? 'slot_03' : 'slot_01';
      }

      function openAssignModalForProfile(p, triggerBtn) {
        if (!p) return;
        function proceedOpen() {
          if (!p.proxyUrl) {
            w.alert('拦截提示：该 Roxy 窗口尚未配置静态住宅代理！请先在 RoxyBrowser 为该窗口绑定独享静态住宅 IP 后再下发至槽位。');
            return;
          }
          var cRole = classifyCombatRole(p);
          if (cRole === 'guard') {
            if (!w.confirm('🛡️ 操作确认：该窗口是【同BM守护保号】，平时与在投主号共管同一个绿盾 BM，无需单独挂载像素槽位。\n\n仅当原在投主号个号被封、需改用本保号接管「1号主力槽」时才需下发，确定继续吗？')) {
              return;
            }
          }
          var curEntry = sopStateMap[p.dirId] || {};
          var mSlot = findMatchedSlotForProfile(p);
          var curBmSt = inferBmStatus(p, curEntry, mSlot);
          if (curBmSt === 'bm_restricted') {
            if (!w.confirm('🚨 风险提醒：该窗口当前标记为【BM已挂 / 受限红标】，强烈不建议下发死 BM！确定仍要继续吗？')) {
              return;
            }
          }
          var assignModal = d.getElementById('scapi-roxy-assign-modal');
          if (!assignModal) return;

          var zCode = classifyCombatZone(p);
          var zMeta = findZoneMetaByCode(zCode);
          var siteSelect = assignModal.querySelector('select[name="site_key"]');
          if (siteSelect && zMeta) {
            for (var si = 0; si < siteSelect.options.length; si++) {
              if (siteSelect.options[si].value === zMeta.site_key) {
                siteSelect.selectedIndex = si;
                break;
              }
            }
          }
          var effSiteKey = (siteSelect && siteSelect.value) ? siteSelect.value : (zMeta ? zMeta.site_key : 'site_03');

          var slotInput = assignModal.querySelector('[name="slot_id"]');
          var roleSelect = assignModal.querySelector('select[name="slot_role"]');
          var isMainTarget = (cRole === 'main' || cRole === 'guard');
          var isAuxTarget = (cRole === 'aux');
          var targetSlotId = 'slot_01';
          var targetRoleVal = 'primary';
          if (mSlot && mSlot.slot_id) {
            targetSlotId = mSlot.slot_id;
            var exRl = String(mSlot.slot_role || mSlot.role || 'primary').toLowerCase();
            targetRoleVal = (exRl === 'shadow' || exRl === 'standby') ? 'shadow' : 'primary';
          } else if (isAuxTarget) {
            targetSlotId = findNextFreeSlotForSite(effSiteKey, true);
            targetRoleVal = 'primary';
          } else if (isMainTarget) {
            targetSlotId = 'slot_01';
            targetRoleVal = 'primary';
          } else {
            targetSlotId = findNextFreeSlotForSite(effSiteKey, true);
            targetRoleVal = 'primary';
          }
          if (slotInput) slotInput.value = targetSlotId;
          if (roleSelect) roleSelect.value = targetRoleVal;

          var gwPair = zMeta ? findGatewaySlotsForSite(zMeta.site_key) : { primary: null, standby: null };
          var targetExistingSlot = mSlot || (isMainTarget ? gwPair.primary : null);
          var fEdit = assignModal.querySelector('input[name="is_edit_existing"]');
          var fDs = assignModal.querySelector('input[name="dataset_id"]');
          var fTok = assignModal.querySelector('input[name="access_token"]');
          var fTest = assignModal.querySelector('input[name="test_event_code"]');
          var fBmId = assignModal.querySelector('input[name="bm_id"]');

          var resolvedBm = resolveWindowBmId(p, mSlot, gwPair.primary, cRole);
          if (fBmId) fBmId.value = resolvedBm;

          if (targetExistingSlot && targetExistingSlot.dataset_id) {
            if (fEdit) fEdit.value = '1';
            if (fDs) fDs.value = String(targetExistingSlot.dataset_id);
            if (fTest) fTest.value = targetExistingSlot.test_event_code || '';
            if (fTok) {
              fTok.value = '';
              fTok.required = false;
              fTok.placeholder = '留空则保留当前槽位已加密保存的访问令牌不变；如需更换令牌请粘贴新 EAA...';
            }
          } else {
            if (fEdit) fEdit.value = '0';
            if (fDs) fDs.value = '';
            if (fTest) fTest.value = '';
            if (fTok) {
              fTok.value = '';
              fTok.required = true;
              fTok.placeholder = 'EAA... 访问令牌（必填）';
            }
          }

          var fbt = formatFbtCode(p.sortNum);
          var cnTag = (p.remark && p.remark !== '-') ? ('｜' + p.remark) : '';
          assignModal.querySelector('input[name="bm_label"]').value = (fbt ? fbt + '｜' : '') + (p.groupName ? p.groupName + '｜' : '') + (p.name || p.dirId) + cnTag;
          assignModal.querySelector('input[name="egress_proxy"]').value = p.proxyUrl;
          var formEl = assignModal.querySelector('form');
          w.StealthHubUI.syncSlotRoleDefaults(formEl, !targetExistingSlot);
          assignModal.classList.add('open');
        }

        if (!p.proxyUrl && p.detailPending && p.workspaceId && p.dirId) {
          if (triggerBtn) {
            triggerBtn.disabled = true;
            triggerBtn.textContent = '读取代理...';
          }
          roxyFetch('/browser/detail?workspaceId=' + encodeURIComponent(p.workspaceId) + '&dirId=' + encodeURIComponent(p.dirId), 'GET')
            .then(function (dRes) {
              var drows = (dRes && dRes.data && (dRes.data.rows || dRes.data.list)) || [];
              var det = drows[0] || (dRes && dRes.data && !Array.isArray(dRes.data) ? dRes.data : null);
              if (det && det.proxyInfo) {
                var pUrl = buildRoxyProxyUrl(det);
                var pi = det.proxyInfo || {};
                var h = pi.ip || pi.host || '';
                var pt = pi.port || '';
                var sch = pUrl ? pUrl.split('://')[0] : '';
                p.proxyUrl = pUrl;
                p.proxyHost = h;
                p.proxyPort = String(pt || '');
                p.proxyMasked = (pUrl && h && pt) ? (sch + '://***@' + h + ':' + pt) : '';
              }
              p.detailPending = false;
            })
            .catch(function () { p.detailPending = false; })
            .finally(function () {
              if (triggerBtn) {
                triggerBtn.disabled = false;
                triggerBtn.textContent = '下发至槽位';
              }
              proceedOpen();
            });
          return;
        }
        proceedOpen();
      }

      root.addEventListener('pointerdown', function (ev) {
        if (ev.target && ev.target.closest && (ev.target.closest('#scapi-roxy-zones-board') || ev.target.closest('#scapi-roxy-tbody'))) {
          isPointerDownInTable = true;
          w.setTimeout(function () {
            isPointerDownInTable = false;
          }, 400);
        }
      });
      root.addEventListener('click', function (ev) {
        var zoneChip = ev.target && ev.target.closest ? ev.target.closest('[data-zone-filter]') : null;
        if (zoneChip) {
          ev.preventDefault();
          activeZoneFilter = zoneChip.getAttribute('data-zone-filter') || 'ALL';
          renderProfiles();
          return;
        }
        var deadBtn = ev.target && ev.target.closest ? ev.target.closest('[data-toggle-dead-drawer]') : null;
        if (deadBtn) {
          ev.preventDefault();
          deadDrawerOpen = !deadDrawerOpen;
          renderProfiles();
          return;
        }
        var assignBtn = ev.target && ev.target.closest ? ev.target.closest('[data-roxy-assign-dir]') : null;
        if (assignBtn) {
          ev.preventDefault();
          var prof = findProfileByDirId(assignBtn.getAttribute('data-roxy-assign-dir'));
          if (prof) openAssignModalForProfile(prof, assignBtn);
          return;
        }
        var sopCell = ev.target && ev.target.closest ? ev.target.closest('[data-roxy-sop-dir]') : null;
        if (sopCell) {
          var sProf = findProfileByDirId(sopCell.getAttribute('data-roxy-sop-dir'));
          if (sProf) openSopEditModal(sProf);
        }
      });

      if (btnViewCards && btnViewTable) {
        btnViewCards.onclick = function () {
          roxyViewMode = 'cards';
          btnViewCards.classList.add('active');
          btnViewTable.classList.remove('active');
          if (zonesBoard) zonesBoard.style.display = 'block';
          if (tableWrap) tableWrap.style.display = 'none';
          renderProfiles();
        };
        btnViewTable.onclick = function () {
          roxyViewMode = 'table';
          btnViewTable.classList.add('active');
          btnViewCards.classList.remove('active');
          if (zonesBoard) zonesBoard.style.display = 'none';
          if (tableWrap) tableWrap.style.display = 'block';
          renderProfiles();
        };
      }

      ['scapi-roxy-assign-modal'].forEach(function (mid) {
        var mEl = d.getElementById(mid);
        var fEl = mEl ? mEl.querySelector('form') : null;
        if (fEl) {
          fEl.addEventListener('submit', function (ev) {
            var bVal = String((fEl.querySelector('input[name="bm_id"]') || {}).value || '').replace(/\s+/g, '');
            if (bVal && !/^\d{6,24}$/.test(bVal)) {
              ev.preventDefault();
              w.alert('格式提示：若填写 BM ID 备注，请输入 6~24 位纯数字编号（外部户可直接留空）！');
              return;
            }
            var sBtn = fEl.querySelector('button[type="submit"]');
            if (sBtn) {
              sBtn.textContent = '⏳ 正在加密下发至网关槽位...';
              sBtn.style.opacity = '0.75';
            }
          });
        }
      });

      function parseBjTimeMs(tsStr) {
        var s = String(tsStr || '').trim();
        if (!s || s === '0' || s.indexOf('1970') === 0) return 0;
        var iso = s.indexOf('T') !== -1 ? s : s.replace(' ', 'T');
        if (!/[+-]\d{2}:?\d{2}$|Z$/i.test(iso)) iso += '+08:00';
        var ms = Date.parse(iso);
        return isNaN(ms) ? 0 : ms;
      }

      function fmtBjShort(ms) {
        if (!ms || ms <= 0) return '-';
        var dt = new Date(ms + 8 * 3600000);
        var mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
        var dd = String(dt.getUTCDate()).padStart(2, '0');
        var hh = String(dt.getUTCHours()).padStart(2, '0');
        var mi = String(dt.getUTCMinutes()).padStart(2, '0');
        return mm + '-' + dd + ' ' + hh + ':' + mi;
      }

      function fmtRelativeAgo(ms, nowMs) {
        if (!ms || ms <= 0) return '';
        var diffMin = Math.max(0, Math.floor((nowMs - ms) / 60000));
        if (diffMin < 2) return '刚刚';
        if (diffMin < 60) return diffMin + '分钟前';
        var diffH = Math.floor(diffMin / 60);
        if (diffH < 48) return diffH + '小时前';
        return Math.floor(diffH / 24) + '天前';
      }

      function abbreviateWindowName(rawName) {
        var s = String(rawName || '').trim();
        if (!s) return '-';
        var m = s.match(/^(\[[^\]]+\][_\-]?[^_\-\s]{1,6})/);
        if (m && m[1] && s.length > m[1].length) {
          return m[1] + '…';
        }
        return s.length > 13 ? (s.slice(0, 12) + '…') : s;
      }

      function abbreviateProxyHost(host) {
        var h = String(host || '').trim();
        if (!h) return '';
        var parts = h.split('.');
        if (parts.length === 4) {
          return '..' + parts[2] + '.' + parts[3];
        }
        return h.length > 10 ? ('..' + h.slice(-9)) : h;
      }

      function toDatetimeLocalVal(tsStr) {
        var ms = parseBjTimeMs(tsStr);
        if (!ms || ms <= 0) return '';
        var dt = new Date(ms + 8 * 3600000);
        var yy = dt.getUTCFullYear();
        var mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
        var dd = String(dt.getUTCDate()).padStart(2, '0');
        var hh = String(dt.getUTCHours()).padStart(2, '0');
        var mi = String(dt.getUTCMinutes()).padStart(2, '0');
        return yy + '-' + mm + '-' + dd + 'T' + hh + ':' + mi;
      }

      function nowBjDatetimeLocal() {
        var dt = new Date(Date.now() + 8 * 3600000);
        var yy = dt.getUTCFullYear();
        var mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
        var dd = String(dt.getUTCDate()).padStart(2, '0');
        var hh = String(dt.getUTCHours()).padStart(2, '0');
        var mi = String(dt.getUTCMinutes()).padStart(2, '0');
        return yy + '-' + mm + '-' + dd + 'T' + hh + ':' + mi;
      }

      function findMatchedSlotForProfile(p) {
        if (!p) return null;
        var cRole = classifyCombatRole(p);
        if (cRole === 'guard' || cRole === 'dead') return null;
        var zCode = classifyCombatZone(p);
        var zMeta = findZoneMetaByCode(zCode);
        var gatewaySlots = Array.isArray(w.SCAPI_ALL_SLOTS) ? w.SCAPI_ALL_SLOTS : [];
        var fbt = formatFbtCode(p.sortNum);
        for (var s = 0; s < gatewaySlots.length; s++) {
          var gs = gatewaySlots[s];
          if (zMeta && gs.site_key !== zMeta.site_key) continue;
          var lbl = String(gs.bm_label || '');
          if ((fbt && lbl.indexOf(fbt) !== -1) || (p.name && lbl.indexOf(p.name) !== -1) || (p.dirId && lbl.indexOf(p.dirId) !== -1) || (p.proxyHost && String(gs.egress_proxy_masked || '').indexOf(p.proxyHost) !== -1)) {
            return gs;
          }
        }
        return null;
      }

      function inferBmStatus(p, entry, matchedSlot) {
        var ov = (entry && entry.bm_status_override) ? String(entry.bm_status_override) : 'auto';
        if (ov && ov !== 'auto') {
          return ov; // 'unknown' | 'no_bm' | 'has_bm_ok' | 'bm_restricted'
        }
        if (matchedSlot) {
          var cir = String(matchedSlot.circuit_state || 'CLOSED').toUpperCase();
          return cir.indexOf('OPEN') !== -1 ? 'bm_restricted' : 'has_bm_ok';
        }
        if (hasRoxyTag(p, '已挂')) {
          return 'bm_restricted';
        }
        if (hasRoxyTag(p, '投放中') || hasRoxyTag(p, '保号') || hasRoxyTag(p, '72h静置BM') || hasRoxyTag(p, '还差广告户') || hasRoxyTag(p, '三不限')) {
          return 'has_bm_ok';
        }
        var gName = String((p && p.groupName) || '');
        if (gName.indexOf('🚀') !== -1 || gName.indexOf('热备库') !== -1 || gName.indexOf('保号库') !== -1) {
          return 'has_bm_ok';
        }
        var stg = (entry && entry.stage_override) ? String(entry.stage_override) : 'auto';
        if (entry && entry.bm_bound_time) {
          return 'has_bm_ok';
        }
        if (stg === '4_warmup_bm' || stg === '5_ready_fire' || stg === '6_golden' || stg === '7_active') {
          return 'has_bm_ok';
        }
        var combinedTxt = String((p && p.name) || '') + ' ' + String((p && p.remark) || '');
        if (/(BM\s*(?:已挂|已封|受限|红标|红了|阵亡)|死\s*BM)/i.test(combinedTxt)) {
          return 'bm_restricted';
        }
        if (/(无\s*BM|没\s*BM|空个号|纯个号|\[\d{0,4}\s*接\s*BM\])/i.test(combinedTxt)) {
          return 'no_bm';
        }
        if (/(\[\d{0,4}\s*(?:点火|黄金|接替)|已接\s*BM|有\s*BM|有绿盾|带\s*BM)/i.test(combinedTxt)) {
          return 'has_bm_ok';
        }
        if (String((p && p.groupName) || '').indexOf('在投') !== -1) {
          return 'has_bm_ok';
        }
        // 严禁按“满 46 小时”盲猜已接 BM！凡未明确标记且未绑槽位者一律返回 unknown
        return 'unknown';
      }

      function computeSopRowView(p, matchedSlot, ipCounts) {
        var nowMs = Date.now();
        var entry = sopStateMap[p.dirId] || {};
        var openMs = parseBjTimeMs(p.openTime);
        var closeMs = parseBjTimeMs(p.closeTime);
        var createMs = parseBjTimeMs(p.createTime);
        var firstOpenStr = entry.first_open_time || '';
        var firstMs = parseBjTimeMs(firstOpenStr);
        if (!firstMs && openMs > 0) {
          firstMs = (createMs > 0 && (openMs - createMs) > 20 * 3600000) ? createMs : openMs;
        }
        var isDupIp = !!(p.proxyHost && (ipCounts[p.proxyHost] || 0) > 1);
        var isOpenNow = (p.openStatus === true || p.openStatus === 1 || p.openStatus === '1');
        var isInFlightGroup = String(p.groupName || '').indexOf('在投') !== -1;
        var resolvedBmStatus = inferBmStatus(p, entry, matchedSlot);

        // 1.4 FB 个人号存活状态（Graph 公开头像端点 + 独享静态代理真实验活）
        var aliveOv = entry.alive_override || 'auto';
        var prInfo = probeMap[p.dirId] || null;
        var prStatus = prInfo ? String(prInfo.status || '') : '';
        var prTail = (prInfo && prInfo.uid_tail) ? ('尾号' + prInfo.uid_tail) : '';
        var prCheckedMs = (prInfo && prInfo.checked_at) ? (parseInt(prInfo.checked_at, 10) * 1000) : 0;
        var aliveBadge = '';
        var aliveStateKey = 'alive';
        if (aliveOv === 'dead') {
          aliveStateKey = 'dead';
          aliveBadge = '<span class="scapi-badge err scapi-sop-click"><span class="scapi-dot red"></span>个号已封</span>';
        } else if (aliveOv === 'warn') {
          aliveStateKey = 'warn';
          aliveBadge = '<span class="scapi-badge warn scapi-sop-click"><span class="scapi-dot yellow"></span>风控待解</span>';
        } else if (aliveOv === 'alive') {
          aliveBadge = isOpenNow
            ? '<span class="scapi-badge ok scapi-sop-click"><span class="scapi-dot pulse"></span>人工验活·在线</span>'
            : '<span class="scapi-badge ok scapi-sop-click"><span class="scapi-dot green"></span>人工点检存活</span>';
        } else {
          if (!p.proxyUrl) {
            aliveStateKey = 'err_proxy';
            aliveBadge = '<span class="scapi-badge err scapi-sop-click"><span class="scapi-dot red"></span>缺代理禁开</span>';
          } else if (prStatus === 'dead') {
            aliveStateKey = 'dead';
            aliveBadge = '<span class="scapi-badge err scapi-sop-click"><span class="scapi-dot red"></span>个号已封(速换)</span>';
          } else if (prStatus === 'proxy_err') {
            aliveStateKey = 'warn';
            aliveBadge = '<span class="scapi-badge warn scapi-sop-click"><span class="scapi-dot yellow"></span>静态代理断网</span>';
          } else if (prStatus === 'no_uid') {
            aliveStateKey = 'wait';
            aliveBadge = '<span class="scapi-badge neutral scapi-sop-click"><span class="scapi-dot gray"></span>待注册无UID</span>';
          } else if (prStatus === 'alive') {
            aliveBadge = isOpenNow
              ? '<span class="scapi-badge ok scapi-sop-click"><span class="scapi-dot pulse"></span>主页存活·在线</span>'
              : '<span class="scapi-badge ok scapi-sop-click"><span class="scapi-dot green"></span>主页存活(已验)</span>';
          } else if (isProbingNow) {
            aliveStateKey = 'wait';
            aliveBadge = '<span class="scapi-badge neutral scapi-sop-click"><span class="scapi-dot gray"></span>Graph验活中…</span>';
          } else {
            aliveStateKey = 'wait';
            aliveBadge = '<span class="scapi-badge neutral scapi-sop-click"><span class="scapi-dot gray"></span>待静默验活</span>';
          }
        }
        var aliveTip = '【FB 个人号 Graph 公开头像无痕验活】（点击可校准）\n' +
          '• 说明：仅测 FB 个人号主页死活（0 碰登录 Cookie）；BM 有无与死活请看右侧「BM与像素状态」列\n' +
          (prInfo
            ? ('• 探活结论：' + (prInfo.detail || prStatus) + (prTail ? ' (' + prTail + ')' : '') + '\n' +
               '• 最近验活：' + (prCheckedMs ? fmtBjShort(prCheckedMs) + ' (' + fmtRelativeAgo(prCheckedMs, nowMs) + ')' : '-'))
            : '• 尚未完成 Graph 公开端点验活，点击上方「⚡ 一键静默验活」即刻检测') + '\n' +
          '• 当前窗口态：' + (isOpenNow ? '🟢 正在打开运行中' : '已安全关窗同步') +
          (isDupIp ? '\n⚠️ 警告：该静态代理 IP 被多个窗口重复绑定！' : '');

        // 1.1 最后上号时间 & 1.3 当前账号进度（兼容 Roxy 偶发限流未返回 openTime 时自动回退使用已存历史锚点，绝不误卡在「待首登」）
        var stgOv = entry.stage_override || 'auto';
        var customNextStr = entry.next_time_override || '';
        var customNextMs = customNextStr ? parseBjTimeMs(customNextStr.replace('T', ' ') + ':00') : 0;

        var lockedBmMs = parseBjTimeMs(entry.bm_bound_time || '');
        var effectiveOpenMs = openMs || lockedBmMs || firstMs;

        var lastOpenHtml = p.detailPending
          ? '<span style="color:#94a3b8;font-size:12px;">读取中...</span>'
          : '<span style="color:#94a3b8;font-size:12px;">尚未上号</span>';
        if (openMs > 0) {
          lastOpenHtml = '<div class="scapi-time-main">' + escHtml(fmtBjShort(openMs)) + '</div>' +
            '<div class="scapi-time-sub">' + escHtml(isOpenNow ? '当前在线中' : fmtRelativeAgo(openMs, nowMs)) + '</div>';
        } else if (effectiveOpenMs > 0) {
          lastOpenHtml = '<div class="scapi-time-main">' + escHtml(fmtBjShort(effectiveOpenMs)) + '</div>' +
            '<div class="scapi-time-sub">' + escHtml(fmtRelativeAgo(effectiveOpenMs, nowMs)) + '</div>';
        }

        var stageBadge = '';
        var nextOpenHtml = '';
        var baseAnchor = firstMs || createMs || effectiveOpenMs || nowMs;
        var t24 = baseAnchor + 24 * 3600000;
        var t48 = baseAnchor + 48 * 3600000;
        var bmStartMs = lockedBmMs || ((effectiveOpenMs >= baseAnchor + 20 * 3600000) ? effectiveOpenMs : (effectiveOpenMs || t48));
        var isBmTimerLocked = !!lockedBmMs;
        var tBm72 = bmStartMs + 72 * 3600000;
        var t168 = baseAnchor + 168 * 3600000;
        var ageH = (nowMs - baseAnchor) / 3600000;

        var lastOpenTip = '最后开窗上号：' + (p.openTime || (effectiveOpenMs ? fmtBjShort(effectiveOpenMs) + ' (历史已记录)' : '尚未打开过')) + '\n' +
          '最后关窗同步：' + (p.closeTime || '-') + '\n' +
          '建窗入库时间：' + (p.createTime || '-') + '\n' +
          '首登起算基准：' + (firstMs ? fmtBjShort(firstMs) : '待首登后自动记录') +
          (lockedBmMs ? ('\n🔒 已锁死接BM时间：' + fmtBjShort(lockedBmMs) + '（中途开窗看一眼不会冲掉 72h 计时）') : '');

        var resolvedStage = stgOv;
        if (resolvedStage === '4_warmup_bm' && isInFlightGroup && matchedSlot && (nowMs - bmStartMs) >= 72 * 3600000) {
          resolvedStage = '7_active';
        }
        if (resolvedStage === 'auto') {
          if (aliveStateKey === 'dead') {
            resolvedStage = '0_dead';
          } else if (resolvedBmStatus === 'bm_restricted') {
            resolvedStage = 'bm_dead';
          } else if (isInFlightGroup && matchedSlot) {
            resolvedStage = '7_active';
          } else if (!effectiveOpenMs && aliveOv !== 'alive' && resolvedBmStatus !== 'has_bm_ok') {
            resolvedStage = '1_wait_first';
          } else if (resolvedBmStatus !== 'has_bm_ok') {
            // 未确认有 BM 前，绝不按小时数自动假装跳入「④ 养BM静置」！
            if (ageH < 48) {
              resolvedStage = '2_warmup_acct';
            } else if (resolvedBmStatus === 'no_bm') {
              resolvedStage = '3_wait_bm';
            } else {
              resolvedStage = '3_check_bm';
            }
          } else {
            var bmAgeH = (nowMs - bmStartMs) / 3600000;
            if (bmAgeH < 72) {
              resolvedStage = '4_warmup_bm';
            } else if (isInFlightGroup) {
              resolvedStage = '7_active';
            } else if (ageH < 168) {
              resolvedStage = '5_ready_fire';
            } else {
              resolvedStage = '6_golden';
            }
          }
        }

        if (resolvedStage === '0_dead') {
          stageBadge = '<span class="scapi-badge err scapi-sop-click">⛔ 已废弃清退</span>';
          nextOpenHtml = '<span style="color:#dc2626;font-size:12px;font-weight:600;">禁止开窗·停IP</span>';
        } else if (resolvedStage === 'bm_dead') {
          stageBadge = '<span class="scapi-badge err scapi-sop-click">⚠️ BM受限·待换BM</span>';
          nextOpenHtml = '<span style="color:#dc2626;font-size:12px;font-weight:600;">禁下发投流·换BM</span>';
        } else if (resolvedStage === '7_active') {
          stageBadge = '<span class="scapi-badge purple scapi-sop-click">⑦ 🔥 前线在投中</span>';
          if (isOpenNow) {
            nextOpenHtml = '<div class="scapi-time-main" style="color:#16a34a;">🟢 当前在线作业</div><div class="scapi-time-sub">04:00前关窗同步</div>';
          } else {
            var bjNow = new Date(nowMs + 8 * 3600000);
            var bjHour = bjNow.getUTCHours();
            var today16Ms = Date.UTC(bjNow.getUTCFullYear(), bjNow.getUTCMonth(), bjNow.getUTCDate(), 8, 0, 0); // 16:00 BJ = 08:00 UTC
            if (bjHour < 16) {
              nextOpenHtml = '<div class="scapi-time-main">今日 16:00</div><div class="scapi-time-sub">开班开机三检</div>';
            } else if (openMs >= today16Ms - 2 * 3600000) {
              nextOpenHtml = '<div class="scapi-time-main">明日 16:00</div><div class="scapi-time-sub">今日已巡检下机</div>';
            } else {
              nextOpenHtml = '<span class="scapi-time-due">⏰ 今日16:00已到班</span>';
            }
          }
        } else if (resolvedStage === '1_wait_first') {
          stageBadge = '<span class="scapi-badge neutral scapi-sop-click">① 待首登验IP</span>';
          nextOpenHtml = '<span class="scapi-time-due">⏰ 立即错峰首登</span><div class="scapi-time-sub">首登每窗隔15~20分</div>';
        } else if (resolvedStage === '2_warmup_acct') {
          var dIdx = Math.min(2, Math.max(1, Math.floor((nowMs - baseAnchor) / (24 * 3600000)) + 1));
          stageBadge = '<span class="scapi-badge primary scapi-sop-click">② 养个号 (Day ' + dIdx + '/2)</span>';
          var checked24 = (openMs >= baseAnchor + 18 * 3600000);
          if (nowMs < t24 && !checked24) {
            nextOpenHtml = '<div class="scapi-time-main">' + escHtml(fmtBjShort(t24)) + '</div><div class="scapi-time-sub">24h存活微刷60秒</div>';
          } else if (nowMs < t48) {
            nextOpenHtml = '<div class="scapi-time-main">' + escHtml(fmtBjShort(t48)) + '</div><div class="scapi-time-sub">满48h接绿盾BM</div>';
          } else {
            nextOpenHtml = '<span class="scapi-time-due">⏰ 满48h·请接BM</span>';
          }
        } else if (resolvedStage === '3_check_bm') {
          stageBadge = '<span class="scapi-badge warn scapi-sop-click">③ 待确认有无BM</span>';
          nextOpenHtml = '<div class="scapi-time-main" style="color:#b45309;">🔎 点此标记有无BM</div><div class="scapi-time-sub">或隔2~3分看一眼窗口</div>';
        } else if (resolvedStage === '3_wait_bm') {
          stageBadge = '<span class="scapi-badge warn scapi-sop-click">③ 满48h·待接BM</span>';
          nextOpenHtml = '<span class="scapi-time-due">⏰ 请上号接绿盾BM</span><div class="scapi-time-sub">已达标 ' + escHtml(fmtBjShort(t48)) + '</div>';
        } else if (resolvedStage === '4_warmup_bm') {
          var bmH = Math.max(0, Math.floor((nowMs - bmStartMs) / 3600000));
          var lockIcon = isBmTimerLocked ? ' 🔒' : '';
          if (bmH >= 72 && stgOv === '4_warmup_bm') {
            stageBadge = '<span class="scapi-badge ok scapi-sop-click">⑤ 满72h·待踢旧管</span>';
          } else {
            stageBadge = '<span class="scapi-badge purple scapi-sop-click">④ 养BM静置 (' + Math.min(72, bmH) + 'h/72h)' + lockIcon + '</span>';
          }
          if (nowMs < tBm72) {
            nextOpenHtml = '<div class="scapi-time-main">🔒 ' + escHtml(fmtBjShort(tBm72)) + '</div><div class="scapi-time-sub">满72h踢旧管验收</div>';
          } else {
            nextOpenHtml = '<span class="scapi-time-due">⏰ 满72h·踢旧管理员</span>';
          }
        } else if (resolvedStage === '5_ready_fire') {
          var kicked = (openMs >= tBm72 - 2 * 3600000) || stgOv === '5_ready_fire';
          if (!kicked) {
            stageBadge = '<span class="scapi-badge warn scapi-sop-click">⑤ 满2+3天·待踢旧管</span>';
            nextOpenHtml = '<span class="scapi-time-due">⏰ 请上号踢旧管</span><div class="scapi-time-sub">随后可挂户点火</div>';
          } else {
            stageBadge = '<span class="scapi-badge ok scapi-sop-click">⑤ ⚡ 2+3达标可点火</span>';
            nextOpenHtml = '<div class="scapi-time-main">' + escHtml(fmtBjShort(t168)) + '</div><div class="scapi-time-sub">满7天晋升黄金态</div>';
          }
        } else {
          stageBadge = '<span class="scapi-badge ok scapi-sop-click">⑥ 🟢 黄金热备就绪</span>';
          nextOpenHtml = '<div class="scapi-time-main" style="color:#16a34a;">随时可换防上阵</div><div class="scapi-time-sub">出库前24h点检存活</div>';
        }

        if (customNextMs > 0 && !(isInFlightGroup && matchedSlot && nowMs >= customNextMs + 24 * 3600000)) {
          if (nowMs >= customNextMs) {
            nextOpenHtml = '<span class="scapi-time-due">⏰ ' + escHtml(fmtBjShort(customNextMs)) + ' 已到点</span><div class="scapi-time-sub">人工指定计划点</div>';
          } else {
            nextOpenHtml = '<div class="scapi-time-main">📌 ' + escHtml(fmtBjShort(customNextMs)) + '</div><div class="scapi-time-sub">人工指定上号时间</div>';
          }
        }

        // 1.5 BM与像素状态胶囊（点击即可一键标记 ⚪纯个号无BM / 🟢有BM正常 / 🔴BM已挂）
        var bmPixelHtml = '';
        var bmPixelTip = '';
        if (matchedSlot) {
          var cirState = String(matchedSlot.circuit_state || 'CLOSED').toUpperCase();
          var slotTag = escHtml(matchedSlot.site_label + '·' + matchedSlot.slot_id);
          if (cirState === 'CLOSED' && resolvedBmStatus !== 'bm_restricted') {
            bmPixelHtml = '<span class="scapi-badge ok scapi-sop-click"><span class="scapi-dot green"></span>BM像素正常 (' + slotTag + ')</span>';
            bmPixelTip = '【已绑定网关槽位 · 实时监控中】\n• 绑定槽位：' + matchedSlot.site_label + ' (' + matchedSlot.slot_id + ')\n• 网关令牌回路：🟢 正常连通（一旦 BM 被封或令牌失效将自动熔断亮红灯）\n• 点击可手动校准状态';
          } else {
            bmPixelHtml = '<span class="scapi-badge err scapi-sop-click"><span class="scapi-dot red"></span>BM受限熔断 (' + slotTag + ')</span>';
            bmPixelTip = '🚨【BM / 像素受限警报】\n• 绑定槽位：' + matchedSlot.site_label + ' (' + matchedSlot.slot_id + ')\n• 状态：网关调用 Meta 接口触发授权失效熔断或已被人工标记受限，请立即核查换号！';
          }
        } else if (resolvedBmStatus === 'bm_restricted') {
          bmPixelHtml = '<span class="scapi-badge err scapi-sop-click"><span class="scapi-dot red"></span>BM已挂(禁投)</span>';
          bmPixelTip = '🚨 该窗口已标记为【BM已挂 / 受限红标】（未绑槽位）\n• 请勿下发投流，点击可修改标记';
        } else if (resolvedBmStatus === 'has_bm_ok') {
          bmPixelHtml = '<span class="scapi-badge ok scapi-sop-click"><span class="scapi-dot green"></span>已接BM(未绑槽)</span>';
          bmPixelTip = '🟢 该窗口已确认【内含可用 BM】（尚未分配至网关槽位）\n' +
            (lockedBmMs ? ('• 🔒 接BM起算时间已锁死：' + fmtBjShort(lockedBmMs) + '（中途开窗看一眼不会清零 72h 静养）\n') : '') +
            '• 点击可调整 BM 状态或起算时间';
        } else if (resolvedBmStatus === 'no_bm') {
          bmPixelHtml = '<span class="scapi-badge neutral scapi-sop-click"><span class="scapi-dot gray"></span>纯个号·无BM</span>';
          bmPixelTip = '⚪ 该窗口当前标记为【纯个人号 · 尚未接 BM】\n• 若后续已接管绿盾 BM，点击此胶囊切换为「🟢 有BM·正常存活」即可启动并锁死 72h 静养倒计时';
        } else if (ageH < 48 && openMs > 0) {
          bmPixelHtml = '<span class="scapi-badge neutral scapi-sop-click"><span class="scapi-dot gray"></span>养个号期(未接BM)</span>';
          bmPixelTip = '⚪ 首登未满 48h 稳号期（SOP 规定前 48h 暂不接 BM）\n• 若买来就是带 BM 一体号，可点击此胶囊手动标为「🟢 有BM·正常存活」';
        } else {
          bmPixelHtml = '<span class="scapi-badge warn scapi-sop-click"><span class="scapi-dot yellow"></span>❓待确认有无BM</span>';
          bmPixelTip = '❓ 外部无法在不碰 Cookie 的前提下透视未绑槽窗口里有没有 BM：\n' +
            '• 若买的是常规小辣椒/耐用号/三解且未接过 BM → 直接点击此处标为「⚪ 纯个号·无BM」\n' +
            '• 若不确定 → 在 Roxy 开窗看 30 秒 business.facebook.com（隔 2~3 分钟看一个，不改设置绝不破坏静养），回来点击此处标清即可！';
        }

        var namePlanMatch = String(p.name || '').match(/^\[(\d{4}[^\]]*)\]/);
        var planLine = namePlanMatch ? ('• 窗口命名计划标：[' + namePlanMatch[1] + ']\n') : '';
        var sopTip = '【2天养号 + 3天养BM SOP 时间轴】（点击可校准有无BM与进度）\n' +
          planLine +
          '• 首登起算点：' + (firstMs ? fmtBjShort(firstMs) : '待完成首登') + '\n' +
          '• +24h 存活微刷：' + (firstMs ? fmtBjShort(t24) : '-') + '\n' +
          '• +48h 接绿盾BM：' + (firstMs ? fmtBjShort(t48) : '-') + '\n' +
          (resolvedBmStatus === 'has_bm_ok'
            ? ('• 🔒 接BM锁死起点：' + fmtBjShort(bmStartMs) + '\n• +72h 踢旧管验收：' + fmtBjShort(tBm72) + '\n')
            : '• +72h 踢旧管验收：待确认接管 BM 后自动锁死起算\n') +
          '• 满 7 天黄金解锁：' + (firstMs ? fmtBjShort(t168) : '-');

        return {
          isDupIp: isDupIp,
          resolvedBmStatus: resolvedBmStatus,
          firstOpenAutoStr: (!entry.first_open_time && firstMs > 0 && openMs > 0) ? p.openTime : '',
          aliveBadge: aliveBadge,
          aliveTip: aliveTip,
          stageBadge: stageBadge,
          sopTip: sopTip,
          lastOpenHtml: lastOpenHtml,
          lastOpenTip: lastOpenTip,
          nextOpenHtml: nextOpenHtml,
          bmPixelHtml: bmPixelHtml,
          bmPixelTip: bmPixelTip
        };
      }

      function setStatus(type, msg) {
        if (!statusBox) return;
        statusBox.className = 'scapi-diag-box ' + type;
        statusBox.innerHTML = msg;
        statusBox.style.display = 'block';
      }

      function roxyFetch(path, method, bodyObj) {
        var baseUrl = (urlInput ? urlInput.value : 'http://127.0.0.1:50055').trim().replace(/\/+$/, '');
        var apiKey = (keyInput ? keyInput.value : '').trim();
        w.localStorage.setItem('scapi_roxy_cfg', JSON.stringify({ url: baseUrl, key: apiKey }));
        var reqMethod = (method || 'GET').toUpperCase();
        var hdrs = {
          'token': apiKey
        };
        if (bodyObj) {
          hdrs['Content-Type'] = 'application/json';
        }
        var opts = {
          method: reqMethod,
          headers: hdrs
        };
        if (bodyObj) opts.body = JSON.stringify(bodyObj);

        function viaCloudRelay() {
          if (!w.SCAPI_ROXY_RELAY || !w.SCAPI_ROXY_RELAY.ajaxUrl) {
            return Promise.reject(new Error('Failed to fetch'));
          }
          var fd = new w.FormData();
          fd.append('action', 'scapi_roxy_browser_relay');
          fd.append('_ajax_nonce', w.SCAPI_ROXY_RELAY.nonce || '');
          fd.append('roxy_path', path);
          fd.append('roxy_method', reqMethod);
          fd.append('roxy_token', apiKey);
          if (bodyObj) fd.append('roxy_body', JSON.stringify(bodyObj));
          return w.fetch(w.SCAPI_ROXY_RELAY.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
          }).then(function (r) { return r.json(); }).then(function (wrap) {
            if (!wrap || !wrap.success) {
              throw new Error((wrap && wrap.data && wrap.data.message) ? wrap.data.message : '本机桥接助手尚未响应');
            }
            return wrap.data.roxy;
          });
        }

        return w.fetch(baseUrl + path, opts)
          .then(function (r) { return r.json(); })
          .catch(function () { return viaCloudRelay(); });
      }

      function fetchSingleProfileDetail(prof, allowRetry, newFirstAnchors, newBmAnchors) {
        if (!prof || !prof.workspaceId || !prof.dirId) return Promise.resolve();
        var urlPath = '/browser/detail?workspaceId=' + encodeURIComponent(prof.workspaceId) + '&dirId=' + encodeURIComponent(prof.dirId);
        return roxyFetch(urlPath, 'GET')
          .then(function (dRes) {
            var drows = (dRes && dRes.data && (dRes.data.rows || dRes.data.list)) || [];
            var det = drows[0] || (dRes && dRes.data && !Array.isArray(dRes.data) ? dRes.data : null);
            if ((!det || typeof det !== 'object' || (dRes && dRes.code !== undefined && dRes.code !== 0 && dRes.code !== 200)) && allowRetry) {
              return new Promise(function (resolve) { w.setTimeout(resolve, 360); }).then(function () {
                return fetchSingleProfileDetail(prof, false, newFirstAnchors, newBmAnchors);
              });
            }
            if (det && typeof det === 'object') {
              if (det.openTime) prof.openTime = String(det.openTime);
              if (det.closeTime) prof.closeTime = String(det.closeTime);
              if (det.openStatus !== undefined) prof.openStatus = det.openStatus;
              if (det.createTime) prof.createTime = String(det.createTime);
              if (!prof.proxyUrl && det.proxyInfo) {
                var pUrl = buildRoxyProxyUrl(det);
                var pi = det.proxyInfo || {};
                var h = pi.ip || pi.host || '';
                var pt = pi.port || '';
                var sch = pUrl ? pUrl.split('://')[0] : '';
                prof.proxyUrl = pUrl;
                prof.proxyHost = h;
                prof.proxyPort = String(pt || '');
                prof.proxyMasked = (pUrl && h && pt) ? (sch + '://***@' + h + ':' + pt) : '';
              }
              var existing = sopStateMap[prof.dirId] || {};
              if (!existing.first_open_time && prof.openTime) {
                var oMs = parseBjTimeMs(prof.openTime);
                var cMs = parseBjTimeMs(prof.createTime);
                var anchorStr = (cMs > 0 && oMs > 0 && (oMs - cMs) > 20 * 3600000) ? prof.createTime : prof.openTime;
                if (anchorStr) {
                  if (!sopStateMap[prof.dirId]) sopStateMap[prof.dirId] = {};
                  sopStateMap[prof.dirId].first_open_time = anchorStr;
                  if (newFirstAnchors) newFirstAnchors[prof.dirId] = anchorStr;
                }
              }
              var curEntry = sopStateMap[prof.dirId] || {};
              var mSlot = findMatchedSlotForProfile(prof);
              var bmSt = inferBmStatus(prof, curEntry, mSlot);
              if (bmSt === 'has_bm_ok' && !curEntry.bm_bound_time && (prof.openTime || prof.createTime)) {
                var bmAnchor = prof.openTime || prof.createTime;
                if (!sopStateMap[prof.dirId]) sopStateMap[prof.dirId] = {};
                sopStateMap[prof.dirId].bm_bound_time = bmAnchor;
                if (newBmAnchors) newBmAnchors[prof.dirId] = bmAnchor;
              }
            }
            prof.detailPending = false;
          })
          .catch(function () {
            prof.detailPending = false;
          });
      }

      var currentSopModalProfile = null;
      function openSopEditModal(p) {
        var modal = d.getElementById('scapi-roxy-sop-modal');
        if (!modal || !p) return;
        currentSopModalProfile = p;
        var entry = sopStateMap[p.dirId] || {};
        var cRole = classifyCombatRole(p);
        var zMeta = findZoneMetaByCode(classifyCombatZone(p));
        var gwPair = zMeta ? findGatewaySlotsForSite(zMeta.site_key) : { primary: null, standby: null };
        var mSlot = findMatchedSlotForProfile(p);
        var curBmId = resolveWindowBmId(p, mSlot, gwPair.primary, cRole);
        var fbt = formatFbtCode(p.sortNum);

        var titleEl = d.getElementById('scapi-sop-modal-title');
        var dirIn = d.getElementById('scapi-sop-dir-id');
        var isMainIn = d.getElementById('scapi-sop-is-main');
        var bmIdIn = d.getElementById('scapi-sop-bmid-input');
        var bmIdLbl = d.getElementById('scapi-sop-bmid-label');
        var clearBmIn = d.getElementById('scapi-sop-clear-bm-flag');
        var aliveSel = d.getElementById('scapi-sop-alive-sel');
        var bmSel = d.getElementById('scapi-sop-bm-sel');
        var bmTimeIn = d.getElementById('scapi-sop-bm-time-input');
        var stgSel = d.getElementById('scapi-sop-stage-sel');
        var nextIn = d.getElementById('scapi-sop-next-input');
        if (titleEl) titleEl.textContent = '目标窗口：' + (fbt ? fbt + ' ｜ ' : '') + (p.groupName ? p.groupName + ' ｜ ' : '') + (p.name || p.dirId);
        if (dirIn) dirIn.value = p.dirId;
        if (isMainIn) isMainIn.value = cRole === 'main' ? '1' : '0';
        if (bmIdIn) {
          bmIdIn.value = curBmId;
          bmIdIn.placeholder = cRole === 'main'
            ? '必填：在投主户必须填写 15~16 位纯数字绿盾 BMID'
            : '选填：可提前记录该窗口的纯数字绿盾 BMID';
        }
        if (bmIdLbl) {
          bmIdLbl.style.color = cRole === 'main' ? '#b91c1c' : '#1e40af';
          bmIdLbl.textContent = cRole === 'main'
            ? '0. 绿盾 BM ID（🚨 在投主户必填 · 纯数字编号）：'
            : '0. 绿盾 BM ID（选填 · 纯数字编号）：';
        }
        if (clearBmIn) clearBmIn.value = '0';
        if (aliveSel) aliveSel.value = entry.alive_override || 'auto';
        if (bmSel) bmSel.value = entry.bm_status_override || 'auto';
        if (bmTimeIn) bmTimeIn.value = toDatetimeLocalVal(entry.bm_bound_time || '');
        if (stgSel) stgSel.value = entry.stage_override || 'auto';
        if (nextIn) nextIn.value = entry.next_time_override || '';
        modal.classList.add('open');
        if (cRole === 'main' && !curBmId && bmIdIn) {
          bmIdIn.focus();
        }
        fetchSingleProfileDetail(p, true, null, null).then(function () {
          renderProfiles();
        });
      }

      var bmSelEl = d.getElementById('scapi-sop-bm-sel');
      var bmTimeEl = d.getElementById('scapi-sop-bm-time-input');
      var clearBmFlagEl = d.getElementById('scapi-sop-clear-bm-flag');
      var bmUseOpenBtn = d.getElementById('scapi-sop-bm-use-open');
      var bmUseNowBtn = d.getElementById('scapi-sop-bm-use-now');
      var bmClearBtn = d.getElementById('scapi-sop-bm-clear-btn');

      if (bmSelEl) {
        bmSelEl.addEventListener('change', function () {
          var v = bmSelEl.value;
          var stgSel = d.getElementById('scapi-sop-stage-sel');
          if (v === 'has_bm_ok' && bmTimeEl && !bmTimeEl.value) {
            var p = currentSopModalProfile;
            var fallback = (p && p.openTime) ? toDatetimeLocalVal(p.openTime) : '';
            bmTimeEl.value = fallback || nowBjDatetimeLocal();
            if (clearBmFlagEl) clearBmFlagEl.value = '0';
          } else if (v === 'no_bm') {
            if (bmTimeEl) bmTimeEl.value = '';
            if (clearBmFlagEl) clearBmFlagEl.value = '1';
            if (stgSel && (stgSel.value === '4_warmup_bm' || stgSel.value === '5_ready_fire' || stgSel.value === '6_golden')) {
              stgSel.value = 'auto';
            }
          } else if (v === 'unknown' || v === 'bm_restricted') {
            if (stgSel && stgSel.value === '4_warmup_bm') {
              stgSel.value = 'auto';
            }
          }
        });
      }
      if (bmUseOpenBtn) {
        bmUseOpenBtn.onclick = function () {
          var p = currentSopModalProfile;
          var val = p ? toDatetimeLocalVal(p.openTime || p.createTime || '') : '';
          if (bmTimeEl) bmTimeEl.value = val || nowBjDatetimeLocal();
          if (clearBmFlagEl) clearBmFlagEl.value = '0';
          if (bmSelEl && (bmSelEl.value === 'auto' || bmSelEl.value === 'unknown' || bmSelEl.value === 'no_bm')) {
            bmSelEl.value = 'has_bm_ok';
          }
        };
      }
      if (bmUseNowBtn) {
        bmUseNowBtn.onclick = function () {
          if (bmTimeEl) bmTimeEl.value = nowBjDatetimeLocal();
          if (clearBmFlagEl) clearBmFlagEl.value = '0';
          if (bmSelEl && (bmSelEl.value === 'auto' || bmSelEl.value === 'unknown' || bmSelEl.value === 'no_bm')) {
            bmSelEl.value = 'has_bm_ok';
          }
        };
      }
      if (bmClearBtn) {
        bmClearBtn.onclick = function () {
          if (bmTimeEl) bmTimeEl.value = '';
          if (clearBmFlagEl) clearBmFlagEl.value = '1';
        };
      }

      var saveSopBtn = d.getElementById('scapi-sop-save-btn');
      if (saveSopBtn) {
        saveSopBtn.onclick = function () {
          var dirId = (d.getElementById('scapi-sop-dir-id') || {}).value || '';
          if (!dirId || !w.SCAPI_ROXY_RELAY || !w.SCAPI_ROXY_RELAY.ajaxUrl) return;
          var isMainVal = (d.getElementById('scapi-sop-is-main') || {}).value || '0';
          var bmIdVal = String((d.getElementById('scapi-sop-bmid-input') || {}).value || '').replace(/\s+/g, '');
          var aliveVal = (d.getElementById('scapi-sop-alive-sel') || {}).value || 'auto';
          var bmVal = (d.getElementById('scapi-sop-bm-sel') || {}).value || 'auto';
          var bmTimeVal = (d.getElementById('scapi-sop-bm-time-input') || {}).value || '';
          var clearBmVal = (d.getElementById('scapi-sop-clear-bm-flag') || {}).value || '0';
          var stgVal = (d.getElementById('scapi-sop-stage-sel') || {}).value || 'auto';
          var nextVal = (d.getElementById('scapi-sop-next-input') || {}).value || '';
          if (bmIdVal && !/^\d{6,24}$/.test(bmIdVal)) {
            w.alert('拦截提示：BMID 备注格式错误，请填写 6~24 位纯数字编号（外部户可留空）！');
            return;
          }
          saveSopBtn.disabled = true;
          saveSopBtn.textContent = '保存中...';
          var fd = new w.FormData();
          fd.append('action', 'scapi_roxy_save_sop');
          fd.append('_ajax_nonce', w.SCAPI_ROXY_RELAY.nonce || '');
          fd.append('dir_id', dirId);
          fd.append('is_active_main', (isMainVal === '1' || stgVal === '7_active') ? '1' : '0');
          fd.append('bm_id', bmIdVal);
          fd.append('alive_override', aliveVal);
          fd.append('bm_status_override', bmVal);
          fd.append('bm_bound_time', bmTimeVal);
          fd.append('clear_bm_time', clearBmVal);
          fd.append('stage_override', stgVal);
          fd.append('next_time_override', nextVal);
          w.fetch(w.SCAPI_ROXY_RELAY.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (res && !res.success && res.data && res.data.message) {
                w.alert(res.data.message);
                return;
              }
              if (res && res.success && res.data && res.data.sop_state) {
                sopStateMap = res.data.sop_state;
              }
              var modal = d.getElementById('scapi-roxy-sop-modal');
              if (modal) modal.classList.remove('open');
              renderProfiles();
            })
            .catch(function () {})
            .finally(function () {
              saveSopBtn.disabled = false;
              saveSopBtn.textContent = '保存并立即刷新';
            });
        };
      }

      function renderRoxyTagPills(labels) {
        if (!Array.isArray(labels) || !labels.length) return '';
        return labels.map(function (lb) {
          var nm = String(lb.name || '').trim();
          if (!nm) return '';
          var bg = '#f1f5f9';
          var fg = '#334155';
          var bd = '#cbd5e1';
          if (nm.indexOf('投放中') !== -1) { bg = '#dcfce7'; fg = '#15803d'; bd = '#86efac'; }
          else if (nm.indexOf('保号') !== -1) { bg = '#f3e8ff'; fg = '#7e22ce'; bd = '#d8b4fe'; }
          else if (nm.indexOf('静置') !== -1) { bg = '#fef3c7'; fg = '#92400e'; bd = '#fde68a'; }
          else if (nm.indexOf('问题') !== -1 || nm.indexOf('差') !== -1 || nm.indexOf('已挂') !== -1 || nm.indexOf('三不限') !== -1) { bg = '#fee2e2'; fg = '#b91c1c'; bd = '#fca5a5'; }
          return '<span class="scapi-roxy-tag" style="background:' + bg + ';color:' + fg + ';border-color:' + bd + ';">' + escHtml(nm) + '</span>';
        }).join(' ');
      }

      function computeHotReadiness(ht, sopView) {
        var lbs = Array.isArray(ht.labels) ? ht.labels : [];
        var warnTags = [];
        lbs.forEach(function (lb) {
          var nm = String(lb.name || '');
          if (nm && nm.indexOf('72h') === -1 && !/^ss\d$/i.test(nm) && nm.indexOf('热备') === -1) {
            warnTags.push(nm);
          }
        });
        if (warnTags.length > 0) {
          return { cls: 'err', text: '⚠️ 卡点待办：' + warnTags.join(' / ') };
        }
        var plainNext = String(sopView.nextOpenHtml || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        var plainStg = String(sopView.stageBadge || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        if (plainStg.indexOf('黄金') !== -1 || plainStg.indexOf('可点火') !== -1 || plainStg.indexOf('满72h') !== -1) {
          return { cls: 'ok', text: '🟢 满72h热备就绪 · 随时可切主位' };
        }
        return { cls: 'warn', text: '⏳ ' + (plainStg || '72h静置BM中') + (plainNext ? '（' + plainNext + '）' : '') };
      }

      function renderProfiles() {
        if (!tblBody && !zonesBoard) return;
        if (isPointerDownInTable) {
          if (!deferredRenderTimer) {
            deferredRenderTimer = w.setTimeout(function () {
              deferredRenderTimer = null;
              renderProfiles();
            }, 420);
          }
          return;
        }
        var kw = buyerFilter ? buyerFilter.value.trim().toLowerCase() : '';
        var ipCounts = {};
        allProfiles.forEach(function (item) {
          if (classifyCombatRole(item) !== 'dead' && item.proxyHost) {
            ipCounts[item.proxyHost] = (ipCounts[item.proxyHost] || 0) + 1;
          }
        });

        // Build per-zone combat buckets (S1..S6 + UNASSIGNED) + dead bucket
        var zoneBuckets = {};
        zonesCatalog.forEach(function (zm) {
          zoneBuckets[zm.zone_code] = { meta: zm, mains: [], guards: [], auxs: [], hots: [] };
        });
        zoneBuckets.UNASSIGNED = {
          meta: { site_key: '', zone_code: 'UNASSIGNED', buyer: '待指派战区', label: '待指派战区', domain: '' },
          mains: [], guards: [], auxs: [], hots: []
        };
        var deadList = [];

        allProfiles.forEach(function (p) {
          var cRole = classifyCombatRole(p);
          if (cRole === 'dead') {
            deadList.push(p);
            return;
          }
          var zCode = classifyCombatZone(p);
          var bucket = zoneBuckets[zCode] || zoneBuckets.UNASSIGNED;
          if (cRole === 'main') bucket.mains.push(p);
          else if (cRole === 'guard') bucket.guards.push(p);
          else if (cRole === 'aux') bucket.auxs.push(p);
          else bucket.hots.push(p);
        });

        // Sort within buckets by sortNum ascending/descending cleanly
        Object.keys(zoneBuckets).forEach(function (zk) {
          var b = zoneBuckets[zk];
          var cmp = function (a, c) { return (parseInt(c.sortNum || 0, 10) || 0) - (parseInt(a.sortNum || 0, 10) || 0); };
          b.mains.sort(cmp);
          b.guards.sort(cmp);
          b.auxs.sort(cmp);
          b.hots.sort(cmp);
        });

        // Evaluate Zone Health & Summary Strip (S1..S5 primary combat zones + S6 if populated)
        function evalBucketHealth(b) {
          var gw = b.meta.site_key ? findGatewaySlotsForSite(b.meta.site_key) : { primary: null, standby: null };
          var m = b.mains[0] || null;
          var g = b.guards[0] || null;
          var hasAux = b.auxs.length > 0;
          var mBmId = m ? resolveWindowBmId(m, findMatchedSlotForProfile(m), gw.primary, 'main') : (gw.primary && gw.primary.bm_id ? String(gw.primary.bm_id) : '');
          var ipMismatch = false;
          var mMatched = m ? findMatchedSlotForProfile(m) : null;
          if (m && m.proxyHost && !mMatched && gw.primary && gw.primary.egress_proxy_masked && String(gw.primary.egress_proxy_masked).indexOf(m.proxyHost) === -1) {
            ipMismatch = true;
          }
          if (!m && hasAux) return { cls: 'ok', label: '⚡ 辅跑在投 (' + b.auxs.length + '户)', gw: gw, mBmId: mBmId, ipMismatch: false };
          if (!m && !g) return { cls: 'err', label: '🔴 缺主号与保号', gw: gw, mBmId: mBmId, ipMismatch: false };
          if (!m) return { cls: 'err', label: '🔴 缺在投主号', gw: gw, mBmId: mBmId, ipMismatch: false };
          if (!g) return { cls: 'warn', label: '🟡 主号待配保号', gw: gw, mBmId: mBmId, ipMismatch: ipMismatch };
          if (ipMismatch) return { cls: 'warn', label: '🟡 主槽IP待对齐', gw: gw, mBmId: mBmId, ipMismatch: true };
          if (!gw.primary && !gw.standby) return { cls: 'warn', label: '🟡 编队齐·待挂像素', gw: gw, mBmId: mBmId, ipMismatch: false };
          if (hasAux) return { cls: 'ok', label: '✅ 主号+' + b.auxs.length + '辅跑并跑', gw: gw, mBmId: mBmId, ipMismatch: false };
          if (!b.hots.length) return { cls: 'warn', label: '🟡 缺独立热备', gw: gw, mBmId: mBmId, ipMismatch: false };
          return { cls: 'ok', label: '✅ 编队齐整', gw: gw, mBmId: mBmId, ipMismatch: false };
        }

        var visibleZoneCodes = [];
        zonesCatalog.forEach(function (zm) {
          var b = zoneBuckets[zm.zone_code];
          if (zm.zone_code !== 'S6' || b.mains.length || b.guards.length || b.auxs.length || b.hots.length) {
            visibleZoneCodes.push(zm.zone_code);
          }
        });
        if (zoneBuckets.UNASSIGNED.mains.length || zoneBuckets.UNASSIGNED.guards.length || zoneBuckets.UNASSIGNED.auxs.length || zoneBuckets.UNASSIGNED.hots.length) {
          visibleZoneCodes.push('UNASSIGNED');
        }

        if (zoneStrip) {
          var totalM = 0, totalG = 0, totalA = 0, totalH = 0;
          visibleZoneCodes.forEach(function (zc) {
            totalM += zoneBuckets[zc].mains.length;
            totalG += zoneBuckets[zc].guards.length;
            totalA += zoneBuckets[zc].auxs.length;
            totalH += zoneBuckets[zc].hots.length;
          });
          var stripHtml = '<div class="scapi-zone-chip ' + (activeZoneFilter === 'ALL' ? 'active' : '') + '" data-zone-filter="ALL">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;"><strong style="font-size:13px;color:#0f172a;">全部战区总览</strong><span class="scapi-badge ok">' + visibleZoneCodes.length + ' 战区</span></div>' +
            '<div style="font-size:12px;color:#334155;margin-top:5px;font-weight:600;">🟢主 ' + totalM + ' · 🛡️保 ' + totalG + ' · ⚡辅 ' + totalA + ' · 🔥热 ' + totalH + '</div>' +
            '<div style="font-size:11px;color:#64748b;margin-top:3px;">点击右侧卡片单看某战区</div>' +
          '</div>';
          visibleZoneCodes.forEach(function (zc) {
            var b = zoneBuckets[zc];
            var h = evalBucketHealth(b);
            stripHtml += '<div class="scapi-zone-chip ' + (activeZoneFilter === zc ? 'active' : '') + '" data-zone-filter="' + escHtml(zc) + '">' +
              '<div style="display:flex;justify-content:space-between;align-items:center;gap:4px;">' +
                '<strong style="font-size:13px;color:#0f172a;">' + escHtml(b.meta.label) + '</strong>' +
                '<span style="font-family:monospace;font-size:11.5px;color:#64748b;">' + b.mains.length + '主' + b.guards.length + '保' + b.auxs.length + '辅' + b.hots.length + '热</span>' +
              '</div>' +
              '<div style="margin-top:5px;"><span class="scapi-badge ' + h.cls + '" style="font-size:11px;">' + escHtml(h.label) + '</span></div>' +
              '<div style="font-size:11px;color:#64748b;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escHtml(b.meta.domain || '-') + '</div>' +
            '</div>';
          });
          zoneStrip.innerHTML = stripHtml;
        }

        function profileMatchesKw(p, bMeta, bmIdStr) {
          if (!kw) return true;
          var fbt = formatFbtCode(p.sortNum);
          var tagStr = (p.labels || []).map(function (l) { return l.name; }).join(' ');
          var hay = (fbt + ' ' + (bMeta.label || '') + ' ' + (bMeta.domain || '') + ' ' + (p.groupName || '') + ' ' + (p.name || '') + ' ' + (p.remark || '') + ' ' + (p.proxyHost || '') + ' ' + tagStr + ' ' + (bmIdStr || '')).toLowerCase();
          return hay.indexOf(kw) !== -1;
        }

        // Render Cards View
        if (zonesBoard) {
          var cardsHtml = '';
          visibleZoneCodes.forEach(function (zc) {
            if (activeZoneFilter !== 'ALL' && activeZoneFilter !== zc) return;
            var b = zoneBuckets[zc];
            var h = evalBucketHealth(b);
            var primaryWin = b.mains[0] || null;
            var primaryFbt = primaryWin ? (formatFbtCode(primaryWin.sortNum) || primaryWin.name) : '主号';
            var primaryBmId = h.mBmId;

            if (kw) {
              var anyHit = b.mains.some(function (p) { return profileMatchesKw(p, b.meta, primaryBmId); }) ||
                b.guards.some(function (p) { return profileMatchesKw(p, b.meta, primaryBmId); }) ||
                b.auxs.some(function (p) { return profileMatchesKw(p, b.meta, ''); }) ||
                b.hots.some(function (p) { return profileMatchesKw(p, b.meta, ''); }) ||
                ((b.meta.label || '').toLowerCase().indexOf(kw) !== -1);
              if (!anyHit) return;
            }

            // 1. Main rows
            var mainBlock = '';
            if (b.mains.length > 0) {
              b.mains.forEach(function (m) {
                var mSlot = findMatchedSlotForProfile(m);
                var sopV = computeSopRowView(m, mSlot || h.gw.primary, ipCounts);
                var fbt = formatFbtCode(m.sortNum);
                var mBm = resolveWindowBmId(m, mSlot, h.gw.primary, 'main');
                var escDir = escHtml(m.dirId);
                var bmBadge = mBm
                  ? '<span class="scapi-bmid-badge ok" data-roxy-sop-dir="' + escDir + '" data-tip="BMID 备注（点击可修改）">🏢 BMID: ' + escHtml(mBm) + '</span>'
                  : '<span class="scapi-badge neutral" data-roxy-sop-dir="' + escDir + '" style="cursor:pointer;" data-tip="外部消耗户可免填 BMID；如需备注绿盾 BMID 可点击录入">+ 备注BMID</span>';

                var gwActionHtml = '';
                var boundMainSlot = mSlot || (!h.ipMismatch ? h.gw.primary : null);
                if (boundMainSlot) {
                  var dsTail = String(boundMainSlot.dataset_id || '').slice(-4);
                  var rlLbl = String(boundMainSlot.slot_role || 'primary').toLowerCase() === 'shadow' ? '旁路养模' : '主归属';
                  gwActionHtml = '<span class="scapi-badge ok">✅ 已绑 ' + escHtml(boundMainSlot.slot_id) + '·' + rlLbl + '(尾号' + escHtml(dsTail) + ')</span> ' +
                    '<button type="button" class="button button-small" data-roxy-assign-dir="' + escDir + '">修改/换号</button>';
                } else if (h.ipMismatch && h.gw.primary) {
                  gwActionHtml = '<span class="scapi-badge warn" data-tip="网关 1号槽当前绑定的代理 (' + escHtml(h.gw.primary.egress_proxy_masked || '') + ') 与本 Roxy 主号 IP (' + escHtml(m.proxyHost) + ') 不一致！">⚠️ 槽位IP待对齐</span> ' +
                    '<button type="button" class="button button-small button-primary" data-roxy-assign-dir="' + escDir + '">一键对齐1号槽</button>';
                } else {
                  gwActionHtml = '<span class="scapi-badge warn">尚未挂载像素</span> ' +
                    '<button type="button" class="button button-small button-primary" data-roxy-assign-dir="' + escDir + '">一键下发1号主槽</button>';
                }

                mainBlock += '<div class="scapi-seat-row main">' +
                  '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;">' +
                    '<span class="scapi-role-tag main">🟢 主 · 在投</span>' +
                    (fbt ? '<span class="scapi-fbt-code">' + escHtml(fbt) + '</span>' : '') +
                    '<strong style="font-size:13.5px;color:#0f172a;">' + escHtml(m.name || m.dirId) + '</strong>' +
                    renderRoxyTagPills(m.labels) +
                    bmBadge +
                    (m.remark && m.remark !== '-' ? '<span style="color:#64748b;">号商：' + escHtml(m.remark) + '</span>' : '') +
                    (m.proxyHost ? '<span class="scapi-abbr-ip" data-tip="独享静态代理：' + escHtml(m.proxyMasked || m.proxyHost) + '">IP: ' + escHtml(m.proxyHost) + '</span>' : '<span class="scapi-badge err">缺代理</span>') +
                    (sopV.isDupIp ? '<span class="scapi-badge err">撞IP!</span>' : '') +
                    '<span data-roxy-sop-dir="' + escDir + '" data-tip="' + escHtml(sopV.aliveTip) + '">' + sopV.aliveBadge + '</span>' +
                  '</div>' +
                  '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">' + gwActionHtml + '</div>' +
                '</div>';
              });
            } else {
              mainBlock = '<div class="scapi-seat-empty">' +
                '<span>🔴 <strong>【主 · 在投主号】当前空缺</strong> — 可在 Roxy「🚀在投库」放入主号，或直接使用下方「⚡辅跑」项目窗口独立跑量</span>' +
              '</div>';
            }

            // 2. Guard rows
            var guardBlock = '';
            if (b.guards.length > 0) {
              b.guards.forEach(function (g) {
                var sopG = computeSopRowView(g, null, ipCounts);
                var fbtG = formatFbtCode(g.sortNum);
                var escDirG = escHtml(g.dirId);
                var sharedBmTxt = primaryBmId ? (' · 共守 BMID: ' + primaryBmId) : '';
                guardBlock += '<div class="scapi-guard-indent">' +
                  '<div class="scapi-seat-row guard">' +
                    '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;">' +
                      '<span class="scapi-role-tag guard">🛡️ 保 · 护主BM</span>' +
                      (fbtG ? '<span class="scapi-fbt-code">' + escHtml(fbtG) + '</span>' : '') +
                      '<strong style="color:#0f172a;">' + escHtml(g.name || g.dirId) + '</strong>' +
                      renderRoxyTagPills(g.labels) +
                      (g.remark && g.remark !== '-' ? '<span style="color:#64748b;">号商：' + escHtml(g.remark) + '</span>' : '') +
                      (g.proxyHost ? '<span class="scapi-abbr-ip" data-tip="保号独享静态代理：' + escHtml(g.proxyMasked || g.proxyHost) + '">IP: ' + escHtml(g.proxyHost) + '</span>' : '<span class="scapi-badge err">缺代理</span>') +
                      '<span data-roxy-sop-dir="' + escDirG + '" data-tip="' + escHtml(sopG.aliveTip) + '">' + sopG.aliveBadge + '</span>' +
                    '</div>' +
                    '<div>' +
                      '<span class="scapi-role-tag guard" style="font-weight:600;">🔒 守护对象 ➔ ' + escHtml(primaryFbt + sharedBmTxt) + '（第2超管躺尸 · 免占像素槽）</span>' +
                    '</div>' +
                  '</div>' +
                '</div>';
              });
            } else if (b.mains.length > 0) {
              guardBlock = '<div class="scapi-guard-indent">' +
                '<div class="scapi-seat-empty">' +
                  '<span>⚠️ <strong>【保 · 同BM守护号】当前空缺</strong> — 建议为绿盾主 BM 配 1 个第二超管移入「🔞保号库」并打标 <code>' + escHtml(zc.toLowerCase().replace('s', 'ss')) + '</code></span>' +
                '</div>' +
              '</div>';
            }

            // 2.5 Aux (辅跑) rows — dedicated auxiliary ad-running accounts assisting Main!
            var auxBlock = '';
            if (b.auxs.length > 0) {
              b.auxs.forEach(function (ax, aIdx) {
                var axSlot = findMatchedSlotForProfile(ax);
                var sopA = computeSopRowView(ax, axSlot, ipCounts);
                var fbtA = formatFbtCode(ax.sortNum);
                var escDirA = escHtml(ax.dirId);
                var axBm = resolveWindowBmId(ax, axSlot, null, 'aux');
                var axActionHtml = '';
                if (axSlot) {
                  var axTail = String(axSlot.dataset_id || '').slice(-4);
                  var axRoleCn = String(axSlot.slot_role || 'primary').toLowerCase() === 'shadow' ? '旁路养模' : '主归属实时';
                  axActionHtml = '<span class="scapi-badge ok">✅ 已绑 ' + escHtml(axSlot.slot_id) + '·' + axRoleCn + '(尾号' + escHtml(axTail) + ')</span> ' +
                    '<button type="button" class="button button-small" data-roxy-assign-dir="' + escDirA + '">修改辅跑槽</button>';
                } else {
                  axActionHtml = '<span class="scapi-badge neutral">待挂载独立辅跑槽</span> ' +
                    '<button type="button" class="button button-small button-primary" data-roxy-assign-dir="' + escDirA + '">⚡ 一键下发辅跑槽</button>';
                }
                auxBlock += '<div class="scapi-seat-row aux">' +
                  '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;">' +
                    '<span class="scapi-role-tag aux">⚡ 辅跑 #' + (aIdx + 1) + '</span>' +
                    (fbtA ? '<span class="scapi-fbt-code">' + escHtml(fbtA) + '</span>' : '') +
                    '<strong style="color:#0f172a;">' + escHtml(ax.name || ax.dirId) + '</strong>' +
                    renderRoxyTagPills(ax.labels) +
                    (axBm ? '<span class="scapi-bmid-badge ok" data-roxy-sop-dir="' + escDirA + '">🏢 BM: ' + escHtml(axBm) + '</span>' : '') +
                    (ax.remark && ax.remark !== '-' ? '<span style="color:#64748b;">号商：' + escHtml(ax.remark) + '</span>' : '') +
                    (ax.proxyHost ? '<span class="scapi-abbr-ip" data-tip="辅跑独享静态代理：' + escHtml(ax.proxyMasked || ax.proxyHost) + '">IP: ' + escHtml(ax.proxyHost) + '</span>' : '<span class="scapi-badge err">缺代理</span>') +
                    (sopA.isDupIp ? '<span class="scapi-badge err">撞IP!</span>' : '') +
                    '<span data-roxy-sop-dir="' + escDirA + '" data-tip="' + escHtml(sopA.aliveTip) + '">' + sopA.aliveBadge + '</span>' +
                  '</div>' +
                  '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">' + axActionHtml + '</div>' +
                '</div>';
              });
            }

            // 3. Hot Standby rows
            var hotBlock = '';
            if (b.hots.length > 0) {
              b.hots.forEach(function (ht, hIdx) {
                var htSlot = findMatchedSlotForProfile(ht);
                var sopH = computeSopRowView(ht, htSlot, ipCounts);
                var fbtH = formatFbtCode(ht.sortNum);
                var escDirH = escHtml(ht.dirId);
                var htBm = resolveWindowBmId(ht, htSlot, null, 'hot');
                var readyInfo = computeHotReadiness(ht, sopH);
                var roleTitle = hIdx === 0 ? '🔥 热 · 一号独立替补' : ('🧱 热 · 次级备选 #' + (hIdx + 1));
                hotBlock += '<div class="scapi-seat-row hot">' +
                  '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;">' +
                    '<span class="scapi-role-tag hot">' + escHtml(roleTitle) + '</span>' +
                    (fbtH ? '<span class="scapi-fbt-code">' + escHtml(fbtH) + '</span>' : '') +
                    '<strong style="color:#0f172a;">' + escHtml(ht.name || ht.dirId) + '</strong>' +
                    renderRoxyTagPills(ht.labels) +
                    (htBm ? '<span class="scapi-bmid-badge ok" data-roxy-sop-dir="' + escDirH + '">🏢 备BM: ' + escHtml(htBm) + '</span>' : '') +
                    (ht.remark && ht.remark !== '-' ? '<span style="color:#64748b;">号商：' + escHtml(ht.remark) + '</span>' : '') +
                    (ht.proxyHost ? '<span class="scapi-abbr-ip" data-tip="热备独享静态代理：' + escHtml(ht.proxyMasked || ht.proxyHost) + '">IP: ' + escHtml(ht.proxyHost) + '</span>' : '<span class="scapi-badge err">缺代理</span>') +
                    '<span data-roxy-sop-dir="' + escDirH + '" data-tip="' + escHtml(sopH.aliveTip) + '">' + sopH.aliveBadge + '</span>' +
                  '</div>' +
                  '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">' +
                    '<span class="scapi-badge ' + readyInfo.cls + '" data-roxy-sop-dir="' + escDirH + '" style="cursor:pointer;" data-tip="' + escHtml(sopH.sopTip) + '">' + escHtml(readyInfo.text) + '</span>' +
                    (htSlot
                      ? '<span class="scapi-badge ok">✅ 已绑 ' + escHtml(htSlot.slot_id) + '</span> <button type="button" class="button button-small" data-roxy-assign-dir="' + escDirH + '">修改槽位</button>'
                      : '<button type="button" class="button button-small" data-roxy-assign-dir="' + escDirH + '">下发至槽位</button>') +
                  '</div>' +
                '</div>';
              });
            } else {
              hotBlock = '<div class="scapi-seat-empty" style="border-color:#fde68a;background:#fffbeb;color:#92400e;">' +
                '<span>🟡 <strong>【热 · 独立替补号】当前空缺</strong> — 建议在「🔥热备库」预养静置号以备换防</span>' +
              '</div>';
            }

            cardsHtml += '<div class="scapi-zone-card">' +
              '<div class="scapi-zone-head">' +
                '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;">' +
                  '<span class="scapi-badge primary" style="font-size:13px;">' + escHtml(b.meta.label) + '</span>' +
                  (b.meta.domain ? '<code>' + escHtml(b.meta.domain) + '</code>' : '') +
                  '<span class="scapi-badge ' + h.cls + '">' + escHtml(h.label) + '</span>' +
                '</div>' +
                '<div style="font-size:12px;color:#64748b;">编队协同：<strong>⚡辅跑 ➔ 独立槽位辅助冲量</strong> ｜ <strong>🔥热备 ➔ 静养待命</strong> ｜ <strong>🛡️保号 ➔ 共守主 BM</strong></div>' +
              '</div>' +
              '<div class="scapi-zone-body">' +
                mainBlock +
                guardBlock +
                auxBlock +
                hotBlock +
              '</div>' +
            '</div>';
          });
          zonesBoard.innerHTML = cardsHtml || '<div style="padding:16px;color:#64748b;background:#f8fafc;border-radius:8px;">当前筛选条件下暂无匹配战区窗口。</div>';
        }

        // Render Compact Table View
        if (tblBody) {
          var tRows = '';
          visibleZoneCodes.forEach(function (zc) {
            if (activeZoneFilter !== 'ALL' && activeZoneFilter !== zc) return;
            var b = zoneBuckets[zc];
            var h = evalBucketHealth(b);
            var primFbt = b.mains[0] ? (formatFbtCode(b.mains[0].sortNum) || b.mains[0].name) : '主号';
            var seq = [];
            b.mains.forEach(function (p) { seq.push({ role: 'main', roleBadge: '<span class="scapi-role-tag main">🟢 主号</span>', p: p }); });
            b.guards.forEach(function (p) { seq.push({ role: 'guard', roleBadge: '<span class="scapi-role-tag guard">🛡️ 保号</span>', p: p }); });
            b.auxs.forEach(function (p, i) { seq.push({ role: 'aux', roleBadge: '<span class="scapi-role-tag aux">⚡ 辅跑#' + (i + 1) + '</span>', p: p }); });
            b.hots.forEach(function (p, i) { seq.push({ role: 'hot', roleBadge: '<span class="scapi-role-tag hot">' + (i === 0 ? '🔥 热备' : '🧱 二备') + '</span>', p: p }); });

            seq.forEach(function (item) {
              var p = item.p;
              var mSlot = findMatchedSlotForProfile(p);
              var bmId = resolveWindowBmId(p, mSlot, h.gw.primary, item.role);
              if (!profileMatchesKw(p, b.meta, bmId)) return;
              var sopV = computeSopRowView(p, mSlot || (item.role === 'main' ? h.gw.primary : null), ipCounts);
              var fbt = formatFbtCode(p.sortNum);
              var escDir = escHtml(p.dirId);
              var relHtml = '';
              if (item.role === 'main') {
                relHtml = bmId
                  ? '<span class="scapi-bmid-badge ok" data-roxy-sop-dir="' + escDir + '">🏢 BMID: ' + escHtml(bmId) + '</span>'
                  : '<span class="scapi-badge neutral" data-roxy-sop-dir="' + escDir + '">选填BMID</span>';
              } else if (item.role === 'guard') {
                relHtml = '🛡️ 保 <strong>' + escHtml(primFbt) + '</strong>' + (h.mBmId ? ' (BM:' + escHtml(h.mBmId) + ')' : '');
              } else if (item.role === 'aux') {
                relHtml = '⚡ 辅跑 <strong>' + escHtml(b.meta.label) + '</strong>' + (mSlot ? ' (' + escHtml(mSlot.slot_id) + ')' : '');
              } else {
                relHtml = '🔥 备 <strong>' + escHtml(b.meta.label) + '</strong> 主位';
              }
              tRows += '<tr>' +
                '<td><strong>' + escHtml(b.meta.label) + '</strong></td>' +
                '<td>' + item.roleBadge + '</td>' +
                '<td>' + (fbt ? '<span class="scapi-fbt-code">' + escHtml(fbt) + '</span>' : '-') + '</td>' +
                '<td><strong>' + escHtml(p.name || p.dirId) + '</strong></td>' +
                '<td>' + renderRoxyTagPills(p.labels) + '</td>' +
                '<td>' + relHtml + '</td>' +
                '<td>' + escHtml(p.remark || '-') + (p.proxyHost ? ' · <code>' + escHtml(p.proxyHost) + '</code>' : '') + '</td>' +
                '<td data-roxy-sop-dir="' + escDir + '" style="cursor:pointer;">' + sopV.aliveBadge + '</td>' +
                '<td>' + (item.role === 'guard' ? '<span style="color:#64748b;font-size:12px;">躺尸免绑槽</span>' : '<button type="button" class="button button-small button-primary" data-roxy-assign-dir="' + escDir + '">下发槽位</button>') + '</td>' +
              '</tr>';
            });
          });
          tblBody.innerHTML = tRows || '<tr><td colspan="9" style="color:#64748b;padding:16px;">当前筛选下暂无匹配窗口。</td></tr>';
        }

        // Render Collapsed Dead / Transferred Drawer
        if (deadDrawer) {
          if (deadList.length > 0) {
            deadDrawer.style.display = 'block';
            var dBody = '';
            if (deadDrawerOpen) {
              dBody = '<div style="padding:10px 14px;border-top:1px solid #e2e8f0;font-size:12px;">' +
                deadList.map(function (dp) {
                  var fbtD = formatFbtCode(dp.sortNum);
                  return '<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;opacity:0.78;flex-wrap:wrap;gap:8px;">' +
                    '<div>' +
                      (fbtD ? '<span class="scapi-fbt-code" style="background:#64748b;">' + escHtml(fbtD) + '</span> ' : '') +
                      '<span style="text-decoration:line-through;">' + escHtml(dp.name || dp.dirId) + '</span> ' +
                      '<span class="scapi-badge neutral">' + escHtml(dp.groupName || '') + '</span> ' +
                      renderRoxyTagPills(dp.labels) +
                    '</div>' +
                    '<div style="color:#64748b;">号商：' + escHtml(dp.remark || '-') + (dp.proxyHost ? ' ｜ IP: ' + escHtml(dp.proxyHost) : '') + '</div>' +
                  '</div>';
                }).join('') +
              '</div>';
            }
            deadDrawer.innerHTML = '<button type="button" class="scapi-dead-drawer-btn" data-toggle-dead-drawer="1">' +
              '<span>⚰️ 已挂 / 窗口转移淘汰收纳区（' + deadList.length + ' 个窗口）— 默认折叠隐藏，不干扰战区看盘</span>' +
              '<span>' + (deadDrawerOpen ? '收起 ▲' : '展开查看 ▼') + '</span>' +
            '</button>' + dBody;
          } else {
            deadDrawer.style.display = 'none';
          }
        }
      }

      function enrichDetailsInBatches(list) {
        if (!list || !list.length) return Promise.resolve();
        var batchSize = 2;
        var idx = 0;
        var newFirstAnchors = {};
        var newBmAnchors = {};

        function nextBatch() {
          if (idx >= list.length) return Promise.resolve();
          var slice = list.slice(idx, idx + batchSize);
          idx += batchSize;
          return Promise.all(slice.map(function (prof) {
            return fetchSingleProfileDetail(prof, true, newFirstAnchors, newBmAnchors);
          })).then(function () {
            renderProfiles();
            return new Promise(function (resolve) { w.setTimeout(resolve, 150); }).then(nextBatch);
          });
        }

        return nextBatch().then(function () {
          w.localStorage.setItem('scapi_roxy_profiles_cache', JSON.stringify(allProfiles));
          var hasFirst = Object.keys(newFirstAnchors).length > 0;
          var hasBm = Object.keys(newBmAnchors).length > 0;
          if ((hasFirst || hasBm) && w.SCAPI_ROXY_RELAY && w.SCAPI_ROXY_RELAY.ajaxUrl) {
            var fd = new w.FormData();
            fd.append('action', 'scapi_roxy_save_sop');
            fd.append('_ajax_nonce', w.SCAPI_ROXY_RELAY.nonce || '');
            if (hasFirst) fd.append('auto_first_opens_json', JSON.stringify(newFirstAnchors));
            if (hasBm) fd.append('auto_bm_anchors_json', JSON.stringify(newBmAnchors));
            w.fetch(w.SCAPI_ROXY_RELAY.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
          }
        });
      }

      function loadWorkspacesAndProfiles() {
        setStatus('warn', '正在连接本机 RoxyBrowser 接口同步分组列表与上号进度...');
        roxyFetch('/browser/workspace?page_index=1&page_size=50', 'GET')
          .then(function (res) {
            if (!res || (res.code !== 0 && res.code !== 200)) {
              throw new Error((res && res.msg) ? res.msg : '接口认证未通过，请核对 Roxy API Key');
            }
            var rows = (res.data && (res.data.rows || res.data.list)) || [];
            if (!rows.length) {
              setStatus('warn', '已连通 RoxyBrowser，但当前未查询到工作空间（Workspace）。');
              return;
            }
            activeWorkspaceId = String(rows[0].id || rows[0].workspaceId || '');
            if (wsContainer) {
              wsContainer.innerHTML = rows.map(function (ws) {
                var wid = String(ws.id || ws.workspaceId);
                return '<span class="scapi-badge ok" style="margin-right:6px;">工作空间：' + escHtml(ws.workspaceName || wid) + ' (ID:' + escHtml(wid) + ')</span>';
              }).join('');
            }
            return Promise.all(rows.map(function (ws) {
              var wid = String(ws.id || ws.workspaceId || '');
              return roxyFetch('/browser/list_v3?workspaceId=' + encodeURIComponent(wid) + '&page_index=1&page_size=200', 'GET')
                .then(function (pRes) {
                  return { wsId: wid, wsName: String(ws.workspaceName || ''), res: pRes };
                });
            }));
          })
          .then(function (wsResults) {
            if (!wsResults) return;
            allProfiles = [];
            var skippedBuffer = 0;
            var skippedTrash = 0;
            wsResults.forEach(function (wr) {
              var pRes = wr.res;
              var list = (pRes && pRes.data && (pRes.data.rows || pRes.data.list)) || [];
              list.forEach(function (item) {
                var rawProject = String(item.projectName || item.groupName || '').trim();
                if (rawProject.indexOf('缓冲池') !== -1) {
                  skippedBuffer++;
                  return;
                }
                if (isTrashRoxyGroup(rawProject) || item.projectId === 0 || item.projectId === '0') {
                  skippedTrash++;
                  return;
                }
                var proxyUrl = buildRoxyProxyUrl(item);
                var pInfo = item.proxyInfo || item.proxy || {};
                var host = pInfo.ip || pInfo.host || '';
                var port = String(pInfo.port || '');
                var schemeStr = proxyUrl ? proxyUrl.split('://')[0] : '';
                var rawRemFull = String(item.windowRemark || item.remark || '');
                var cleanCnRemark = extractChineseRemark(rawRemFull);
                allProfiles.push({
                  workspaceId: wr.wsId,
                  dirId: String(item.dirId || item.id || ''),
                  sortNum: item.windowSortNum != null ? item.windowSortNum : (item.sortNum || ''),
                  name: String(item.windowName || item.name || ''),
                  groupName: rawProject,
                  labels: extractRoxyLabels(item),
                  sniffedBmId: sniffBmIdFromText(String(item.windowName || '') + '\n' + rawRemFull),
                  remark: cleanCnRemark,
                  createTime: String(item.createTime || ''),
                  openTime: String(item.openTime || ''),
                  closeTime: String(item.closeTime || ''),
                  openStatus: item.openStatus || 0,
                  detailPending: true,
                  proxyUrl: proxyUrl,
                  proxyHost: host,
                  proxyPort: port,
                  proxyMasked: (proxyUrl && host && port) ? (schemeStr + '://***@' + host + ':' + port) : ''
                });
              });
            });

            if (w.SCAPI_ROXY_RELAY && w.SCAPI_ROXY_RELAY.ajaxUrl) {
              var fdSave = new w.FormData();
              fdSave.append('action', 'scapi_roxy_save_cfg');
              fdSave.append('_ajax_nonce', w.SCAPI_ROXY_RELAY.nonce || '');
              fdSave.append('roxy_url', urlInput ? urlInput.value.trim() : 'http://127.0.0.1:50055');
              fdSave.append('roxy_key', keyInput ? keyInput.value.trim() : '');
              w.fetch(w.SCAPI_ROXY_RELAY.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fdSave }).catch(function () {});
            }

            var extraNotes = [];
            if (skippedBuffer > 0) extraNotes.push('已按规则自动隐藏「😴缓冲池」无主窗口 ' + skippedBuffer + ' 个');
            if (skippedTrash > 0) extraNotes.push('隐藏默认项目 ' + skippedTrash + ' 个');
            var skipMsg = extraNotes.length > 0 ? ('（' + extraNotes.join('，') + '）') : '';
            setStatus('ok', '✅ 已同步本机 RoxyBrowser 战区编队！当前纳入编队窗口 <strong>' + allProfiles.length + '</strong> 个' + skipMsg + '，正在同步开窗时间与 Graph 无痕验活...');
            renderProfiles();
            return enrichDetailsInBatches(allProfiles).then(function () {
              return runSilentUidProbe(false);
            });
          })
          .catch(function (err) {
            setStatus(
              'err',
              '<strong>连接本机 RoxyBrowser 未响应（' + escHtml(err.message) + '）</strong><br/>' +
              '请依次确认：① 本机已打开 RoxyBrowser 客户端并在左侧「API -> API 配置」开启开关、填入正确 API Key；② 若浏览器因 HTTPS 安全策略拦截访问本机 <code>127.0.0.1:50000</code>，请点击右侧 <strong>「下载本机 Roxy 桥接助手」</strong> 双击运行后将接口地址填为 <code>http://127.0.0.1:50055</code> 即可 1 秒连通！'
            );
          });
      }

      function runSilentUidProbe(forceRefresh) {
        if (!allProfiles.length || !activeWorkspaceId) return Promise.resolve();
        var ids = allProfiles.map(function (p) { return p.dirId; }).filter(Boolean);
        if (!ids.length) return Promise.resolve();
        isProbingNow = true;
        if (btnProbe) {
          btnProbe.disabled = true;
          btnProbe.textContent = '⏳ 正在通过各窗独享代理无痕验活...';
        }
        renderProfiles();
        return roxyFetch('/local/probe_fb_uids', 'POST', {
          workspaceId: activeWorkspaceId,
          dirIds: ids,
          force: !!forceRefresh
        }).then(function (res) {
          var rmap = (res && res.data && res.data.results) ? res.data.results : null;
          if (rmap && typeof rmap === 'object') {
            var aliveCnt = 0;
            var deadCnt = 0;
            var proxyErrCnt = 0;
            Object.keys(rmap).forEach(function (k) {
              probeMap[k] = rmap[k];
              var st = rmap[k] && rmap[k].status;
              if (st === 'alive') aliveCnt++;
              else if (st === 'dead') deadCnt++;
              else if (st === 'proxy_err') proxyErrCnt++;
            });
            if (w.SCAPI_ROXY_RELAY && w.SCAPI_ROXY_RELAY.ajaxUrl) {
              var fd = new w.FormData();
              fd.append('action', 'scapi_roxy_save_sop');
              fd.append('_ajax_nonce', w.SCAPI_ROXY_RELAY.nonce || '');
              fd.append('probe_results_json', JSON.stringify(rmap));
              w.fetch(w.SCAPI_ROXY_RELAY.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
            }
            if (deadCnt > 0) {
              setStatus('err', '🚨 Graph 无痕验活警报：检出 <strong>' + deadCnt + '</strong> 个窗口账号已封禁停用（公开主页已消失），请立即找号商售后换号！其余正常存活 ' + aliveCnt + ' 个。');
            } else if (proxyErrCnt > 0) {
              setStatus('warn', '⚠️ Graph 无痕验活完成：主页正常存活 <strong>' + aliveCnt + '</strong> 个，另有 <strong>' + proxyErrCnt + '</strong> 个窗口静态代理无法连通，请排查代理线路！');
            } else {
              setStatus('ok', '✅ 已同步本机 RoxyBrowser 并完成 Graph 公开端点无痕验活：<strong>' + aliveCnt + '</strong> 个已绑 UID 窗口主页 100% 正常存活（0 触碰 Cookie · 各自走独享静态代理）！');
            }
          }
        }).catch(function () {
          // 若旧版桥接脚本尚未支持 /local/probe_fb_uids，保留既有状态不报错中断
        }).finally(function () {
          isProbingNow = false;
          if (btnProbe) {
            btnProbe.disabled = false;
            btnProbe.textContent = '⚡ 一键静默验活 (Graph无痕)';
          }
          renderProfiles();
        });
      }

      if (btnConn) btnConn.onclick = loadWorkspacesAndProfiles;
      if (btnProbe) {
        btnProbe.onclick = function () {
          if (!allProfiles.length) {
            loadWorkspacesAndProfiles();
          } else {
            runSilentUidProbe(true);
          }
        };
      }
      if (buyerFilter) buyerFilter.oninput = renderProfiles;

      if (keyInput && keyInput.value.trim()) {
        loadWorkspacesAndProfiles();
      }
    }
  };

  function runSmartMatch(csvText, fileName, storeItems) {
    var rows = parseCsv(csvText);
    var reportEl = d.getElementById('scapi-sku-match-report');
    var previewEl = d.getElementById('scapi-sku-match-preview');
    var formEl = d.getElementById('scapi-sku-bulk-form');
    var jsonInput = d.getElementById('scapi-sku-bulk-json');
    var submitBtn = d.getElementById('scapi-sku-bulk-submit');

    if (!rows || rows.length < 2) {
      if (reportEl) {
        reportEl.innerHTML = '<div class="scapi-diag-box err">❌ 文件解析失败：未读取到有效的数据行，请确认拖入的是标准 CSV 文件。</div>';
      }
      return;
    }

    var headers = rows[0].map(function (h) { return h.toLowerCase().trim(); });
    var idxSkuCode = headers.indexOf('sku_code');
    var idxSalePrice = headers.indexOf('sale_price');
    var idxTitle = headers.indexOf('product_title_en');
    var idxSpec = headers.indexOf('variant_spec_en');
    var idxRawSku = headers.indexOf('raw_sku');
    if (idxRawSku === -1) idxRawSku = headers.indexOf('原下单sku(woocommerce保持不变)');

    var errors = [];
    var warnings = [];
    var pairs = [];
    var usedSafeCodes = {};

    // Case A: Structured A-Site Catalog CSV (like brand_1_skus.csv: sku_code + sale_price + product_title_en)
    if (idxSkuCode !== -1 && idxSalePrice !== -1 && idxRawSku === -1) {
      if (!storeItems.length) {
        if (reportEl) {
          reportEl.innerHTML = '<div class="scapi-diag-box err">❌ 当前未能从线上店铺读取到原始商品列表，请确认店铺在线后刷新重试。</div>';
        }
        return;
      }

      var csvRecords = [];
      for (var r = 1; r < rows.length; r++) {
        var line = rows[r];
        var code = (line[idxSkuCode] || '').trim().toUpperCase();
        var price = parseFloat(line[idxSalePrice] || '0');
        var title = idxTitle !== -1 ? (line[idxTitle] || '').trim() : ('Group_' + r);
        var spec = idxSpec !== -1 ? (line[idxSpec] || '').trim() : '';
        if (!code && isNaN(price)) continue;

        var viol = checkSafeSkuViolation(code);
        if (viol) {
          errors.push('CSV 第 ' + (r + 1) + ' 行脱敏号 [' + code + '] 不合规：' + viol);
        }
        if (usedSafeCodes[code]) {
          errors.push('CSV 第 ' + (r + 1) + ' 行脱敏号 [' + code + '] 与第 ' + usedSafeCodes[code] + ' 行重复！');
        }
        usedSafeCodes[code] = r + 1;

        if (isNaN(price) || price <= 0) {
          errors.push('CSV 第 ' + (r + 1) + ' 行 [' + code + '] 售价无效：' + (line[idxSalePrice] || '空'));
        }
        csvRecords.push({
          lineNo: r + 1,
          safeSku: code,
          price: isNaN(price) ? 0 : price,
          pKey: priceKey(price),
          title: title,
          spec: spec,
          used: false
        });
      }

      // Group Store Items by SPU (pid)
      var storeSpuMap = {};
      var storeSpuList = [];
      storeItems.forEach(function (it, idx) {
        var k = String(it.pid || it.raw_sku || idx);
        if (!storeSpuMap[k]) {
          storeSpuMap[k] = { pid: k, name: it.spu_name || it.raw_sku, vars: [], matched: false };
          storeSpuList.push(storeSpuMap[k]);
        }
        storeSpuMap[k].vars.push({
          rawSku: String(it.raw_sku || ''),
          spuName: String(it.spu_name || ''),
          specLabel: String(it.spec_label || ''),
          price: parseFloat(it.price || 0),
          pKey: priceKey(it.price),
          matchedCsv: null
        });
      });
      storeSpuList.forEach(function (spu) {
        spu.sig = spu.vars.map(function (v) { return v.pKey; }).sort().join('|');
      });

      // Group CSV Records by consecutive/same product_title_en
      var csvSpuMap = {};
      var csvSpuList = [];
      csvRecords.forEach(function (rec) {
        var tk = rec.title;
        if (!csvSpuMap[tk]) {
          csvSpuMap[tk] = { title: tk, rows: [], matched: false };
          csvSpuList.push(csvSpuMap[tk]);
        }
        csvSpuMap[tk].rows.push(rec);
      });
      csvSpuList.forEach(function (g) {
        g.sig = g.rows.map(function (x) { return x.pKey; }).sort().join('|');
      });

      // Pass 1: Exact SPU Price-Tuple Signature Bijection
      var csvBucketsBySig = {};
      csvSpuList.forEach(function (g) {
        if (!csvBucketsBySig[g.sig]) csvBucketsBySig[g.sig] = [];
        csvBucketsBySig[g.sig].push(g);
      });

      storeSpuList.forEach(function (sSpu) {
        var bucket = csvBucketsBySig[sSpu.sig];
        if (bucket && bucket.length > 0) {
          var cSpu = bucket.shift();
          sSpu.matched = true;
          cSpu.matched = true;
          var sVars = sSpu.vars.slice().sort(function (a, b) { return a.price - b.price; });
          var cRows = cSpu.rows.slice().sort(function (a, b) { return a.price - b.price; });
          for (var i = 0; i < sVars.length; i++) {
            sVars[i].matchedCsv = cRows[i];
            cRows[i].used = true;
          }
        }
      });

      // Pass 2: Subset SPU Match (e.g. store SPU has [$10], CSV SPU has [$7, $10])
      storeSpuList.forEach(function (sSpu) {
        if (sSpu.matched) return;
        for (var j = 0; j < csvSpuList.length; j++) {
          var cSpu = csvSpuList[j];
          if (cSpu.matched) continue;
          // Check if all prices in sSpu exist in cSpu unused rows
          var tempPool = cSpu.rows.filter(function (r) { return !r.used; });
          var chosen = [];
          var allFound = true;
          for (var v = 0; v < sSpu.vars.length; v++) {
            var targetPk = sSpu.vars[v].pKey;
            var hitIdx = -1;
            for (var m = 0; m < tempPool.length; m++) {
              if (tempPool[m].pKey === targetPk) {
                hitIdx = m;
                break;
              }
            }
            if (hitIdx === -1) {
              allFound = false;
              break;
            }
            chosen.push(tempPool[hitIdx]);
            tempPool.splice(hitIdx, 1);
          }
          if (allFound) {
            sSpu.matched = true;
            cSpu.matched = true;
            for (var v2 = 0; v2 < sSpu.vars.length; v2++) {
              sSpu.vars[v2].matchedCsv = chosen[v2];
              chosen[v2].used = true;
            }
            break;
          }
        }
      });

      // Pass 3: Individual Exact-Price Fallback for any remaining store variation
      storeSpuList.forEach(function (sSpu) {
        sSpu.vars.forEach(function (sv) {
          if (sv.matchedCsv) return;
          for (var k = 0; k < csvRecords.length; k++) {
            if (!csvRecords[k].used && csvRecords[k].pKey === sv.pKey) {
              sv.matchedCsv = csvRecords[k];
              csvRecords[k].used = true;
              break;
            }
          }
        });
      });

      // Collect Pairs, Unmatched Store Errors, and Unused CSV Warnings
      storeSpuList.forEach(function (sSpu) {
        sSpu.vars.forEach(function (sv) {
          if (!sv.matchedCsv) {
            errors.push('商城在售商品未找到同价脱敏项：[' + sv.spuName + ' · 货号 ' + sv.rawSku + ' · 售价 $' + sv.pKey + ']');
            pairs.push({
              rawSku: sv.rawSku,
              spuName: sv.spuName,
              specLabel: sv.specLabel,
              storePrice: sv.pKey,
              safeSku: '',
              aTitle: '❌ 未找到同价匹配项',
              aPrice: '-',
              ok: false
            });
          } else {
            pairs.push({
              rawSku: sv.rawSku,
              spuName: sv.spuName,
              specLabel: sv.specLabel,
              storePrice: sv.pKey,
              safeSku: sv.matchedCsv.safeSku,
              aTitle: sv.matchedCsv.title + (sv.matchedCsv.spec ? ' (' + sv.matchedCsv.spec + ')' : ''),
              aPrice: sv.matchedCsv.pKey,
              ok: true
            });
          }
        });
      });

      csvRecords.forEach(function (rec) {
        if (!rec.used) {
          warnings.push('CSV 第 ' + rec.lineNo + ' 行 [' + rec.safeSku + ' · ' + rec.title + ' · 售价 $' + rec.pKey + '] 在当前站点无对应在售规格（商城未上架该规格），已自动跳过。');
        }
      });
    } else {
      // Case B: Direct 2-column CSV (raw_sku, safe_sku)
      var cRaw = idxRawSku !== -1 ? idxRawSku : 0;
      var cSafe = idxSkuCode !== -1 ? idxSkuCode : 1;
      var storeBySku = {};
      storeItems.forEach(function (it) {
        storeBySku[String(it.raw_sku || '').trim().toUpperCase()] = it;
      });
      var startRow = (rows[0][0] && rows[0][0].toLowerCase().indexOf('sku') !== -1) ? 1 : 0;
      for (var i = startRow; i < rows.length; i++) {
        var rSkuRaw = String(rows[i][cRaw] || '').trim();
        var rSkuKey = rSkuRaw.toUpperCase();
        var sSku = String(rows[i][cSafe] || '').trim().toUpperCase();
        if (!rSkuRaw && !sSku) continue;
        var vErr = checkSafeSkuViolation(sSku);
        if (vErr) errors.push('第 ' + (i + 1) + ' 行脱敏号 [' + sSku + '] 不合规：' + vErr);
        var stItem = storeBySku[rSkuKey];
        var finalRawSku = stItem && stItem.raw_sku ? String(stItem.raw_sku).trim() : rSkuRaw;
        if (storeItems.length && !stItem) {
          warnings.push('第 ' + (i + 1) + ' 行原货号 [' + finalRawSku + '] 不在当前店铺在售列表中（仍可写入备用）。');
        }
        pairs.push({
          rawSku: finalRawSku,
          spuName: stItem ? stItem.spu_name : '自定义原货号',
          specLabel: stItem ? stItem.spec_label : '',
          storePrice: stItem ? priceKey(stItem.price) : '-',
          safeSku: sSku,
          aTitle: '直接指定映射',
          aPrice: stItem ? priceKey(stItem.price) : '-',
          ok: !vErr && !!finalRawSku && !!sSku
        });
      }
    }

    var validEntries = pairs.filter(function (p) { return p.ok && p.rawSku && p.safeSku; }).map(function (p) {
      return { raw_sku: p.rawSku, safe_catalog_id: p.safeSku };
    });

    // Render Diagnostic Summary
    var htmlDiag = '<div class="scapi-grid-4" style="margin-top:14px;">' +
      '<div class="scapi-kpi"><div class="label">当前站点在售规格</div><div class="val">' + storeItems.length + '</div></div>' +
      '<div class="scapi-kpi"><div class="label">精准配对成功</div><div class="val" style="color:#16a34a;">' + validEntries.length + '</div></div>' +
      '<div class="scapi-kpi"><div class="label">脱敏表冗余跳过</div><div class="val" style="color:#d97706;">' + warnings.length + '</div></div>' +
      '<div class="scapi-kpi"><div class="label">阻断级错误</div><div class="val" style="color:#dc2626;">' + errors.length + '</div></div>' +
    '</div>';

    if (errors.length > 0) {
      htmlDiag += '<div class="scapi-diag-box err"><strong>❌ 检测到 ' + errors.length + ' 处阻断问题（请修正后再提交）：</strong><ul style="margin:6px 0 0 18px;">' +
        errors.slice(0, 15).map(function (e) { return '<li>' + escHtml(e) + '</li>'; }).join('') +
        '</ul></div>';
    } else {
      htmlDiag += '<div class="scapi-diag-box ok"><strong>✅ 全部 ' + validEntries.length + ' 个商城在售 SKU 已完成 100% 价格对齐与合规安全体检！</strong> 请核对下方左右对照预览表，点击按钮即可一键写入网关生效。</div>';
    }

    if (warnings.length > 0) {
      htmlDiag += '<div class="scapi-diag-box warn"><strong>⚠️ 差异明细提示（共 ' + warnings.length + ' 条冗余项已自动跳过，不影响在售商品）：</strong><ul style="margin:6px 0 0 18px;">' +
        warnings.slice(0, 10).map(function (wMsg) { return '<li>' + escHtml(wMsg) + '</li>'; }).join('') +
        '</ul></div>';
    }

    if (reportEl) reportEl.innerHTML = htmlDiag;

    if (previewEl) {
      var rowsHtml = pairs.map(function (p, idx) {
        return '<tr style="' + (!p.ok ? 'background:#fef2f2;' : '') + '">' +
          '<td>' + (idx + 1) + '</td>' +
          '<td><strong>' + escHtml(p.spuName) + '</strong>' + (p.specLabel ? '<br/><span style="font-size:11px;color:#64748b;">' + escHtml(p.specLabel) + '</span>' : '') + '</td>' +
          '<td><code>' + escHtml(p.rawSku) + '</code></td>' +
          '<td><strong>$' + escHtml(p.storePrice) + '</strong></td>' +
          '<td style="text-align:center;color:#2563eb;font-weight:700;">⟷</td>' +
          '<td>' + (p.safeSku ? '<span class="scapi-badge ok">' + escHtml(p.safeSku) + '</span>' : '<span class="scapi-badge err">未匹配</span>') + '</td>' +
          '<td>' + escHtml(p.aTitle) + '</td>' +
          '<td>$' + escHtml(p.aPrice) + '</td>' +
          '<td>' + (p.ok ? '<span class="scapi-badge ok">✅ 价格与合规通过</span>' : '<span class="scapi-badge err">❌ 需处理</span>') + '</td>' +
        '</tr>';
      }).join('');
      previewEl.innerHTML = '<h3 style="margin:18px 0 10px;">左右自动配对核验对照表（文件：' + escHtml(fileName) + '）</h3>' +
        '<div class="scapi-dual-scroll"><table class="scapi-table">' +
        '<thead><tr><th>#</th><th>【左】当前站点商品与规格</th><th>原始 SKU</th><th>商城售价</th><th>配对</th><th>【右】对应脱敏 SKU</th><th>A 站对应商品与规格</th><th>A 站售价</th><th>校验结论</th></tr></thead>' +
        '<tbody>' + rowsHtml + '</tbody></table></div>';
    }

    if (formEl && jsonInput && submitBtn) {
      if (errors.length === 0 && validEntries.length > 0) {
        jsonInput.value = JSON.stringify(validEntries);
        submitBtn.textContent = '确认匹配无误 · 一键同步写入网关（共 ' + validEntries.length + ' 条）';
        formEl.style.display = 'block';
      } else {
        jsonInput.value = '';
        formEl.style.display = 'none';
      }
    }
  }

  d.addEventListener('DOMContentLoaded', function () {
    w.StealthHubUI.initDualSkuMatcher();
    w.StealthHubUI.initRoxyHub();
  });
})(window, document);
