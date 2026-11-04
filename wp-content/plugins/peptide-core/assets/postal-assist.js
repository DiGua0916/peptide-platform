/* Native Woo Blocks address actions only. No React DOM value writes, address logs or persistent browser storage. */
(function(global){
'use strict';
const fields=['country','state','city','address_1','address_2','postcode'];
const text=value=>typeof value==='string'?value.trim().replace(/\s+/gu,' '):'';
const address=value=>Object.fromEntries(fields.map(key=>[key,text(value?.[key])]));
const fingerprint=value=>JSON.stringify(fields.filter(key=>key!=='postcode').map(key=>text(value?.[key])));
function eligible(value){const a=address(value);return !a.postcode&&['US','PR'].includes(a.country)&&!!a.city&&a.address_1.length>=5&&/\d/u.test(a.address_1)&&/\p{L}/u.test(a.address_1)&&(a.country==='PR'||/^[A-Z]{2}$/u.test(a.state));}
const validZip=(zip,country)=>typeof zip==='string'&&/^\d{5}(?:-\d{4})?$/u.test(zip)&&(country==='US'||(country==='PR'&&/^(006|007|009)/u.test(zip)));
function createController({read,lookup,write,consent=()=>false,busy=()=>false,report=()=>{},changed=()=>{}}){
    let running=false,stopped=false;const attempted=new Map(),manual=new Map();
    function userPostcode(group){if(['shipping','billing'].includes(group))manual.set(group,fingerprint(read()?.[group+'Address']));}
    async function run(){
        if(running||stopped||busy())return;running=true;
        try{for(const group of ['shipping','billing']){
            if(stopped||busy())break;
            const original=read()?.[group+'Address'],a=address(original),key=fingerprint(original),allowExternal=consent()===true;
            if(!eligible(a)||manual.get(group)===key||attempted.get(group)===key+'|'+allowExternal)continue;
            attempted.set(group,key+'|'+allowExternal);
            let result;try{result=await lookup(a,allowExternal);}catch(_){report('manual');continue;}
            const current=read()?.[group+'Address'];
            if(stopped||busy()||!eligible(current)||fingerprint(current)!==key||manual.get(group)===key||allowExternal!== (consent()===true))continue;
            if(result?.state!=='matched'||!validZip(result.postcode,a.country))continue;
            // Merge the current native address (including name/email/extensions), changing postcode only.
            try{const confirmed=await write(group,{...current,postcode:result.postcode});if(confirmed===true)report('filled');else report('manual');}catch(_){report('manual');}
        }}finally{running=false;if(!stopped)changed();}
    }
    return {run,userPostcode,stop(){stopped=true;},running:()=>running};
}
if(typeof module==='object'&&module.exports)module.exports={address,fingerprint,eligible,validZip,createController};
if(!global?.document||global.PCPostalAssist?.enabled!==true)return;
const config=global.PCPostalAssist,document=global.document;
function mount(){
    const checkout=document.querySelector('.wc-block-checkout'),data=global.wp?.data,key=global.wc?.wcBlocksData?.CART_STORE_KEY||'wc/store/cart';
    if(!checkout||!data?.select||!data?.dispatch||!data?.subscribe)return;
    const select=data.select(key),actions=data.dispatch(key);
    if(!select?.getCustomerData||!actions?.setShippingAddress||!actions?.setBillingAddress||!actions?.updateCustomerData)return;
    let endpoint;try{endpoint=new URL(config.endpoint,global.location.href);if(endpoint.origin!==global.location.origin||!['http:','https:'].includes(endpoint.protocol))return;}catch(_){return;}
    const panel=document.createElement('aside');panel.className='pc-postal-assist';panel.style.cssText='margin:0 0 20px;padding:14px 16px;border:1px solid #d4e4e7;border-radius:10px;color:#315762;font-size:13px;line-height:1.5';
    const notice=document.createElement('p');notice.textContent=config.strings.notice;notice.style.margin='0';panel.append(notice);
    let checkbox=null;
    if(config.externalEnabled===true){const label=document.createElement('label');label.style.cssText='display:flex;gap:10px;align-items:flex-start;min-height:44px;margin-top:12px';checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.style.cssText='width:20px;height:20px;margin:2px 0;flex:none';const span=document.createElement('span');span.textContent=config.strings.consent;label.append(checkbox,span);panel.append(label);}
    const status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');status.style.margin='8px 0 0';panel.append(status);checkout.before(panel);
    let timer=null,requests=0,ended=false;
    const schedule=()=>{if(ended)return;clearTimeout(timer);timer=setTimeout(()=>controller.run(),900);};
    const nativeBusy=()=>!!(select.isCustomerDataUpdating?.()||select.hasPendingItemsOperations?.()||global.wp?.data?.select('wc/store/checkout')?.isProcessing?.());
    const controller=createController({read:()=>select.getCustomerData(),busy:nativeBusy,consent:()=>checkbox?.checked===true,report:kind=>{status.textContent=config.strings[kind]||'';},changed:()=>{},lookup:async(a,allowExternal)=>{
        if(++requests>10)return{state:'manual'};
        const response=await global.fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-WP-Nonce':config.nonce},body:JSON.stringify({address:a,allow_external:allowExternal}),signal:AbortSignal.timeout(6000)});
        if(!response.ok)return{state:'manual'};return response.json();
    },write:async(group,a)=>{
        if(nativeBusy())return false;
        actions[group==='shipping'?'setShippingAddress':'setBillingAddress'](a);
        // editing=true prevents a stale response replacing fields the shopper edits during the request.
        const result=await actions.updateCustomerData({[group+'_address']:a},true);
        return result?.[group+'_address']?.postcode===a.postcode&&result[group+'_address'].country===a.country;
    }});
    const stop=data.subscribe(schedule,key);checkbox?.addEventListener('change',schedule);
    const input=event=>{const target=event.target;if(!target||target.tagName!=='INPUT')return;const id=target.id||'',autocomplete=target.getAttribute('autocomplete')||'';if(!/postcode|postal-code/u.test(id+' '+autocomplete))return;const group=/billing/u.test(id+' '+autocomplete)?'billing':(/shipping/u.test(id+' '+autocomplete)?'shipping':null);if(group)controller.userPostcode(group);};
    document.addEventListener('input',input,true);global.addEventListener('pagehide',()=>{ended=true;clearTimeout(timer);controller.stop();stop?.();document.removeEventListener('input',input,true);},{once:true});schedule();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})(typeof window==='undefined'?null:window);
