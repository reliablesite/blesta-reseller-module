#!/usr/bin/env python3
"""Synthetic loopback fixture acceptance: php -S 127.0.0.1:8765 tests/fixture.php."""
import urllib.request
import os
BASE = os.environ.get('RS_KVM_FIXTURE_URL', 'http://127.0.0.1:8765')
from html.parser import HTMLParser
class Links(HTMLParser):
    def __init__(self): super().__init__(); self.links=[]
    def handle_starttag(self, tag, attrs):
        if tag=='a': self.links.append(dict(attrs))
for view in ('client','admin','service'):
    with urllib.request.urlopen(BASE + '/?view='+view) as response: html=response.read().decode()
    links=Links(); links.feed(html)
    launch=[a for a in links.links if a.get('href')=='/console']
    assert len(launch)==1, f'{view}: expected one Instant KVM link'
    assert launch[0].get('target')=='_blank' and set(launch[0].get('rel','').split()) >= {'noopener','noreferrer'}, view
    with urllib.request.urlopen(BASE + '/?view='+view+'&state=unavailable') as response: unavailable=response.read().decode()
    assert 'href="/console"' not in unavailable, view+': capability must gate button'
    if view!='service':
        assert 'Enable KVM' in html and 'remote_ip' in html, view+': legacy controls preserved'
    print('PASS synthetic '+view+': new-tab capability gate and legacy preservation')
with urllib.request.urlopen(BASE + '/console') as response: html=response.read().decode()
assert '<!doctype html>' in html.lower() and 'id="instant-kvm"' in html, 'Dedicated full document required'
assert 'id="kvm-config"' in html and 'id="kvm-retry"' in html, 'Config and retry required'
assert '100dvh' in html and 'no-referrer' in html, 'Viewport and privacy contract'
assert '<iframe' not in html and 'class="rs-module"' not in html, 'No iframe or Blesta module chrome'
assert 'components/modules/' not in html, 'Console cannot depend on blocked public component assets'
with urllib.request.urlopen(BASE + '/console?state=unavailable') as response: unavailable=response.read().decode()
assert 'Instant KVM is unavailable for this server.' in unavailable
assert '>null</script>' in unavailable, 'Unavailable must not auto-launch'
print('PASS standalone document: full viewport, inline assets, unavailable state')
for method in ('GET', 'POST'):
    req = urllib.request.Request(BASE + '/response', method=method)
    with urllib.request.urlopen(req) as response:
        assert response.headers['Cache-Control'] == 'no-store'
        assert response.headers['Referrer-Policy'] == 'no-referrer'
        assert response.headers['X-Content-Type-Options'] == 'nosniff'
        content_type = 'application/json' if method == 'POST' else 'text/html'
        assert response.headers['Content-Type'].startswith(content_type)
        body = response.read().decode()
        assert 'framework fallthrough' not in body
        if method == 'POST':
            import json
            assert 'SYNTHETIC' in json.loads(body)['JavascriptUrl']
        else:
            assert '<!doctype html>' in body.lower() and 'SYNTHETIC response fixture' in body
print('PASS actual HTTP emitter: no-store HTML/JSON, no-referrer, nosniff, exits before framework fallthrough')
