#!/usr/bin/env python3
"""Provision only the isolated Compose bench, including the real termination gate."""
import json
from pathlib import Path
import subprocess
import sys
import time
from acceptance import CONTAINER, PLUGIN, ROOT, execute, console, sql, run


def write(path, content):
    code = 'from pathlib import Path; import sys; p=Path(sys.argv[1]); p.parent.mkdir(parents=True,exist_ok=True); p.write_text(sys.argv[2])'
    execute('python3', '-c', code, path, content, user='root')


def environment(name):
    code = "from pathlib import Path; import sys; keys=('APP_ENV=','APP_DEBUG=','AGENTENBLICK_TEST_CATALOG='); p=Path(sys.argv[1]); lines=p.read_text().splitlines() if p.exists() else []; p.write_text('\\n'.join(x for x in lines if not x.startswith(keys))+'\\nAPP_ENV='+sys.argv[2]+'\\nAPP_DEBUG=0\\n'+('AGENTENBLICK_TEST_CATALOG='+sys.argv[3]+'\\n' if sys.argv[2]=='test' else ''))"
    for path in ['/var/www/html/.env', '/var/www/html/.env.local']:
        execute('python3', '-c', code, path, name, PLUGIN + '/tests/fixtures/catalog.json', user='root')


def ensure_app_secret():
    # The disposable image ships a short demo secret, rejected by modern HS256.
    # Keep an adequate existing value so rerunning setup does not revoke logins.
    code = "from pathlib import Path; import secrets; p=Path('/var/www/html/.env'); lines=p.read_text().splitlines(); current=next((x.split('=',1)[1].strip(chr(34)+chr(39)) for x in lines if x.startswith('APP_SECRET=')), ''); secret=current if len(current)>=64 else secrets.token_hex(32); p.write_text('\\n'.join(x for x in lines if not x.startswith('APP_SECRET='))+'\\nAPP_SECRET='+secret+'\\n'); q=Path('/var/www/html/.env.local'); lines=q.read_text().splitlines() if q.exists() else []; q.write_text('\\n'.join(x for x in lines if not x.startswith('APP_SECRET='))+'\\nAPP_SECRET='+secret+'\\n'); t=Path('/var/www/html/.env.test.local'); lines=t.read_text().splitlines() if t.exists() else []; t.write_text('\\n'.join(x for x in lines if not x.startswith('APP_SECRET='))+'\\nAPP_SECRET='+secret+'\\n')"
    execute('python3', '-c', code, user='root')


def copy(source, destination):
    local = str(ROOT / source) + ('/.' if source.endswith('/.') else '')
    run('docker', 'cp', local, CONTAINER + ':' + destination)


if __name__ == '__main__':
    subprocess.run(['docker', 'compose', '-f', str(ROOT / 'compose.yaml'), 'up', '-d', 'shop'], check=True)
    for _ in range(180):
        try:
            execute('mysqladmin', '--protocol=TCP', '-h127.0.0.1', '-uroot', '-proot', 'ping')
            if 'container IS READY' in run('docker', 'logs', '--tail', '80', CONTAINER):
                break
        except subprocess.CalledProcessError:
            pass
        time.sleep(2)
    else:
        raise RuntimeError('Dockware did not become ready')
    environment('prod')
    ensure_app_secret()
    sql("UPDATE sales_channel_domain SET url='http://127.0.0.1:18080' WHERE url NOT LIKE 'default.headless%'")
    console('plugin:deactivate', 'DockwareSamplePlugin')
    execute('mkdir', '-p', PLUGIN + '/dev', user='root')
    for folder in ['src', 'tests', 'scripts']:
        copy(folder, PLUGIN + '/')
    for filename in ['composer.json', 'phpunit.xml', 'phpstan.neon', '.php-cs-fixer.dist.php']:
        copy(filename, PLUGIN + '/')
    copy('dev/tools', PLUGIN + '/dev/')
    copy('dev/probe', PLUGIN + '/dev/')
    copy('dev/probe/.', '/var/www/html/custom/plugins/AgentenblickTerminateProbe')
    execute('chown', '-R', 'www-data:www-data', PLUGIN, '/var/www/html/custom/plugins/AgentenblickTerminateProbe', user='root')
    execute('php', '/var/www/html/vendor/bin/composer', 'require', 'bakame/http-structured-fields:^2.0', 'phpseclib/phpseclib:^3.0', '--working-dir=/var/www/html', '--no-interaction', '--no-scripts')
    console('plugin:refresh')
    console('plugin:install', '--activate', 'AgentenblickTerminateProbe')
    console('plugin:activate', 'AgentenblickTerminateProbe')
    console('cache:clear')
    try:
        subprocess.run([sys.executable, str(ROOT / 'scripts/measure-terminate.py')], check=True)
    finally:
        console('plugin:deactivate', 'AgentenblickTerminateProbe')
    # Development stops if the real web server did not flush before termination.
    environment('test')
    copy('dev/services_test.php', '/var/www/html/config/services_test.php')
    write('/var/www/html/config/packages/agentenblick-test-proxy.yaml', "when@test:\n  framework:\n    trusted_proxies: '127.0.0.1'\n    trusted_headers: ['x-forwarded-for', 'x-forwarded-host']\n")
    console('plugin:install', '--activate', 'AithosAgentenblick')
    console('plugin:activate', 'AithosAgentenblick')
    console('database:migrate', 'AithosAgentenblick', '--all')
    console('cache:clear')
    console('scheduled-task:register')
    # Shopware's test filesystem is in-memory: install public assets in prod.
    run('docker', 'exec', '-u', 'www-data', '-e', 'APP_ENV=prod', '-e', 'APP_DEBUG=0', CONTAINER, 'php', '/var/www/html/bin/console', 'assets:install')
    execute('php', '/var/www/html/vendor/bin/composer', 'install', '--working-dir=' + PLUGIN + '/dev/tools', '--no-interaction', '--no-scripts', '--no-plugins')
    subprocess.run(['docker', 'exec', '-u', 'root', CONTAINER, 'pkill', '-f', '^node ' + PLUGIN + '/scripts/test-proxy.mjs$'], check=False)
    run('docker', 'exec', '-d', '-u', 'www-data', CONTAINER, 'bash', '-lc', 'node ' + PLUGIN + '/scripts/test-proxy.mjs > /tmp/agentenblick-proxy.log 2>&1')
    print(console('agentenblick:selftest'))
