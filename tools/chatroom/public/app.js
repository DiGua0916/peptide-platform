"use strict";
const $ = (selector) => document.querySelector(selector);
const state = {messages: [], author: "全部", query: "", revision: "", etag: "", loading: false, logPath: "", online: false};
const authors = {Codex:{className:"codex",avatar:"C",role:"前端设计与开发"},Claude:{className:"claude",avatar:"✳",role:"后端业务与接口"},"用户":{className:"user",avatar:"你",role:"项目负责人"}};
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
function formatTime(value){const date=new Date(value);return Number.isNaN(date.getTime())?value:new Intl.DateTimeFormat("zh-CN",{month:"2-digit",day:"2-digit",hour:"2-digit",minute:"2-digit",hour12:false}).format(date);}
function visibleMessages(){return state.messages.filter(m=>(state.author==="全部"||m.author===state.author)&&(!state.query||(m.title+"\n"+m.body+"\n"+m.task+"\n"+m.author).toLowerCase().includes(state.query)));}
function render(){
  $("#nav-count").textContent=state.messages.length;$("#total-count").textContent=state.messages.length;
  const messages=visibleMessages();$("#visible-count").textContent=messages.length+" 条记录";
  if(!messages.length){
    const filtered=state.author!=="全部"||state.query;
    $("#messages").innerHTML='<div class="empty"><span class="empty-symbol">···</span><h2>'+ (filtered?"暂时没有匹配的记录":"第一条记录，从这里开始")+'</h2><p>'+(filtered?"换个参与者或搜索词试试。":"你可以留言，Codex 和 Claude 完成任务后也会追加进度。")+"</p></div>";return;
  }
  $("#messages").innerHTML=messages.map(m=>{
    const author=authors[m.author];return '<article class="message '+(m.author==="用户"?"user-message":"")+'" id="message-'+escapeHtml(m.id)+'"><span class="avatar '+author.className+'" aria-hidden="true">'+author.avatar+'</span><div class="message-card"><div class="message-meta"><strong>'+escapeHtml(m.author==="用户"?"你":m.author)+'</strong><span class="role-label">'+author.role+'</span><span class="tag '+escapeHtml(m.kind)+'">'+escapeHtml(kinds[m.kind]||"记录")+'</span>'+(m.task?'<span class="task-label">'+escapeHtml(m.task)+"</span>":"")+'<time datetime="'+escapeHtml(m.time)+'" title="'+escapeHtml(m.time)+'">'+escapeHtml(formatTime(m.time))+'</time></div><h2 class="message-title">'+escapeHtml(m.title)+'</h2><div class="markdown">'+renderMarkdown(m.body)+"</div></div></article>";
  }).join("");
}
function setConnection(online){state.online=online;$("#sync-dot").classList.toggle("offline",!online);$("#sync-label").textContent=online?"记录已同步":"连接中断 · 请重新打开聊天室";}
async function refresh(force=false){
  if(state.loading)return;state.loading=true;
  try{
    const response=await fetch("/api/messages",{headers:force||!state.etag?{}:{"If-None-Match":state.etag}});
    if(response.status===304){setConnection(true);return;}
    if(!response.ok)throw Error("读取失败");
    const data=await response.json();state.etag=response.headers.get("ETag")||"";
    const changed=state.revision!==data.revision;const wasLoaded=Boolean(state.revision);const oldCount=state.messages.length;
    state.messages=data.messages;state.revision=data.revision;state.logPath=data.logPath;
    $("#record-path").title=data.logPath;$("#warning").hidden=!data.warnings.length;$("#warning").textContent=data.warnings.join(" ");
    if(changed){render();if(wasLoaded&&oldCount<data.messages.length)$("#jump-latest").hidden=false;}
    setConnection(true);
  }catch{setConnection(false);if(!state.revision){$("#messages").innerHTML='<div class="empty"><h2>暂时无法读取记录</h2><p>请双击项目里的“打开项目聊天室.cmd”，然后刷新。</p></div>';$("#visible-count").textContent="连接失败";}}
  finally{state.loading=false;}
}
document.querySelectorAll("[data-author]").forEach(button=>button.addEventListener("click",()=>{state.author=button.dataset.author;document.querySelectorAll("[data-author]").forEach(b=>{const selected=b===button;b.classList.toggle("selected",selected);b.setAttribute("aria-pressed",String(selected));});render();}));
$("#search").addEventListener("input",event=>{state.query=event.target.value.trim().toLowerCase();render();});
$("#refresh").addEventListener("click",()=>refresh(true));
$("#jump-latest").addEventListener("click",()=>{document.querySelector(".message:last-child")?.scrollIntoView({behavior:"smooth",block:"center"});$("#jump-latest").hidden=true;});
$("#message-form").addEventListener("submit",async event=>{
  event.preventDefault();const input=$("#message-input"),body=input.value.trim();if(!body||$("#send-button").disabled)return;
  const status=$("#send-status");$("#send-button").disabled=true;status.className="";status.textContent="正在保存到 Markdown…";
  try{
    const response=await fetch("/api/messages",{method:"POST",headers:{"Content-Type":"application/json","X-Peptide-Room":"1"},body:JSON.stringify({title:"项目留言",body})});
    const data=await response.json();if(!response.ok)throw Error(data.error||"保存失败");
    input.value="";status.textContent="已写入记录 · 请提醒对方读取";status.className="success";
    state.author="全部";state.query="";$("#search").value="";document.querySelectorAll("[data-author]").forEach(b=>{const selected=b.dataset.author==="全部";b.classList.toggle("selected",selected);b.setAttribute("aria-pressed",String(selected));});
    // A poll may already be in flight; refresh again after it completes.
    while(state.loading)await new Promise(resolve=>setTimeout(resolve,60));await refresh(true);$("#jump-latest").hidden=true;document.getElementById("message-"+data.id)?.scrollIntoView({behavior:"smooth",block:"center"});
  }catch(error){status.className="error";status.textContent=error.message||"写入失败，内容已保留";}
  finally{$("#send-button").disabled=false;}
});
$("#message-input").addEventListener("keydown",event=>{if((event.ctrlKey||event.metaKey)&&event.key==="Enter"){event.preventDefault();$("#message-form").requestSubmit();}});
$("#source-button").addEventListener("click",async()=>{
  const dialog=$("#source-dialog");$("#source-content").textContent="正在读取…";$("#source-path").textContent=state.logPath;dialog.showModal();
  try{const response=await fetch("/room.md");if(!response.ok)throw Error();$("#source-content").textContent=await response.text();}catch{$("#source-content").textContent="读取失败，请检查服务是否运行。";}
});
$("#close-source").addEventListener("click",()=>$("#source-dialog").close());
$("#copy-source").addEventListener("click",async()=>{try{await navigator.clipboard.writeText($("#source-content").textContent);$("#copy-source").textContent="已复制";}catch{$("#copy-source").textContent="请选中原文复制";}setTimeout(()=>$("#copy-source").textContent="复制原文",1800);});
refresh();setInterval(()=>{if(!document.hidden)refresh();},2500);
document.addEventListener("visibilitychange",()=>{if(!document.hidden)refresh(true);});
