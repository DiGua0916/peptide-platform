"use strict";
const $ = (selector) => document.querySelector(selector);
const state = {messages: [], rooms: [], roomId: new URLSearchParams(location.search).get("room") || "peptide", epoch: 0, author: "全部", query: "", revision: "", etag: "", loading: false, logPath: "", online: false, sending: false, expanded: new Set(), collapsed: new Set()};
function roomUrl(path, roomId=state.roomId) {return path+"?room="+encodeURIComponent(roomId);}
function currentRoom() {return state.rooms.find(r=>r.id===state.roomId);}
function renderRooms() {
  $("#room-count").textContent=String(state.rooms.length).padStart(2,"0");
  $("#room-list").innerHTML=state.rooms.map(r=>'<button class="conversation '+(r.id===state.roomId?'selected':'')+'" data-room="'+escapeHtml(r.id)+'" aria-pressed="'+(r.id===state.roomId)+'" aria-label="'+escapeHtml(r.name)+'"><span class="group-avatar" aria-hidden="true"><span class="mini codex"><img src="/avatars/openai.svg" alt=""></span><span class="mini claude"><img src="/avatars/claude.svg" alt=""></span><span class="mini user"><img src="/avatars/digua.jpg" alt=""></span><span class="mini project"><svg><use href="#i-chat"/></svg></span></span><span class="conversation-copy"><span class="conversation-top"><strong>'+escapeHtml(r.name)+'</strong><time>'+escapeHtml(r.lastTime?formatTime(r.lastTime,true):'')+'</time></span><span class="conversation-preview">'+escapeHtml((r.lastPreview||'地瓜、Codex、Claude · 开始协作').replace(/^用户：/,"地瓜：").replace(/^你、/,"地瓜、"))+'</span></span></button>').join("");
  const room=currentRoom();
  $("#room-name").textContent=room?.name||"选择群聊";
  $("#detail-room-name").textContent=room?.name||"";
  $("#detail-project").textContent=room?.projectRoot||"暂未关联项目目录";
  $("#detail-code").textContent=room?.code||"";
  document.title=(room?.name||"协作群聊")+" · 项目聊天室";
}
let roomsLoading=false;
async function refreshRooms() {
  if(roomsLoading)return;roomsLoading=true;
  const epoch=state.epoch;
  try {
    const response=await fetch("/api/rooms");if(!response.ok)throw Error();
    const data=await response.json();if(epoch!==state.epoch)return;state.rooms=data.rooms;
    const resolved=state.rooms.find(r=>[r.id,r.name,r.alias,r.code].some(key=>key.toLowerCase()===state.roomId.toLowerCase()));
    if(resolved)state.roomId=resolved.id;
    renderRooms();
  }catch{if(!state.rooms.length)$("#room-list").innerHTML='<p class="rooms-error">暂时无法读取群聊列表</p>';}
  finally{roomsLoading=false;}
}
async function switchRoom(id) {
  if(state.sending)return;
  updateInput();state.roomId=id;state.epoch++;state.loading=false;state.revision="";state.etag="";
  state.messages=[];state.author="全部";state.query="";state.expanded.clear();state.collapsed.clear();$("#search").value="";
  $("#message-input").value=readDraft();updateInput();
  $("#send-status").textContent="留言后需提醒助手读取";$("#send-status").className="";
  $("#warning").hidden=true;$("#jump-latest").hidden=true;toggleDetails(false);toggleSidebar(false);toggleEmoji(false);
  history.replaceState(null,"",roomUrl("/"));renderRooms();render();
  await refresh(true);
}
const authors = {Codex:{className:"codex",avatar:'<img src="/avatars/openai.svg" alt="">',role:"前端设计与开发"},Claude:{className:"claude",avatar:'<img src="/avatars/claude.svg" alt="">',role:"后端业务与接口"},"用户":{className:"user",avatar:'<img src="/avatars/digua.jpg" alt="">',role:"项目负责人"}};
const kinds = {progress:"进度",message:"消息",decision:"决定",question:"待确认"};
function escapeHtml(value) {return String(value).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));}
function inlineMarkdown(text) {
  const pattern = /(\x60[^\x60]+\x60|\*\*[^*]+\*\*|\[[^\]]+\]\([^)]+\))/g;
  let html = "", start = 0;
  for (const match of text.matchAll(pattern)) {
    html += escapeHtml(text.slice(start,match.index));
    const token = match[0];
    if (token.startsWith("\x60")) html += "<code>"+escapeHtml(token.slice(1,-1))+"</code>";
    else if (token.startsWith("**")) html += "<strong>"+escapeHtml(token.slice(2,-2))+"</strong>";
    else {
      const link = token.match(/^\[([^\]]+)\]\(([^)]+)\)$/);
      let safe = false;
      try {const url = new URL(link[2]);safe = ["https:","http:"].includes(url.protocol);} catch {}
      html += safe ? '<a target="_blank" rel="noopener noreferrer" href="'+escapeHtml(link[2])+'">'+escapeHtml(link[1])+"</a>" : escapeHtml(token);
    }
    start = match.index+token.length;
  }
  return html+escapeHtml(text.slice(start));
}
function renderMarkdown(source) {
  const lines=source.replace(/\r/g,"").split("\n");let html="",paragraph=[],list=null,code=null;
  const flushParagraph=()=>{if(paragraph.length){html+="<p>"+paragraph.map(inlineMarkdown).join("<br>")+"</p>";paragraph=[];}};
  const closeList=()=>{if(list){html+="</"+list+">";list=null;}};
  for(const line of lines){
    if(line.startsWith("\x60\x60\x60")){flushParagraph();closeList();if(code!==null){html+="<pre><code>"+escapeHtml(code.join("\n"))+"</code></pre>";code=null;}else code=[];continue;}
    if(code!==null){code.push(line);continue;}
    if(!line.trim()){flushParagraph();closeList();continue;}
    const heading=line.match(/^(#{1,6})\s+(.+)$/);
    const item=line.match(/^\s*(?:([-*])|(\d+)\.)\s+(.+)$/);
    if(heading){flushParagraph();closeList();html+="<h3>"+inlineMarkdown(heading[2])+"</h3>";}
    else if(item){flushParagraph();const type=item[2]?"ol":"ul";if(list!==type){closeList();list=type;html+="<"+type+">";}html+="<li>"+inlineMarkdown(item[3])+"</li>";}
    else if(/^>\s?/.test(line)){flushParagraph();closeList();html+="<blockquote>"+inlineMarkdown(line.replace(/^>\s?/,""))+"</blockquote>";}
    else if(/^---+$/.test(line.trim())){flushParagraph();closeList();html+="<hr>";}
    else {closeList();paragraph.push(line);}
  }
  flushParagraph();closeList();if(code!==null)html+="<pre><code>"+escapeHtml(code.join("\n"))+"</code></pre>";return html;
}
function formatTime(value, short=false) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  const time = new Intl.DateTimeFormat("zh-CN", {hour:"2-digit",minute:"2-digit",hour12:false}).format(date);
  if(short) return time;
  const today = new Date();
  return (date.toDateString() === today.toDateString() ? "今天" : `${date.getFullYear()}年${date.getMonth()+1}月${date.getDate()}日`) + " " + time;
}
function plainPreview(body) {
  return body.replace(/\[([^\]]+)\]\([^)]+\)/g,"$1").replace(/\*\*|`/g,"").replace(/^#{1,6}\s+/gm,"").replace(/^[-*]\s+/gm,"· ").trim();
}
function visibleMessages() {
  return state.messages.filter(m => (state.author === "全部" || m.author === state.author) && (!state.query || (m.title+"\n"+m.body+"\n"+m.task+"\n"+m.author+"\n"+(m.author==="用户"?"地瓜":"")).toLowerCase().includes(state.query)));
}
function messageBody(message) {
  const long = message.body.length > 340 || message.body.split("\n").length > 10;
  const expanded = state.expanded.has(message.id) || (Boolean(state.query) && !state.collapsed.has(message.id));
  const previewSource = message.body.replace(/^(?:\*\*[^\n]+\*\*|#{1,6}[^\n]+)\s*\n/, "");
  const preview = plainPreview(previewSource).split("\n").filter(line=>line.trim()).slice(0,3).join("\n").slice(0,200).trimEnd();
  return '<div class="markdown" id="body-'+escapeHtml(message.id)+'">'+(long && !expanded ? '<p class="preview-text">'+escapeHtml(preview)+'…</p>' : renderMarkdown(message.body))+'</div>'+ (long ? '<button class="expand-message" data-expand="'+escapeHtml(message.id)+'" aria-controls="body-'+escapeHtml(message.id)+'" aria-expanded="'+expanded+'">'+(expanded?'收起':'展开全文')+'</button>' : '');
}
function render() {
  $("#total-count").textContent = state.messages.length;
  const latest = state.messages.at(-1);
  const active=currentRoom();
  if(active){active.lastTime=latest?.time||"";active.lastPreview=latest?`${latest.author === "用户" ? "地瓜" : latest.author}：${latest.kind === "message" ? plainPreview(latest.body) : latest.title}`:"地瓜、Codex、Claude · 开始协作";renderRooms();}
  const messages = visibleMessages(), filtered = state.author !== "全部" || Boolean(state.query);
  $("#filter-bar").hidden = !filtered;
  $("#filter-label").textContent = [state.author !== "全部" ? (state.author === "用户" ? "地瓜" : state.author) : "", state.query ? `“${state.query}”` : "", `${messages.length} 条消息`].filter(Boolean).join(" · ");
  document.querySelectorAll("[data-author]").forEach(button => {
    const selected = button.dataset.author === state.author;
    button.classList.toggle("selected", selected);button.setAttribute("aria-pressed",String(selected));
  });
  if(!messages.length) {
    $("#messages").innerHTML = '<div class="empty">'+(filtered ? '没有找到相关消息<p>换个关键词，或查看全部消息。</p>' : '还没有消息<p>在下方留下一条项目消息吧。</p>')+'</div>';
    return;
  }
  let previous = null;
  $("#messages").innerHTML = messages.map(m => {
    const author = authors[m.author], isUser = m.author === "用户";
    const date = new Date(m.time);
    const showTime = !previous || date - previous >= 5*60*1000 || date.toDateString() !== previous.toDateString();
    previous = date;
    const time = showTime ? '<div class="time-divider"><time datetime="'+escapeHtml(m.time)+'">'+escapeHtml(formatTime(m.time))+'</time></div>' : '';
    const title = m.kind !== "message" || m.title !== "项目留言" ? '<h2 class="message-title">'+escapeHtml(m.title)+'</h2>' : '';
    const meta = m.kind !== "message" || m.task ? '<div class="message-meta"><span>'+escapeHtml(kinds[m.kind] || "消息")+'</span>'+(m.task ? '<span>'+escapeHtml(m.task)+'</span>' : '')+'</div>' : '';
    return time+'<article class="message '+(isUser ? 'user-message' : '')+'" id="message-'+escapeHtml(m.id)+'"><span class="avatar '+author.className+'" aria-hidden="true">'+author.avatar+'</span><div class="message-content"><div class="sender" title="'+escapeHtml(formatTime(m.time))+'"><span>'+escapeHtml(isUser ? "地瓜" : m.author)+'</span><time datetime="'+escapeHtml(m.time)+'">'+escapeHtml(formatTime(m.time,true))+'</time></div><div class="bubble">'+title+messageBody(m)+meta+'</div></div></article>';
  }).join("");
}
function atBottom() {
  const timeline = $("#timeline");
  return timeline.scrollHeight-timeline.scrollTop-timeline.clientHeight < 70;
}
function scrollToLatest() {
  $("#timeline").scrollTop = $("#timeline").scrollHeight;
  $("#jump-latest").hidden = true;
}
function setConnection(online) {
  state.online=online;$("#sync-dot").classList.toggle("offline",!online);
  $("#sync-label").textContent = online ? "本地记录已同步" : "连接中断，请重新打开聊天室";
}
async function refresh(force=false) {
  if(state.loading) return;
  const epoch=state.epoch, requestedRoom=state.roomId;
  state.loading=true;
  try {
    const response = await fetch(roomUrl("/api/messages",requestedRoom),{headers:force||!state.etag?{}:{"If-None-Match":state.etag}});
    if(epoch!==state.epoch)return;
    if(response.status===304){setConnection(true);return;}
    if(!response.ok){const error=await response.json();throw Error(error.error||"读取失败");}
    const data=await response.json();if(epoch!==state.epoch)return;state.etag=response.headers.get("ETag")||"";
    const changed=state.revision!==data.revision, wasLoaded=Boolean(state.revision), oldCount=state.messages.length;
    const nearBottom=atBottom(), oldScroll=$("#timeline").scrollTop;
    state.messages=data.messages;state.revision=data.revision;state.logPath=data.logPath;
    $("#record-path").title=data.logPath;$("#warning").hidden=!data.warnings.length;$("#warning").textContent=data.warnings.join(" ");
    if(changed) {
      render();
      if(!wasLoaded || (nearBottom && !state.query && state.author === "全部")) scrollToLatest();
      else {$("#timeline").scrollTop=oldScroll;if(oldCount<data.messages.length) $("#jump-latest").hidden=false;}
    }
    setConnection(true);
  } catch(error) {
    if(epoch!==state.epoch)return;
    setConnection(false);
    if(!state.revision) $("#messages").innerHTML='<div class="empty">暂时无法读取记录<p>'+escapeHtml(error.message||"请重新打开聊天室")+'</p></div>';
  } finally {if(epoch===state.epoch)state.loading=false;}
}
function clearFilters() {
  state.author="全部";state.query="";$("#search").value="";render();scrollToLatest();
}
function toggleDetails(open, focus=false) {
  $("#details-panel").hidden=!open;
  document.querySelectorAll("[data-members]").forEach(b=>b.setAttribute("aria-expanded",String(open)));
  if(open){toggleSidebar(false);if(focus) $("#close-details").focus();}
}
function toggleSidebar(open) {
  $("#sidebar").classList.toggle("is-open",open);$("#open-sidebar").setAttribute("aria-expanded",String(open));
}
function toggleEmoji(open) {
  $("#emoji-picker").hidden=!open;$("#emoji-button").setAttribute("aria-expanded",String(open));
}
$("#messages").addEventListener("click", event=>{
  const button=event.target.closest("[data-expand]");if(!button)return;
  const id=button.dataset.expand, article=button.closest("article"), top=article.getBoundingClientRect().top;
  if(button.getAttribute("aria-expanded")==="true") {state.expanded.delete(id);state.collapsed.add(id);}
  else {state.expanded.add(id);state.collapsed.delete(id);}
  render();
  const replacement=document.getElementById("message-"+id);
  $("#timeline").scrollTop+=replacement.getBoundingClientRect().top-top;
  replacement.querySelector("[data-expand]").focus({preventScroll:true});
});
document.querySelectorAll("[data-author]").forEach(button=>button.addEventListener("click",()=>{
  state.author=button.dataset.author;render();$("#timeline").scrollTop=0;$("#jump-latest").hidden=true;toggleDetails(false);$("#timeline").focus();
}));
$("#search").addEventListener("input",event=>{state.query=event.target.value.trim().toLowerCase();state.collapsed.clear();render();$("#timeline").scrollTop=0;$("#jump-latest").hidden=true;});
$("#search").addEventListener("keydown",event=>{if(event.key==="Enter"){toggleSidebar(false);$("#timeline").focus();}});
$("#clear-filter").addEventListener("click",clearFilters);
$("#room-list").addEventListener("click",event=>{const button=event.target.closest("[data-room]");if(button)switchRoom(button.dataset.room);});
$("#chat-button").addEventListener("click",()=>{clearFilters();toggleDetails(false);$("#message-input").focus();});
$("#find-button").addEventListener("click",()=>{toggleDetails(false);toggleSidebar(true);$("#search").focus();});
$("#open-sidebar").addEventListener("click",()=>{toggleDetails(false);toggleSidebar(!$("#sidebar").classList.contains("is-open"));});
$("#close-sidebar").addEventListener("click",()=>{toggleSidebar(false);$("#open-sidebar").focus();});
document.querySelectorAll("[data-members]").forEach(b=>b.addEventListener("click",()=>toggleDetails($("#details-panel").hidden,true)));
$("#close-details").addEventListener("click",()=>{toggleDetails(false);$(".header-actions [data-members]").focus();});
$("#refresh").addEventListener("click",()=>refresh(true));
$("#jump-latest").addEventListener("click",clearFilters);
$("#timeline").addEventListener("scroll",()=>{if(atBottom()&&!state.query&&state.author==="全部")$("#jump-latest").hidden=true;});
function draftKey() {return "collaboration-room-draft-v2-"+state.roomId;}
function readDraft() {
  try {
    const saved=sessionStorage.getItem(draftKey());
    if(saved!==null)return saved;
    if(state.roomId==="peptide"){
      const legacy=sessionStorage.getItem("peptide-room-draft-v1");
      if(legacy!==null){sessionStorage.setItem(draftKey(),legacy);sessionStorage.removeItem("peptide-room-draft-v1");return legacy;}
    }
  }catch{}
  return "";
}
function updateInput() {
  $("#send-button").disabled=state.sending || !$("#message-input").value.trim();
  $("#new-room-button").disabled=state.sending;
  try{sessionStorage.setItem(draftKey(),$("#message-input").value);}catch{}
}
$("#message-input").value=readDraft();
updateInput();
$("#message-input").addEventListener("input",()=>{updateInput();$("#send-status").textContent="留言后需提醒助手读取";$("#send-status").className="";});
$("#message-form").addEventListener("submit",async event=>{
  event.preventDefault();
  const input=$("#message-input"),body=input.value.trim();if(!body||state.sending)return;
  const status=$("#send-status");state.sending=true;input.readOnly=true;updateInput();toggleEmoji(false);status.className="";status.textContent="正在发送…";
  try {
    const response=await fetch(roomUrl("/api/messages"),{method:"POST",headers:{"Content-Type":"application/json","X-Peptide-Room":"1"},body:JSON.stringify({title:"项目留言",body})});
    const data=await response.json();if(!response.ok)throw Error(data.error||"保存失败");
    input.value="";status.textContent="已保存 · 请提醒助手读取";status.className="success";
    state.author="全部";state.query="";$("#search").value="";
    while(state.loading) await new Promise(resolve=>setTimeout(resolve,60));
    await refresh(true);render();scrollToLatest();
  } catch(error) {status.className="error";status.textContent=(error.message==="Failed to fetch"?"连接失败，消息已保留":error.message)||"写入失败，内容已保留";}
  finally {state.sending=false;input.readOnly=false;updateInput();input.focus();}
});
$("#message-input").addEventListener("keydown",event=>{
  if(event.key==="Enter"&&!event.shiftKey&&!event.isComposing&&event.keyCode!==229){event.preventDefault();$("#message-form").requestSubmit();}
});
function insertText(text) {
  const input=$("#message-input");if(state.sending)return;
  if(input.value.length-(input.selectionEnd-input.selectionStart)+text.length>input.maxLength)return;
  input.setRangeText(text,input.selectionStart,input.selectionEnd,"end");input.focus();updateInput();return true;
}
$("#emoji-button").addEventListener("click",()=>toggleEmoji($("#emoji-picker").hidden));
$("#emoji-picker").addEventListener("click",event=>{const button=event.target.closest("button");if(button){insertText(button.textContent);toggleEmoji(false);}});
$("#code-button").addEventListener("click",()=>{if(!insertText("\n```\n\n```\n"))return;const input=$("#message-input");input.setSelectionRange(input.selectionStart-5,input.selectionStart-5);});
document.addEventListener("click",event=>{if(!event.target.closest("#emoji-button, #emoji-picker"))toggleEmoji(false);});
document.addEventListener("keydown",event=>{if(event.key==="Escape"){toggleEmoji(false);toggleDetails(false);toggleSidebar(false);}});
document.querySelectorAll("[data-source]").forEach(button=>button.addEventListener("click",async()=>{
  const sourceRoom=state.roomId;
  const dialog=$("#source-dialog");$("#source-content").textContent="正在读取…";$("#source-path").textContent=state.logPath;dialog.showModal();
  try{const response=await fetch(roomUrl("/room.md",sourceRoom));if(!response.ok)throw Error();const content=await response.text();if(sourceRoom===state.roomId&&dialog.open)$("#source-content").textContent=content;}catch{if(sourceRoom===state.roomId&&dialog.open)$("#source-content").textContent="读取失败，请检查服务是否运行。";}
}));
$("#close-source").addEventListener("click",()=>$("#source-dialog").close());
$("#copy-source").addEventListener("click",async()=>{try{await navigator.clipboard.writeText($("#source-content").textContent);$("#copy-source").textContent="已复制";}catch{$("#copy-source").textContent="请选中原文复制";}setTimeout(()=>$("#copy-source").textContent="复制原文",1800);});
document.querySelectorAll("[data-close]").forEach(b=>b.addEventListener("click",()=>document.getElementById(b.dataset.close).close()));
$("#new-room-button").addEventListener("click",()=>{$("#create-room-status").textContent="";$("#create-room-dialog").showModal();});
async function openJoin() {
  const room=currentRoom();if(!room)return;
  $("#join-room-name").textContent=room.name;$("#join-code").textContent=room.code;$("#join-status").textContent="";
  $("#join-instructions").value="";$("#copy-join-instructions").disabled=true;$("#join-dialog").showModal();
  try {const response=await fetch(roomUrl("/api/join",room.id));if(!response.ok)throw Error();const data=await response.json();if($("#join-dialog").open&&$("#join-code").textContent===room.code){$("#join-instructions").value=data.instructions;$("#copy-join-instructions").disabled=false;}}
  catch{if($("#join-dialog").open&&$("#join-code").textContent===room.code)$("#join-status").textContent="接入说明读取失败，请稍后重试。";}
}
document.querySelectorAll("[data-join]").forEach(b=>b.addEventListener("click",openJoin));
async function copyJoin(text) {try{await navigator.clipboard.writeText(text);$("#join-status").textContent="已复制，可以粘贴到助手的新会话中。";}catch{$("#join-status").textContent="复制不可用，请手动选中上方内容复制。";}}
$("#copy-room-key").addEventListener("click",()=>copyJoin($("#join-code").textContent));
$("#copy-join-instructions").addEventListener("click",()=>copyJoin($("#join-instructions").value));
$("#create-room-form").addEventListener("submit",async event=>{
  event.preventDefault();const button=$("#create-room-submit");if(button.disabled)return;button.disabled=true;$("#create-room-status").textContent="正在创建…";
  try {
    const response=await fetch("/api/rooms",{method:"POST",headers:{"Content-Type":"application/json","X-Peptide-Room":"1"},body:JSON.stringify({name:$("#new-room-name").value.trim(),projectRoot:$("#new-room-project").value.trim()})});
    const data=await response.json();if(!response.ok)throw Error(data.error||"创建失败");
    state.rooms.push(data);$("#create-room-dialog").close();$("#create-room-form").reset();await switchRoom(data.id);await openJoin();
  }catch(error){$("#create-room-status").textContent=error.message||"创建失败，请稍后重试。";}
  finally{button.disabled=false;}
});
async function bootstrap(){await refreshRooms();$("#message-input").value=readDraft();updateInput();await refresh(true);}
bootstrap();setInterval(()=>{if(!document.hidden)refresh();},2500);
setInterval(()=>{if(!document.hidden)refreshRooms();},10000);
document.addEventListener("visibilitychange",()=>{if(!document.hidden){refresh(true);refreshRooms();}});
