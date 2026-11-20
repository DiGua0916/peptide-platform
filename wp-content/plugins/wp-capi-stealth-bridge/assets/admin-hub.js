/* WP CAPI Stealth Bridge V3.2 Ultimate - Admin Hub Interactive Controls */
(function (w, d) {
  'use strict';

  w.StealthHubUI = {
    openReplaceModal: function (siteKey, slotId, slotRole, currentLabel) {
      var modal = d.getElementById('scapi-replace-modal');
      if (!modal) return;
      var fSite = modal.querySelector('input[name="site_key"]');
      var fSlot = modal.querySelector('input[name="slot_id"]');
      var fRole = modal.querySelector('select[name="slot_role"]');
      var fLabel = modal.querySelector('input[name="bm_label"]');
      if (fSite) fSite.value = siteKey || '';
      if (fSlot) fSlot.value = slotId || '';
      if (fRole && slotRole) fRole.value = slotRole;
      if (fLabel) fLabel.value = currentLabel || '';
      modal.classList.add('open');
    },
    closeReplaceModal: function () {
      var modal = d.getElementById('scapi-replace-modal');
      if (modal) modal.classList.remove('open');
    },
    confirmPromote: function (formEl, isColdLocked, warmupDays, minDays) {
      if (!isColdLocked) {
        return w.confirm('确认将该已满静默期的热备槽位原子晋升为当前站点的 PRIMARY 主力槽位吗？');
      }
      var ok = w.confirm(
        '【7天冷置保护锁警告】该槽位当前仅静默预热 ' +
          warmupDays +
          ' 天（未满标准 ' +
          minDays +
          ' 天）！\n提前接户跑流有较高风控概率。是否确认以超级管理员权限强制突破冷置锁晋升？'
      );
      if (ok) {
        var forceInput = formEl.querySelector('input[name="force_override"]');
        if (forceInput) forceInput.value = '1';
      }
      return ok;
    }
  };
})(window, document);
