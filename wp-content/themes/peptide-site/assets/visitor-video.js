/* Optional HTML video observer. watched_s is visible-document playback time,
 * not evidence of human attention. No media URL, storage or network is used. */
(() => {
  'use strict';
  const PROGRESS_INTERVAL = 15000;
  const seconds = value => Number.isFinite(value) ? Math.round(Math.max(0, value) * 1000) / 1000 : null;
  function observe(root, emit) {
    const doc = root?.nodeType === 9 ? root : root?.ownerDocument;
    const view = doc?.defaultView;
    if (!root?.querySelectorAll || !view?.HTMLVideoElement || typeof emit !== 'function') return {stop() {}};
    const active = new Map(), known = new WeakMap(), pageOrder = new WeakMap();
    let nextOrdinal = 0, stopped = false, suspended = false;
    doc.querySelectorAll('video').forEach(video => pageOrder.set(video, ++nextOrdinal));
    const now = () => view.performance.now();
    const inRoot = video => video.isConnected && (root === video || root.contains(video));
    const canCount = record => !suspended && doc.visibilityState === 'visible' && inRoot(record.video) && record.playing && !record.waiting && !record.seeking && !record.video.seeking && !record.video.paused && !record.video.ended;
    function accrue(record, time) {
      if (record.baseline !== null) { record.watched += Math.max(0, time - record.baseline) / 1000; record.baseline = time; }
    }
    function resetClock(record, time) { record.baseline = canCount(record) ? time : null; }
    function publish(type, record) {
      if (stopped) return;
      const video = record.video;
      // Pause/end already carry the final watched time. A later visibility or
      // page-exit flush must not describe that same interval as still playing.
      if (type === 'video_pause' || type === 'video_end') {
        record.lastProgressAt = now();
        record.lastProgressWatched = record.watched;
      }
      const data = {video_id: record.id, module_id: video.closest('[data-module]')?.id || null,
        position_s: seconds(video.currentTime), duration_s: seconds(video.duration), watched_s: seconds(record.watched),
        autoplay: Boolean(video.autoplay), playback_origin: record.origin};
      try { emit(type, data); } catch { /* Analytics must not interfere with playback. */ }
    }
    function attach(video) {
      if (stopped || !(video instanceof view.HTMLVideoElement) || active.has(video) || !inRoot(video)) return;
      let record = known.get(video);
      if (!record) {
        if (!pageOrder.has(video)) pageOrder.set(video, ++nextOrdinal);
        record = {video, id: video.getAttribute('data-visitor-video-id')?.trim() || video.id || `video-${pageOrder.get(video)}`,
          watched: 0, origin: 'unknown', hasPlayed: false, gestureAt: -Infinity, baseline: null,
          lastProgressAt: now(), lastProgressWatched: 0, listeners: []};
        known.set(video, record);
      }
      record.playing = false; record.started = false; record.waiting = false; record.seeking = video.seeking;
      active.set(video, record);
      const on = (type, handler) => { video.addEventListener(type, handler); record.listeners.push([type, handler]); };
      const markGesture = event => {
        if (!event.isTrusted || (event.type === 'keydown' && ![' ', 'Enter', 'k', 'K'].includes(event.key))) return;
        record.gestureAt = now();
      };
      on('pointerdown', markGesture); on('keydown', markGesture);
      on('play', () => {
        record.origin = now() - record.gestureAt <= 1500 ? 'user' : (!record.hasPlayed && video.autoplay ? 'autoplay' : 'unknown');
        record.gestureAt = -Infinity;
      });
      const playing = () => {
        const time = now(); accrue(record, time);
        record.playing = true; record.waiting = false; record.seeking = video.seeking; resetClock(record, time);
        if (!record.started) {
          record.started = true; record.hasPlayed = true; record.lastProgressAt = time; record.lastProgressWatched = record.watched;
          publish('video_play', record);
        }
      };
      on('playing', playing);
      on('waiting', () => { accrue(record, now()); record.waiting = true; record.baseline = null; });
      on('seeking', () => { accrue(record, now()); record.seeking = true; record.baseline = null; });
      on('seeked', () => {
        const wasSeeking = record.seeking, time = now(); accrue(record, time);
        record.seeking = false; resetClock(record, time);
        if (wasSeeking) {
          publish('video_seek', record);
          // The protocol stores video_seek as "seeking". Seeking has now
          // finished, so immediately report the resulting playback state.
          publish(video.ended ? 'video_end' : (video.paused ? 'video_pause' : 'video_play'), record);
        }
      });
      on('pause', () => {
        accrue(record, now()); record.baseline = null; record.playing = false;
        const wasStarted = record.started; record.started = false;
        if (wasStarted && !video.ended) publish('video_pause', record);
      });
      on('ended', () => {
        accrue(record, now()); record.baseline = null; record.playing = false; record.started = false;
        publish('video_end', record);
      });
      const resetPlayback = () => {
        accrue(record, now()); record.baseline = null; record.playing = false; record.started = false;
        record.waiting = false; record.seeking = video.seeking; record.origin = 'unknown';
      };
      on('emptied', resetPlayback); on('error', resetPlayback);
      if (!video.paused && !video.ended && !video.seeking && video.readyState >= 3) playing();
    }
    function detach(record) {
      accrue(record, now()); record.baseline = null;
      record.listeners.forEach(([type, handler]) => record.video.removeEventListener(type, handler));
      record.listeners = []; active.delete(record.video);
    }
    function scan(node) { if (node instanceof view.HTMLVideoElement) attach(node); node.querySelectorAll?.('video').forEach(attach); }
    function refreshClocks() { const time = now(); active.forEach(record => { accrue(record, time); resetClock(record, time); }); }
    // Capture the final partial interval before the caller suspends delivery.
    function flush() {
      if (stopped) return;
      const time = now();
      active.forEach(record => {
        accrue(record, time); resetClock(record, time);
        if (!record.playing || record.video.paused || record.video.ended) return;
        if (record.watched <= record.lastProgressWatched) return;
        record.lastProgressAt = time; record.lastProgressWatched = record.watched;
        publish('video_progress', record);
      });
    }
    const hidePage = () => { suspended = true; refreshClocks(); };
    const showPage = () => { suspended = false; refreshClocks(); };
    doc.addEventListener('visibilitychange', refreshClocks); view.addEventListener('pagehide', hidePage); view.addEventListener('pageshow', showPage);
    const observer = new view.MutationObserver(changes => {
      active.forEach(record => { if (!inRoot(record.video)) detach(record); });
      changes.forEach(change => change.addedNodes.forEach(scan));
    });
    observer.observe(root, {childList: true, subtree: true}); scan(root);
    const timer = view.setInterval(() => {
      const time = now();
      active.forEach(record => {
        accrue(record, time); resetClock(record, time);
        if (record.baseline === null || time - record.lastProgressAt < PROGRESS_INTERVAL || record.watched <= record.lastProgressWatched) return;
        record.lastProgressAt = time; record.lastProgressWatched = record.watched; publish('video_progress', record);
      });
    }, 1000);
    return {flush, stop() {
      if (stopped) return;
      stopped = true; observer.disconnect(); view.clearInterval(timer);
      doc.removeEventListener('visibilitychange', refreshClocks); view.removeEventListener('pagehide', hidePage); view.removeEventListener('pageshow', showPage);
      active.forEach(detach);
    }};
  }
  window.VialCoveVisitorVideo = {observe};
})();
