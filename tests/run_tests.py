#!/usr/bin/env python3
"""Run deterministic KVM tests and lint. No privileged/live provider calls."""
from pathlib import Path
import os
import socket
import subprocess
import sys
import time
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
output = []
def run(args, env=None):
    result = subprocess.run(args, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=env)
    output.append('$ ' + ' '.join(map(str, args)) + '\n' + result.stdout)
    print(result.stdout, end='')
    if result.returncode:
        raise RuntimeError('Failed: ' + ' '.join(map(str, args)))

server = None
try:
    for test in ['instant_kvm_test.php', 'module_instant_kvm_test.php', 'config_test.php']:
        run(['php', '-d', 'error_reporting=32767', '-d', 'display_errors=1', 'tests/' + test])
    run(['php', '-n', 'tests/transport_test.php'])
    run(['node', 'tests/instant_kvm_js_test.js'])
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', 'tests/fixture.php'], cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    base = f'http://127.0.0.1:{port}'
    for attempt in range(50):
        try:
            with urllib.request.urlopen(base, timeout=1) as response:
                if response.status == 200: break
        except OSError:
            time.sleep(.1)
    else:
        raise RuntimeError('Synthetic fixture failed to start')
    run([sys.executable, 'tests/ui_test.py'], env={**os.environ, 'RS_KVM_FIXTURE_URL': base})
    files = sorted(p for p in ROOT.rglob('*') if p.suffix in ('.php', '.pdt') and '.git' not in p.parts)
    for path in files:
        command = ['php', '-n', '-l', str(path)] if path.name == 'transport_test.php' else ['php', '-l', str(path)]
        result = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if result.returncode: raise RuntimeError(result.stdout)
    line = f'PASS PHP/PDT lint: {len(files)} files\n'
    print(line, end=''); output.append(line)
    run(['git', 'diff', '--check'])
finally:
    if server:
        server.terminate(); server.wait(timeout=5)
    artifacts = ROOT / 'tests/artifacts'
    artifacts.mkdir(exist_ok=True)
    (artifacts / 'latest-tests.txt').write_text('\n'.join(output))
