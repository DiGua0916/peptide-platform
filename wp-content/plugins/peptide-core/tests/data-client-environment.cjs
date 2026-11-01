/* Actual seven-group renderer/export helper; reuse027 DOM/API boundary, no browser/network. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const file=path.join(__dirname,'data-conversion-export.cjs'),source=fs.readFileSync(file,'utf8'),marker='(async()=>{';assert.equal(source.split(marker).length,2);
const box={require,__dirname,console,URL,URLSearchParams,setImmediate,process};vm.runInNewContext(source.slice(0,source.indexOf(marker))+'\nglobalThis.ui043={harness,data,raw,flush};',box,{filename:file});
const {harness,data,raw,flush}=box.ui043;let passed=0;const check=(name,yes)=>{assert.ok(yes,name);++passed;};
(async()=>{
 const h=harness('conversion');check('real selector exposes exactly seven groups',h.el('group').children.length===7&&h.el('group').children.map(e=>e.getAttribute('value')).join(',')==='device,browser,utm,domain,country,os,screen');
 h.last().resolve(data());await flush();h.el('group').value='os';h.el('group').fire('change');check('OS uses existing request and disables stale export',h.last().params.group==='os'&&h.el('export').disabled);
 const os=data('os');os.rows[0].label='Windows';os.rows[0].dimension={state:'value',value:'Windows'};os.dimension_coverage={unit:'visits',base_count:5,value:2,unknown:1,unavailable:1,unobserved:1};h.last().resolve(os);await flush();
 check('typed OS title and separate coverage are displayed',h.el('result').innerHTML.includes('Windows')&&h.el('result').innerHTML.includes('dimensionCoverage')&&!h.el('export').disabled);
 h.el('export').fire('click');const stale=h.last();check('OS CSV snapshots accepted group and dates',stale.params.group==='os'&&stale.params.format==='csv'&&stale.params.from===os.range.dates.from);
 h.el('group').value='screen';h.el('group').fire('change');stale.resolve(raw());await flush();check('late OS CSV cannot download after screen selection',h.downloads.length===0&&h.el('export').disabled&&h.last().params.group==='screen');
 const screen=data('screen');screen.rows[0].label='1920 × 1080 CSS pixels';screen.rows[0].dimension={state:'value',value:'1920x1080'};h.last().resolve(screen);await flush();check('screen uses backend CSS-pixel display identity',h.el('result').innerHTML.includes('1920 × 1080 CSS pixels')&&!h.el('export').disabled);
 h.el('export').fire('click');check('screen CSV retains selected group',h.last().params.group==='screen');h.last().resolve(raw());await flush();check('accepted screen CSV alone creates a download',h.downloads.length===1);
 const unknown=data('os');unknown.rows[0].label='Operating system unknown';unknown.rows[0].dimension={state:'unknown',value:null};h.el('group').value='os';h.el('group').fire('change');h.last().resolve(unknown);await flush();check('OS unknown is not relabeled Country unknown',h.el('result').innerHTML.includes('Operating system unknown')&&!h.el('result').innerHTML.includes('Country unknown'));
 console.log(JSON.stringify({suite:'seven-conversion-groups',passed,failed:0,browser:false,network:false}));
})().catch(error=>{console.error(error);process.exitCode=1;});
