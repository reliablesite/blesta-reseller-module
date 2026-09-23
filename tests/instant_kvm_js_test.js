'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path').join(__dirname, '../views/default/js/rs-instant-kvm.js');
const scriptUrl = 'https://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js?token=SYNTHETIC%2B&v=1';
async function runFixture(response, config = {origins:['https://kvmproxy-ny1.reliablesite.net'], csrf:'csrf', nonce:'nonce'}) {
    const scripts = [], requests = [], elements = {};
    for (const id of ['instant-kvm','kvm-status','kvm-message','kvm-retry','kvm-config']) elements[id] = {hidden:false, textContent:'', addEventListener(type, fn) {this[type]=fn;}};
    elements['kvm-config'].textContent = JSON.stringify(config);
    const context = {URL, URLSearchParams, document:{getElementById(id){return elements[id];}, createElement(tag){assert.equal(tag,'script');return {dataset:{}};}, body:{appendChild(script){scripts.push(script);}}}, location:{href:'https://billing.example/blesta/client/services/manage/11/tabClientManage/?p=instantkvm', reload(){context.reloaded=true;}}, fetch:async(url,options)=>{requests.push({url,options}); if(response instanceof Error)throw response;return response;}};
    vm.runInNewContext(fs.readFileSync(path,'utf8'), context);
    await new Promise(resolve=>setImmediate(resolve));
    return {scripts,requests,elements,context};
}
function response(data, status=200, type='application/json') { return {ok:status===200,status,headers:{get(){return type;}},json:async()=>data}; }
(async()=>{
    assert.ok(fs.existsSync(path), 'Missing standalone console browser code');
    const r=await runFixture(response({JavascriptUrl:scriptUrl}));
    assert.equal(r.requests.length,1); assert.equal(r.requests[0].options.method,'POST');
    assert.equal(r.requests[0].options.cache,'no-store'); assert.equal(r.requests[0].options.redirect,'error');
    assert.equal(r.requests[0].options.credentials,'same-origin');
    const body=new URLSearchParams(r.requests[0].options.body); assert.equal(body.get('_csrf_token'),'csrf'); assert.equal(body.get('nonce'),'nonce'); assert.equal(body.get('action'),'instant_kvm_launch');
    assert.equal(r.scripts.length,1); assert.equal(r.scripts[0].src,scriptUrl);
    assert.equal(r.scripts[0].dataset.target,'instant-kvm'); assert.equal(r.scripts[0].referrerPolicy,'no-referrer');
    console.log('PASS browser mount: one protected POST, exact provider script, data-target, no-referrer');
    for (const bad of [response({JavascriptUrl:'https://evil.test/x.js'}), response({JavascriptUrl:'http://kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js'}), response({JavascriptUrl:'https://u@kvmproxy-ny1.reliablesite.net/embed/v1/kvm.js'}), response({JavascriptUrl:'https://kvmproxy-ny1.reliablesite.net/console.html'}), response({JavascriptUrl:scriptUrl+'#bad'}), response({JavascriptUrl:scriptUrl+'\n'}), response({javascriptUrl:scriptUrl}), response({error:'SECRET'},429), response({error:'SECRET'},403), response({error:'SECRET'},502), response({},200,'text/html')]) {
        const f=await runFixture(bad); assert.equal(f.requests.length,1); assert.equal(f.scripts.length,0,'invalid response must never insert script'); assert.equal(f.elements['kvm-retry'].hidden,false); assert.ok(!f.elements['kvm-message'].textContent.includes('SECRET'));
    }
    const failure=await runFixture(new Error('SECRET timeout')); assert.equal(failure.requests.length,1); assert.equal(failure.scripts.length,0); assert.equal(failure.elements['kvm-retry'].hidden,false);
    const unavailable=await runFixture(response({}),null); assert.equal(unavailable.requests.length,0);
    r.scripts[0].onerror(); assert.equal(r.elements['kvm-retry'].hidden,false); assert.equal(r.requests.length,1); assert.equal(r.scripts.length,1);
    r.elements['kvm-retry'].click(); assert.equal(r.context.reloaded,true);
    const mounted=await runFixture(response({JavascriptUrl:scriptUrl})); mounted.scripts[0].onload(); assert.equal(mounted.elements['kvm-status'].hidden,true);
    console.log('PASS browser failures: URL gate, safe errors, explicit fresh-document retry, no automatic retries, mounted overlay hidden');
})().catch(error=>{console.error(error.message);process.exit(1);});
