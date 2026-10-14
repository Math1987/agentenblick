#!/usr/bin/env python3
"""Provision an isolated Shopware 6.6 root/database in the disposable container.

Never points a console command at the existing 6.7 root. Heavy commands are
terminated if the host system disk falls below 3 GiB. Local credentials stay
in .local/compat-sw66-state.json (0600), never in evidence or normal output.
"""
import argparse
import json
import os
from pathlib import Path
import secrets
import shlex
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
CONTAINER = 'agentenblick-shop'
TARGET = '/tmp/agentenblick-sw66'
DATABASE = 'agentenblick_sw66'
ORIGIN = 'http://agentenblick-sw66.localhost:18080'
STATE = ROOT / '.local/compat-sw66-state.json'
COMPOSER = '/var/www/html/vendor/bin/composer'
LIMIT = 3 * 1024 ** 3


def run(*args, input=None, check=True):
    result = subprocess.run(args, input=input, text=True, capture_output=True)
    if check and result.returncode:
        raise RuntimeError('Command failed: ' + args[0] + ' (exit ' + str(result.returncode) + ')\n' + result.stderr[-2500:])
    return result.stdout.strip()


def execute(*args, user='www-data', cwd=TARGET, check=True):
    return run('docker', 'exec', '-u', user, '-w', cwd, CONTAINER,
               'env', '-u', 'DATABASE_URL', '-u', 'APP_SECRET', '-u', 'APP_URL',
               '-u', 'MAILER_DSN', '-u', 'AGENTENBLICK_TEST_CATALOG',
               'APP_ENV=prod', 'APP_DEBUG=0', *args, check=check)


def write(path, contents, mode=0o640, user='root'):
    code = 'import os,pathlib,sys; p=pathlib.Path(sys.argv[1]);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(sys.stdin.read());os.chmod(p,int(sys.argv[2]))'
    return run('docker', 'exec', '-i', '-u', user, CONTAINER, 'python3', '-c', code, path, str(mode), input=contents)


def save(state):
    STATE.parent.mkdir(exist_ok=True)
    STATE.write_text(json.dumps(state, indent=2) + '\n')
    STATE.chmod(0o600)


def heavy(*args, cwd='/tmp'):
    if shutil.disk_usage('/').free < LIMIT:
        raise RuntimeError('System disk below 3 GiB: stopped before heavy command')
    pidfile = '/tmp/agentenblick-sw66-install.pid'
    command = 'echo $$ > ' + shlex.quote(pidfile) + '; exec ' + shlex.join(args)
    with tempfile.TemporaryFile(mode='w+t') as output:
        process = subprocess.Popen(['docker', 'exec', '-u', 'www-data', '-w', cwd,
            '-e', 'COMPOSER_HOME=/tmp/agentenblick-sw66-composer',
            '-e', 'COMPOSER_CACHE_DIR=/tmp/agentenblick-sw66-composer/cache',
            '-e', 'APP_ENV=prod', '-e', 'APP_DEBUG=0', CONTAINER, 'sh', '-c', command],
            stdout=output, stderr=subprocess.STDOUT, text=True)
        while process.poll() is None:
            if shutil.disk_usage('/').free < LIMIT:
                run('docker', 'exec', '-u', 'root', CONTAINER, 'sh', '-c',
                    'test -f ' + pidfile + ' && kill -TERM "$(cat ' + pidfile + ')"', check=False)
                process.wait(timeout=30)
                raise RuntimeError('System disk crossed 3 GiB: isolated install terminated')
            time.sleep(1)
        output.seek(0)
        text = output.read()
        if process.returncode:
            raise RuntimeError('Isolated install failed:\n' + text[-6000:])
        return text


def sql(statement):
    return run('docker', 'exec', '-i', '-u', 'root', '-e', 'MYSQL_PWD=root', CONTAINER,
               'mysql', '--protocol=TCP', '-h127.0.0.1', '-uroot', '--batch', '--skip-column-names', input=statement)


def console(*args):
    return execute('php', TARGET + '/bin/console', *args, '--no-ansi', '--no-interaction')


def bootstrap():
    if STATE.exists():
        raise RuntimeError('A compatibility state already exists; resume its explicit next phase')
    if run('docker', 'inspect', '--format', '{{.Name}}', CONTAINER) != '/' + CONTAINER:
        raise RuntimeError('Unexpected container')
    execute('test', '!', '-e', TARGET, cwd='/tmp')
    if sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='agentenblick_sw66'") != '0':
        raise RuntimeError('The isolated compatibility database already exists')
    state = {'version': '6.6.10.29', 'root': TARGET, 'database': DATABASE, 'origin': ORIGIN,
             'dbPassword': secrets.token_urlsafe(24), 'appSecret': secrets.token_hex(32),
             'adminUsername': 'compat-admin', 'adminPassword': secrets.token_urlsafe(24),
             'startedAt': time.time(), 'diskBeforeBytes': shutil.disk_usage('/').free}
    save(state)
    heavy('php', COMPOSER, 'create-project', 'shopware/production:6.6.10.29', TARGET,
          '--no-dev', '--prefer-dist', '--no-interaction', '--no-scripts')
    state['dependenciesInstalled'] = True
    state['diskAfterInstallBytes'] = shutil.disk_usage('/').free
    save(state)
    print(json.dumps({'phase': 'dependencies-installed', 'version': state['version'], 'hostFreeGiB': round(state['diskAfterInstallBytes']/1024**3, 2)}))


def initialize():
    state = json.loads(STATE.read_text())
    if not state.get('dependenciesInstalled') or state.get('databaseInitialized'):
        raise RuntimeError('Compatibility state is not ready for database initialization')
    sql("CREATE DATABASE agentenblick_sw66 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'agentenblick_sw66'@'localhost' IDENTIFIED BY '" + state['dbPassword'] + "'; GRANT ALL ON agentenblick_sw66.* TO 'agentenblick_sw66'@'localhost';")
    state['databaseCreated'] = True
    save(state)
    environment = '\n'.join(['APP_ENV=prod', 'APP_DEBUG=0', 'APP_URL='+ORIGIN,
        'APP_SECRET='+state['appSecret'], 'DATABASE_URL=mysql://agentenblick_sw66:'+state['dbPassword']+'@127.0.0.1:3306/agentenblick_sw66',
        'MAILER_DSN=null://null', 'MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=false',
        'SHOPWARE_ES_ENABLED=0', 'SHOPWARE_ES_INDEXING_ENABLED=0', 'SHOPWARE_ES_THROW_EXCEPTION=0', ''])
    write(TARGET+'/.env.local', environment)
    execute('chown', 'www-data:www-data', TARGET+'/.env.local', user='root')
    console('system:install', '--basic-setup', '--shop-name=Agentenblick Compatibility 6.6')
    state['databaseInitialized'] = True
    save(state)
    print(json.dumps({'phase': 'database-initialized', 'version': state['version']}))


def expose():
    state = json.loads(STATE.read_text())
    if not state.get('databaseInitialized'):
        raise RuntimeError('Initialize the isolated database first')
    console('system:config:set', '--json', 'core.mailerSettings.disableDelivery', 'true')
    console('system:config:set', '--json', 'AithosAgentenblick.config.heuristicsEnabled', 'false')
    console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'true')
    # Console-generated credentials and tokens never appear in ordinary output.
    if not state.get('vhostConfigured'):
        console('user:create', state['adminUsername'], '--admin', '--email=compat-admin@example.invalid',
                '--firstName=Compatibility', '--lastName=Agentenblick', '--password='+state['adminPassword'])
    console('assets:install')
    vhost = '''<VirtualHost *:80>
    ServerName agentenblick-sw66.localhost
    DocumentRoot /tmp/agentenblick-sw66/public
    DirectoryIndex index.php
    <Directory /tmp/agentenblick-sw66/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        CGIPassAuth On
    </Directory>
    <FilesMatch \\.php$>
        SetHandler "proxy:unix:/var/run/php/php8.2-fpm.sock|fcgi://localhost"
    </FilesMatch>
    ErrorLog /tmp/agentenblick-sw66-apache-error.log
    CustomLog /dev/null combined
</VirtualHost>
'''
    write('/etc/apache2/sites-available/agentenblick-sw66.conf', vhost, 0o644)
    execute('a2ensite', 'agentenblick-sw66.conf', user='root')
    execute('apache2ctl', 'configtest', user='root')
    execute('apache2ctl', 'graceful', user='root')
    state['vhostConfigured'] = True
    state['hostFreeBytes'] = shutil.disk_usage('/').free
    save(state)
    print(json.dumps({'phase': 'vhost-ready', 'origin': ORIGIN, 'hostFreeGiB': round(state['hostFreeBytes']/1024**3, 2), 'apacheGracefulReload': True}))


def plugin():
    state = json.loads(STATE.read_text())
    if not state.get('databaseInitialized'):
        raise RuntimeError('Initialize the isolated database first')
    destination = TARGET+'/custom/plugins/AithosAgentenblick'
    execute('mkdir', '-p', destination, user='root')
    execute('python3', '-c', 'import pathlib,shutil;p=pathlib.Path("'+destination+'/src");shutil.rmtree(p) if p.exists() else None', user='root')
    # A real earlier schema makes plugin:update exercise the new migrations.
    archive = ROOT / '.local/compat-sw66-baseline.tar'
    run('git', '-C', str(ROOT), 'archive', '--format=tar', '--output='+str(archive),
        '4786b97', 'src', 'composer.json', 'tests/fixtures')
    run('docker', 'cp', str(archive), CONTAINER+':/tmp/agentenblick-sw66-baseline.tar')
    execute('tar', '-xf', '/tmp/agentenblick-sw66-baseline.tar', '-C', destination, user='root')
    archive.unlink()
    execute('rm', '/tmp/agentenblick-sw66-baseline.tar', user='root')
    execute('chown', '-R', 'www-data:www-data', destination, user='root')
    execute('python3', '-c', 'import pathlib,shutil;p=pathlib.Path("'+TARGET+'/var/cache");shutil.rmtree(p);p.mkdir()')
    console('system:config:set', '--json', 'core.mailerSettings.disableDelivery', 'true')
    console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'true')
    console('system:config:set', '--json', 'AithosAgentenblick.config.heuristicsEnabled', 'false')
    heavy('php', COMPOSER, 'require', 'aithos/agentenblick:^0.1.0', '--update-no-dev',
          '--no-interaction', '--no-scripts', cwd=TARGET)
    console('plugin:refresh')
    if not state.get('pluginInstalled'):
        console('plugin:install', '--activate', 'AithosAgentenblick')
    console('database:migrate', 'AithosAgentenblick', '--all')
    console('scheduled-task:register')
    console('cache:clear')
    state['pluginInstalled'] = True
    state['baselineRef'] = '4786b97'
    save(state)
    print(json.dumps({'phase': 'plugin-installed', 'selftest': json.loads(console('agentenblick:selftest'))}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('phase', choices=['bootstrap', 'initialize', 'expose', 'plugin'])
    args = parser.parse_args()
    {'bootstrap': bootstrap, 'initialize': initialize, 'expose': expose, 'plugin': plugin}[args.phase]()
