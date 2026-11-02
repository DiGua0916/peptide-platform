'use strict';
window.createAnnouncementTicker = function ({ element, config, context, scrollRoot, dialog }) {
  const model = window.VialCoveTickerModel;
  let settings = model.normalizeConfig(config);
  let values = { ...context };
  let sequence = model.createSequence(settings.items);
  let animation = null;
  let visible = false;
  let pausedByUser = false;
  let destroyed = false;
  let measureFrame = 0;
  const line = element.querySelector('.announcement-line');
  const text = element.querySelector('.announcement-copy');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const templateValues = () => ({...values,name:settings.name || ''});
  const canPlay = () => !destroyed && visible && !document.hidden && !pausedByUser && settings.enabled && settings.items.length > 0
    && (!dialog || dialog.open) && ![...document.querySelectorAll('dialog[open]')].some(d => d !== dialog);
  function paint(target, value) {
    const words = settings.keywords.map(w => model.renderText(w, templateValues())).filter(Boolean).sort((a,b) => b.length-a.length);
    target.replaceChildren();
    const message=document.createElement('span'); message.className='announcement-message';
    target.append(message);
    if (!words.length) { message.textContent = value; appendBadge(target); return; }
    const escaped = words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const re = new RegExp('(' + escaped.join('|') + ')', 'giu');
    let start=0;
    for (const m of value.matchAll(re)) {
      message.append(document.createTextNode(value.slice(start,m.index)));
      const strong=document.createElement('strong'); strong.textContent=m[0]; message.append(strong);
      start=m.index+m[0].length;
    }
    message.append(document.createTextNode(value.slice(start)));
    appendBadge(target);
  }
  function appendBadge(target) {
    if(settings.badgeEnabled === false) return;
    const badge=document.createElement('span'); badge.className='announcement-hot';
    const flame=document.createElement('span'); flame.className='announcement-flame'; flame.setAttribute('aria-hidden','true'); flame.textContent='🔥';
    badge.append(flame,document.createTextNode(' HOT'));
    target.append(document.createTextNode(' '),badge);
  }
  function measure() {
    cancelAnimationFrame(measureFrame);
    measureFrame=requestAnimationFrame(() => {
      if (!element.clientWidth || !settings.items.length) return;
      const probe=document.createElement('div');
      probe.className='announcement-measure';
      for(const item of settings.items) {
        const row=document.createElement('span'); row.className='announcement-copy';
        paint(row,model.renderText(item,templateValues())); probe.append(row);
      }
      element.append(probe);
      const tallest=Math.max(24,...[...probe.children].map(e=>e.getBoundingClientRect().height));
      element.style.setProperty('--announcement-height',Math.ceil(tallest+24)+'px');
      probe.remove();
    });
  }
  function sync() {
    if (!animation) return;
    if (canPlay()) animation.play(); else animation.pause();
  }
  function show() {
    animation?.cancel(); animation=null;
    element.hidden = !settings.enabled || !settings.items.length;
    if(element.hidden) return;
    const copy=model.renderText(sequence.current(),templateValues());
    paint(text,copy);
    element.dataset.itemIndex=String(sequence.index());
    element.dataset.itemCount=String(settings.items.length);
    element.setAttribute('aria-label',copy+(settings.badgeEnabled===false?'':'. HOT')+'. '+(pausedByUser?'Resume announcements':'Pause announcements'));
    element.setAttribute('aria-pressed',String(pausedByUser));
    const hold=Math.max(settings.holdMs,Math.min(15000,copy.length*45));
    const total=settings.enterMs+hold+settings.exitMs+settings.gapMs;
    const start=reduced.matches?'translateX(0)':'translateX(-105%)';
    const end=reduced.matches?'translateX(0)':'translateX(105%)';
    animation=line.animate([
      {transform:start,opacity:0,offset:0,easing:'cubic-bezier(.22,1,.36,1)'},
      {transform:'translateX(0)',opacity:1,offset:settings.enterMs/total},
      {transform:'translateX(0)',opacity:1,offset:(settings.enterMs+hold)/total,easing:'cubic-bezier(.55,0,.8,.4)'},
      {transform:end,opacity:0,offset:(settings.enterMs+hold+settings.exitMs)/total},
      {transform:end,opacity:0,offset:1}
    ],{duration:total,fill:'both'});
    animation.onfinish=()=>{ if(destroyed) return; sequence.next(); show(); };
    sync();
  }
  function apply(next) {
    const candidate=model.normalizeConfig(next);
    if(candidate.enabled && !candidate.name && candidate.items.some(item=>item.includes('{name}'))) {
      throw new Error('Announcement text uses {name}, but no name is configured.');
    }
    settings=candidate;
    sequence=model.createSequence(settings.items);
    pausedByUser=false; show(); measure();
  }
  function visibilityChanged(){sync();}
  function motionChanged(){show();}
  function togglePause() {
    pausedByUser=!pausedByUser;
    element.setAttribute('aria-pressed',String(pausedByUser));
    element.setAttribute('aria-label',model.renderText(sequence.current(),templateValues())+(settings.badgeEnabled===false?'':'. HOT')+'. '+(pausedByUser?'Resume announcements':'Pause announcements'));
    sync();
  }
  element.addEventListener('click',togglePause);
  document.addEventListener('visibilitychange',visibilityChanged);
  reduced.addEventListener('change',motionChanged);
  const io='IntersectionObserver' in window ? new IntersectionObserver(entries=>{
    visible=entries[0].isIntersecting && entries[0].intersectionRatio>=.5; sync();
  },{root:scrollRoot,threshold:[0,.5]}) : null;
  if(io) io.observe(element); else visible=true;
  const ro=new ResizeObserver(measure); ro.observe(element);
  const mo=new MutationObserver(sync);
  mo.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['open']});
  apply(config);
  return {
    updateContext(next) {
      values={...next};
      if(!element.hidden && sequence.current()!==null) {
        const copy=model.renderText(sequence.current(),templateValues());
        paint(text,copy);
        element.setAttribute('aria-label',copy+(settings.badgeEnabled===false?'':'. HOT')+'. '+(pausedByUser?'Resume announcements':'Pause announcements'));
      }
      measure();
    },
    configure:apply,
    destroy() { destroyed=true; animation?.cancel(); cancelAnimationFrame(measureFrame); io?.disconnect();ro.disconnect();mo.disconnect();element.removeEventListener('click',togglePause);document.removeEventListener('visibilitychange',visibilityChanged);reduced.removeEventListener('change',motionChanged); }
  };
};
