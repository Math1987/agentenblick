#!/usr/bin/env python3
"""Qualify lifecycle in an independent Shopware 6.7 root and fresh database.

No existing database, .env, JWT key or user is copied. The vendor tree is a
physical copy; its only package symlink resolves inside the new root. All
plugin writes and lifecycle commands target this isolated root. Run bootstrap
first, then expose/run after coordinating the single Apache graceful reload.
Credentials remain in ignored .local state (0600), never in acceptance JSON.
"""
import argparse
import hashlib
import json
from pathlib import Path
import secrets
import shlex
import shutil
import subprocess
import tarfile
import tempfile
import time
import urllib.error
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
CONTAINER = 'agentenblick-shop'
TARGET = '/tmp/agentenblick-sw67-lifecycle'
DATABASE = 'agentenblick_sw67_lifecycle'
DBUSER = 'ab_sw67_lifecycle'
HOST = 'agentenblick-sw67-lifecycle.localhost'
ORIGIN = 'http://' + HOST + ':18080'
PLUGIN = TARGET + '/custom/plugins/AithosAgentenblick'
STATE = ROOT / '.local/lifecycle-sw67-state.json'
OUT = ROOT / '.local/lifecycle-sw67-acceptance.json'
LIMIT = 3 * 1024 ** 3
BASELINE = '4786b97'


def run(*args, input=None, check=True):
    process = subprocess.run(args, text=True, input=input, capture_output=True)
    if check and process.returncode:
        # Error output is retained only in the ignored local state area; callers
        # never print environment values, command arguments or SQL credentials.
        error = ROOT / '.local/lifecycle-sw67-command-error.log'
        error.write_text(process.stdout[-5000:] + '\n' + process.stderr[-5000:])
        error.chmod(0o600)
        raise RuntimeError('Isolated command failed (exit ' + str(process.returncode) + '); see local command-error log')
    return process.stdout.strip()


def execute(*args, user='www-data', cwd=TARGET, check=True):
    return run('docker', 'exec', '-u', user, '-w', cwd, CONTAINER, 'env',
               '-u', 'DATABASE_URL', '-u', 'APP_SECRET', '-u', 'APP_URL',
               '-u', 'MAILER_DSN', '-u', 'AGENTENBLICK_TEST_CATALOG',
               'APP_ENV=prod', 'APP_DEBUG=0', *args, check=check)


def write(path, value, mode=0o640):
    code = 'import pathlib,os,sys;p=pathlib.Path(sys.argv[1]);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(sys.stdin.read());os.chmod(p,int(sys.argv[2]))'
    run('docker', 'exec', '-i', '-u', 'root', CONTAINER, 'python3', '-c', code, path, str(mode), input=value)


def save(state):
    STATE.parent.mkdir(exist_ok=True)
    STATE.write_text(json.dumps(state, indent=2) + '\n')
    STATE.chmod(0o600)


def sql(statement, database=True):
    return run('docker', 'exec', '-i', '-u', 'root', '-e', 'MYSQL_PWD=root', CONTAINER,
               'mysql', '--protocol=TCP', '-h127.0.0.1', '-uroot', '--batch', '--skip-column-names',
               input=('USE ' + DATABASE + '; ' if database else '') + statement)


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def guard():
    if shutil.disk_usage('/').free < LIMIT:
        raise RuntimeError('System disk below 3 GiB; stopped')
    available = int(run('docker', 'exec', CONTAINER, 'python3', '-c', 'import shutil;print(shutil.disk_usage("/tmp").free)'))
    if available < LIMIT:
        raise RuntimeError('Docker disk below 3 GiB; stopped')


def heavy(*args, user='www-data', cwd=TARGET):
    guard()
    pidfile = TARGET + '/.lifecycle-process.pid'
    command = 'echo $$ > ' + shlex.quote(pidfile) + '; exec ' + shlex.join(args)
    with tempfile.TemporaryFile(mode='w+t') as output:
        process = subprocess.Popen(['docker', 'exec', '-u', user, '-w', cwd, CONTAINER,
            'env', '-u', 'DATABASE_URL', '-u', 'APP_SECRET', '-u', 'APP_URL', '-u', 'MAILER_DSN',
            '-u', 'AGENTENBLICK_TEST_CATALOG', 'APP_ENV=prod', 'APP_DEBUG=0',
            'sh', '-c', command], stdout=output, stderr=subprocess.STDOUT, text=True)
        try:
            while process.poll() is None:
                guard()
                time.sleep(1)
        except Exception:
            run('docker', 'exec', '-u', 'root', CONTAINER, 'python3', '-c',
                'import os,pathlib,signal,sys;p=pathlib.Path(sys.argv[1]);os.kill(int(p.read_text()),signal.SIGTERM) if p.exists() else None', pidfile, check=False)
            process.wait(timeout=30)
            raise
        output.seek(0)
        result = output.read()
        if process.returncode:
            error = ROOT / '.local/lifecycle-sw67-command-error.log'
            error.write_text(result[-10000:])
            error.chmod(0o600)
            raise RuntimeError('Isolated operation failed; see local command-error log')
        return result.strip()


def console(*args):
    return heavy('php', TARGET + '/bin/console', *args, '--no-ansi', '--no-interaction')


def baseline():
    archive = ROOT / '.local/lifecycle-sw67-baseline.tar'
    run('git', '-C', str(ROOT), 'archive', '--format=tar', '--output=' + str(archive), BASELINE, 'src', 'composer.json')
    run('docker', 'cp', str(archive), CONTAINER + ':/tmp/lifecycle-sw67-baseline.tar')
    execute('mkdir', '-p', PLUGIN, cwd='/tmp', user='root')
    execute('tar', '-xf', '/tmp/lifecycle-sw67-baseline.tar', '-C', PLUGIN, cwd='/tmp', user='root')
    execute('rm', '/tmp/lifecycle-sw67-baseline.tar', cwd='/tmp', user='root')
    archive.unlink()


def bootstrap():
    guard()
    require(not STATE.exists(), 'Lifecycle state already exists; resume its next phase')
    require(sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + DATABASE + "'", False) == '0', 'Isolated DB already exists')
    execute('test', '!', '-e', TARGET, cwd='/tmp')
    state = {'shopware': '6.7.15.0', 'root': TARGET, 'database': DATABASE, 'origin': ORIGIN,
             'dbPassword': secrets.token_urlsafe(24), 'appSecret': secrets.token_hex(32),
             'adminUsername': 'lifecycle-admin', 'adminPassword': secrets.token_urlsafe(24),
             'startedAt': time.time(), 'diskBeforeBytes': shutil.disk_usage('/').free, 'baselineRef': BASELINE}
    save(state)
    execute('mkdir', '-p', TARGET, cwd='/tmp', user='root')
    baseline()
    code = '''import pathlib,shutil,json,os
source=pathlib.Path('/var/www/html');target=pathlib.Path('/tmp/agentenblick-sw67-lifecycle')
for name in ('vendor','bin','src'):
    shutil.copytree(source/name,target/name,symlinks=True)
(target/'config/packages').mkdir(parents=True)
(target/'config/routes').mkdir()
for name in ('bundles.php','routes.yaml','services.yaml'):
    shutil.copy2(source/'config'/name,target/'config'/name)
for name in ('lock.yaml','http_discovery.yaml'):
    shutil.copy2(source/'config/packages'/name,target/'config/packages'/name)
(target/'public').mkdir()
for name in ('index.php','.htaccess','.htaccess.dist','maintenance.html'):
    shutil.copy2(source/'public'/name,target/'public'/name)
for name in ('composer.json','composer.lock','symfony.lock'):
    if (source/name).exists():shutil.copy2(source/name,target/name)
manifest=json.loads((target/'composer.json').read_text());manifest['require']['aithos/agentenblick']='^0.1.0';(target/'composer.json').write_text(json.dumps(manifest,indent=2))
for name in ('var/cache','var/log','var/plugins','config/jwt','custom/static-plugins','files'):(target/name).mkdir(parents=True,exist_ok=True)
for link in target.rglob('*'):
    if link.is_symlink() and not link.resolve().is_relative_to(target):raise RuntimeError('Symlink escapes isolated root: '+str(link.relative_to(target)))
a=source/'vendor/autoload.php';b=target/'vendor/autoload.php'
if a.stat().st_ino==b.stat().st_ino:raise RuntimeError('Vendor must be an independent copy')
'''
    heavy('python3', '-c', code, user='root')
    environment = '\n'.join(['APP_ENV=prod', 'APP_DEBUG=0', 'APP_URL=' + ORIGIN,
        'APP_SECRET=' + state['appSecret'], 'DATABASE_URL=mysql://' + DBUSER + ':' + state['dbPassword'] + '@127.0.0.1:3306/' + DATABASE,
        'MAILER_DSN=null://null', 'MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=false',
        'SHOPWARE_ES_ENABLED=0', 'SHOPWARE_ES_INDEXING_ENABLED=0', 'SHOPWARE_ES_THROW_EXCEPTION=0', ''])
    write(TARGET + '/.env', environment)
    write(TARGET + '/.env.local', environment)
    execute('chown', '-R', 'www-data:www-data', TARGET, user='root')
    sql("CREATE DATABASE " + DATABASE + " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '" + DBUSER + "'@'localhost' IDENTIFIED BY '" + state['dbPassword'] + "'; GRANT ALL ON " + DATABASE + ".* TO '" + DBUSER + "'@'localhost';", False)
    state['databaseCreated'] = True
    save(state)
    initialize()


def initialize():
    state = json.loads(STATE.read_text())
    require(state.get('databaseCreated') and not state.get('bootstrapComplete'), 'Resume only an unfinished isolated initialization')
    sql('DROP DATABASE ' + DATABASE + '; CREATE DATABASE ' + DATABASE + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', False)
    # MySQL binary logging requires SUPER while legacy core migrations create
    # triggers. Scope the temporary privilege to this new local test account,
    # and revoke it even if installation fails; no global variable is changed.
    sql("GRANT SUPER ON *.* TO '" + DBUSER + "'@'localhost';", False)
    try:
        console('system:install', '--basic-setup', '--shop-name=Agentenblick Lifecycle 6.7')
    finally:
        sql("REVOKE SUPER ON *.* FROM '" + DBUSER + "'@'localhost';", False)
    console('system:config:set', '--json', 'core.mailerSettings.disableDelivery', 'true')
    console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'true')
    console('system:config:set', '--json', 'AithosAgentenblick.config.heuristicsEnabled', 'false')
    console('user:create', state['adminUsername'], '--admin', '--email=lifecycle-admin@example.invalid', '--firstName=Lifecycle', '--lastName=Agentenblick', '--password=' + state['adminPassword'])
    console('plugin:refresh')
    console('plugin:install', '--activate', 'AithosAgentenblick')
    console('database:migrate', 'AithosAgentenblick', '--all')
    console('assets:install')
    console('cache:clear')
    state['bootstrapComplete'] = True
    state['diskAfterBytes'] = shutil.disk_usage('/').free
    save(state)
    print(json.dumps({'phase': 'bootstrap-complete', 'version': state['shopware'], 'root': TARGET, 'database': DATABASE, 'tables': len(tables()), 'hostFreeGiB': round(state['diskAfterBytes']/1024**3,2)}))


def expose():
    state = json.loads(STATE.read_text())
    require(state.get('bootstrapComplete'), 'Bootstrap must finish first')
    vhost = '''<VirtualHost *:80>
    ServerName agentenblick-sw67-lifecycle.localhost
    DocumentRoot /tmp/agentenblick-sw67-lifecycle/public
    DirectoryIndex index.php
    <Directory /tmp/agentenblick-sw67-lifecycle/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        CGIPassAuth On
    </Directory>
    <FilesMatch \\.php$>
        SetHandler "proxy:unix:/var/run/php/php8.2-fpm.sock|fcgi://localhost"
    </FilesMatch>
    ErrorLog /tmp/agentenblick-sw67-lifecycle-apache-error.log
    CustomLog /dev/null combined
</VirtualHost>
'''
    write('/etc/apache2/sites-available/agentenblick-sw67-lifecycle.conf', vhost, 0o644)
    execute('a2ensite', 'agentenblick-sw67-lifecycle.conf', user='root')
    execute('apache2ctl', 'configtest', user='root')
    execute('apache2ctl', 'graceful', user='root')
    state['vhostReady'] = True
    save(state)
    print(json.dumps({'phase': 'vhost-ready', 'origin': ORIGIN}))


def http(method, path, body=None, token=None, ua='AgentenblickLifecycle/1.0'):
    headers = {'Host': HOST + ':18080', 'User-Agent': ua}
    data = json.dumps(body).encode() if body is not None else None
    if data is not None:headers['Content-Type'] = 'application/json'
    if token:headers['Authorization'] = 'Bearer ' + token
    request = urllib.request.Request('http://127.0.0.1:18080' + path, method=method, data=data, headers=headers)
    try:
        response = urllib.request.build_opener(urllib.request.ProxyHandler({})).open(request, timeout=30)
    except urllib.error.HTTPError as error:
        return error.code, error.read()
    with response:return response.status, response.read()


def tables():
    return sql("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='" + DATABASE + "' AND TABLE_NAME LIKE 'agentenblick\\_%' ORDER BY TABLE_NAME").splitlines()


def snapshot():
    return json.loads(sql("SELECT JSON_OBJECT('requests',(SELECT COUNT(*) FROM agentenblick_request),'sessions',(SELECT COUNT(*) FROM agentenblick_session),'dailyRequests',(SELECT COALESCE(SUM(requests),0) FROM agentenblick_daily),'dailySessions',(SELECT COALESCE(SUM(sessions),0) FROM agentenblick_daily))"))


def final_source(version, archive_path=None, freeze=False):
    """Create an immutable snapshot of the working release; fixture version only here."""
    archive = ROOT / '.local/lifecycle-sw67-release.tar'
    if freeze and archive_path:
        with zipfile.ZipFile(archive_path) as package, tarfile.open(archive, 'w') as output:
            with tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                for entry in package.infolist():
                    relative = Path(entry.filename)
                    require(not relative.is_absolute() and '..' not in relative.parts, 'Unsafe ZIP entry')
                package.extractall(root)
                manifests = list(root.glob('*/composer.json')) + list(root.glob('composer.json'))
                require(len(manifests) == 1, 'Expected one plugin root in ZIP')
                package_root = manifests[0].parent
                for path in package_root.iterdir():output.add(path, arcname=path.name)
    elif freeze:
        with tarfile.open(archive, 'w') as output:
            output.add(ROOT/'src', arcname='src')
            output.add(ROOT/'composer.json', arcname='composer.json')
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    run('docker', 'cp', str(archive), CONTAINER + ':/tmp/lifecycle-sw67-release.tar')
    execute('python3', '-c', 'import pathlib,shutil;root=pathlib.Path("' + PLUGIN + '");[shutil.rmtree(p) if p.is_dir() and not p.is_symlink() else p.unlink() for p in root.iterdir()]')
    execute('tar', '-xf', '/tmp/lifecycle-sw67-release.tar', '-C', PLUGIN)
    execute('python3', '-c', 'import pathlib,json,sys;p=pathlib.Path(sys.argv[1]);d=json.loads(p.read_text());changed=d.get("version")!=sys.argv[2];d["version"]=sys.argv[2];p.write_text(json.dumps(d,indent=2)) if changed else None', PLUGIN+'/composer.json', version)
    execute('rm', '/tmp/lifecycle-sw67-release.tar', user='root')
    execute('python3', '-c', 'import pathlib,shutil;p=pathlib.Path("' + TARGET + '/var/cache");shutil.rmtree(p);p.mkdir()')
    return digest


def verify_installed_zip(archive_path):
    expected = {}
    with zipfile.ZipFile(archive_path) as package:
        manifests = [Path(e.filename) for e in package.infolist() if not e.is_dir() and (e.filename == 'composer.json' or (len(Path(e.filename).parts) == 2 and e.filename.endswith('/composer.json'))) ]
        require(len(manifests) == 1, 'Expected one plugin root in ZIP')
        prefix = manifests[0].parent
        for entry in package.infolist():
            if not entry.is_dir():
                expected[str(Path(entry.filename).relative_to(prefix))] = hashlib.sha256(package.read(entry)).hexdigest()
    code = 'import pathlib,sys,json,hashlib;root=pathlib.Path("' + PLUGIN + '");expected=json.load(sys.stdin);bad=[n for n,h in expected.items() if not (root/n).is_file() or hashlib.sha256((root/n).read_bytes()).hexdigest()!=h];print(json.dumps({"files":len(expected),"mismatches":len(bad)}))'
    result = json.loads(run('docker','exec','-i',CONTAINER,'python3','-c',code,input=json.dumps(expected)))
    require(result['mismatches'] == 0, 'Installed files differ from the qualified ZIP')
    return result


def reset_baseline():
    """Repeat qualification only on this empty, independently provisioned clone."""
    guard()
    state = json.loads(STATE.read_text())
    require(state.get('bootstrapComplete') and state.get('vhostReady'), 'An initialized lifecycle clone is required')
    require(state.get('root') == TARGET and state.get('database') == DATABASE and state.get('shopware') == '6.7.15.0', 'Unexpected lifecycle state')
    identity = execute('php', '-r', 'require "vendor/autoload.php";(new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv(".env");$p=parse_url($_SERVER["DATABASE_URL"]??$_ENV["DATABASE_URL"]??"");echo (($p["path"]??"")=="/' + DATABASE + '"&&($p["user"]??"")=="' + DBUSER + '"&&($p["host"]??"")=="127.0.0.1")?"isolated":"wrong";')
    require(identity == 'isolated', 'Clone environment does not address the dedicated lifecycle database')
    require(len(tables()) == 10, 'Reset requires the previously qualified final schema')
    observed = ['request', 'session', 'daily', 'journey_daily', 'health_daily', 'health_snapshot', 'benchmark_draft']
    for table in observed:
        require(sql('SELECT COUNT(*) FROM agentenblick_' + table) == '0', 'Reset refused: observation or contribution data remain in ' + table)
    status = json.loads(console('agentenblick:benchmark', 'status'))
    require(status.get('offline') is True and status.get('enabled') is False and status.get('pendingWithdrawal') is False, 'Reset requires offline, disabled participation without pending withdrawal')
    if OUT.exists():
        previous = json.loads(OUT.read_text())
        require(previous.get('passed') is True, 'Preserve and investigate the previous failed lifecycle before resetting')
        suffix = previous.get('zipSha256', hashlib.sha256(OUT.read_bytes()).hexdigest())[:12]
        archive = ROOT / ('.local/lifecycle-sw67-acceptance-' + suffix + '.json')
        if archive.exists():
            require(archive.read_bytes() == OUT.read_bytes(), 'Previous evidence archive would be overwritten')
        else:
            shutil.copy2(OUT, archive)
    console('plugin:uninstall', 'AithosAgentenblick')
    require(tables() == [], 'Reset uninstall did not remove the dedicated plugin tables')
    execute('python3', '-c', 'import pathlib,shutil;root=pathlib.Path("' + PLUGIN + '");[shutil.rmtree(p) if p.is_dir() and not p.is_symlink() else p.unlink() for p in root.iterdir()]')
    baseline()
    execute('chown', '-R', 'www-data:www-data', PLUGIN, user='root')
    execute('python3', '-c', 'import pathlib,shutil;p=pathlib.Path("' + TARGET + '/var/cache");shutil.rmtree(p);p.mkdir()')
    console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'true')
    console('system:config:set', '--json', 'AithosAgentenblick.config.heuristicsEnabled', 'false')
    console('plugin:refresh')
    console('plugin:install', '--activate', 'AithosAgentenblick')
    console('database:migrate', 'AithosAgentenblick', '--all')
    console('cache:clear')
    require(len(tables()) == 6 and snapshot() == {'requests': 0, 'sessions': 0, 'dailyRequests': 0, 'dailySessions': 0}, 'Reset baseline must have six empty analytics tables')
    state['lastBaselineResetAt'] = time.time()
    save(state)
    print(json.dumps({'phase': 'reset-baseline-complete', 'root': TARGET, 'tables': 6, 'observations': 0, 'apacheReloaded': False}))


def acceptance(archive_path=None):
    state = json.loads(STATE.read_text())
    require(state.get('vhostReady'), 'Expose the isolated vhost after coordinating Apache')
    result = {'startedAt': time.time(), 'shopware':'6.7.15.0', 'baselineRef':BASELINE,
              'databaseIsolated':True, 'vendorIndependentCopy':True, 'existingSecretsCopied':False,
              'offline':True, 'outboundEmailDisabled':True, 'checks':{}, 'passed':False}
    if archive_path:
        result['zipSha256'] = hashlib.sha256(archive_path.read_bytes()).hexdigest()
        result['zipFile'] = archive_path.name
    try:
        result['runtime'] = json.loads(execute('php','-r','require "vendor/autoload.php";echo json_encode(["php"=>PHP_VERSION,"shopware"=>Composer\\InstalledVersions::getPrettyVersion("shopware/core"),"sodium"=>extension_loaded("sodium"),"openssl"=>extension_loaded("openssl")]);'))
        require(result['runtime']['shopware'].lstrip('v') == '6.7.15.0', 'Actual copied Shopware version differs')
        require(sql("SELECT Super_priv FROM mysql.user WHERE User='" + DBUSER + "' AND Host='localhost'", False) == 'N', 'Temporary installation privilege was not revoked')
        result['temporaryInstallPrivilegeRevoked'] = True
        result['databaseVersion'] = sql('SELECT VERSION()')
        require(len(tables()) == 6, 'Baseline must have six tables')
        prior = snapshot()
        status, _ = http('GET','/',ua='GPTBot/1.0 LifecycleBaseline')
        require(status == 200,'Baseline storefront response failed')
        time.sleep(.5)
        before = snapshot()
        require(before['requests'] == prior['requests']+1 and before['dailyRequests'] == prior['dailyRequests']+1,'Baseline capture must increment exactly once')
        result['checks']['baselineCapture'] = before
        result['releaseArchiveSha256'] = final_source('0.1.1', archive_path, freeze=True)
        console('plugin:refresh')
        console('plugin:update','AithosAgentenblick')
        console('scheduled-task:register')
        console('cache:clear')
        require(len(tables()) == 10,'Health upgrade must create ten tables')
        require(snapshot() == before,'Upgrade altered observations')
        require(sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='"+DATABASE+"' AND TABLE_NAME='agentenblick_request' AND COLUMN_NAME IN ('pending_context','stage','failure_mask','heuristic_score','domain_id','discovery_resource','discovery_completed_at')") == '7','Upgrade columns missing')
        console('database:migrate','AithosAgentenblick','--all')
        require(snapshot() == before,'Repeated migrations changed observations')
        result['checks']['upgrade'] = {'from':'0.1.0','toFixtureVersion':'0.1.1','tablesBefore':6,'tablesAfter':10,'observationsPreserved':True,'migrationsIdempotent':True}
        result['checks']['selftest'] = json.loads(console('agentenblick:selftest'))
        status,body=http('POST','/api/oauth/token',{'grant_type':'password','client_id':'administration','scopes':'write','username':state['adminUsername'],'password':state['adminPassword']})
        require(status==200,'Isolated admin authentication failed')
        token=json.loads(body)['access_token']
        for endpoint in ['summary?days=7','agents?days=7','export?days=7','journey/summary?days=7','journey/sessions?days=7','heuristics/settings','benchmark/status','health?days=7']:
            path='/api/_action/agentenblick/'+endpoint
            anonymous,_=http('GET',path)
            admin,payload=http('GET',path,token=token)
            require(anonymous==401 and admin==200,'Admin API failed: '+endpoint.split('?')[0])
            result['checks']['api_'+endpoint.split('?')[0]]={'anonymous':anonymous,'admin':admin}
            if endpoint.startswith('health'):
                health=json.loads(payload);require(health['offline'] and all(not d['eligible'] for d in health['domains']),'Local Health must stay ineligible/offline')
        console('plugin:deactivate','AithosAgentenblick')
        frozen=snapshot()
        status,_=http('GET','/',ua='GPTBot/1.0 LifecycleDisabled')
        time.sleep(.5)
        require(status==200 and snapshot()==frozen,'Inactive plugin changed response or captured traffic')
        console('plugin:activate','AithosAgentenblick')
        status,_=http('GET','/',ua='GPTBot/1.0 LifecycleReactivated')
        time.sleep(.5)
        after=snapshot()
        require(status==200 and after['requests']==frozen['requests']+1 and after['dailyRequests']==frozen['dailyRequests']+1,'Reactivated plugin did not capture once')
        result['checks']['deactivateReactivate']={'storefront':200,'noCaptureWhenInactive':True,'captureResumedExactlyOnce':True}
        for suffix in ['/var/log/agentenblick/errors.json','/var/agentenblick/errors.json']:
            write(TARGET+suffix,'{"LifecycleFixture":{"count":1,"last_logged":0}}',0o600)
        write(TARGET+'/var/log/agentenblick/unrelated.log','must remain\n',0o600)
        execute('chown','-R','www-data:www-data',TARGET+'/var/log/agentenblick',TARGET+'/var/agentenblick',user='root')
        console('plugin:uninstall','--keep-user-data','AithosAgentenblick')
        require(len(tables())==10 and snapshot()==after,'Keep-data uninstall changed observations')
        for suffix in ['/var/log/agentenblick/errors.json','/var/agentenblick/errors.json']:execute('test','-f',TARGET+suffix)
        console('plugin:install','--activate','AithosAgentenblick')
        require(snapshot()==after,'Reinstall after keep-data changed observations')
        result['checks']['keepDataUninstallReinstall']={'tablesPreserved':10,'observationsPreserved':True,'failureCountersPreserved':True}
        console('plugin:uninstall','AithosAgentenblick')
        require(tables()==[],'Delete-data uninstall retained plugin tables')
        require(sql("SELECT COUNT(*) FROM system_config WHERE configuration_key LIKE 'AithosAgentenblick.%'")=='0','Delete-data uninstall retained config')
        for suffix in ['/var/log/agentenblick/errors.json','/var/agentenblick/errors.json']:execute('test','!','-e',TARGET+suffix)
        execute('test','-f',TARGET+'/var/log/agentenblick/unrelated.log')
        result['checks']['deleteDataUninstall']={'analyticsTablesRemaining':0,'pluginSettingsRemaining':0,'ownedFailureFilesRemaining':0,'unrelatedLogPreserved':True}
        require(final_source('0.1.0',archive_path) == result['releaseArchiveSha256'], 'Final reinstall must use the exact frozen release bytes')
        console('plugin:refresh')
        console('plugin:install','--activate','AithosAgentenblick')
        console('system:config:set','--json','AithosAgentenblick.config.offline','true')
        console('system:config:set','--json','AithosAgentenblick.config.heuristicsEnabled','false')
        console('scheduled-task:register')
        console('assets:install')
        console('cache:clear')
        require(len(tables())==10 and snapshot()=={'requests':0,'sessions':0,'dailyRequests':0,'dailySessions':0},'Final fresh reinstall retained observations or missed tables')
        require(sql("SELECT version FROM plugin WHERE name='AithosAgentenblick'")=='0.1.0','Fixture version leaked to final installation')
        result['checks']['finalReinstall']={'version':'0.1.0','tables':10,'observations':0,'offline':True,'heuristicsEnabled':False,'source':'zip' if archive_path else 'working-tree'}
        if archive_path:
            result['checks']['installedZipBytes'] = verify_installed_zip(archive_path)
        result['passed']=True
    except Exception as error:
        result['failure']=str(error) if isinstance(error,AssertionError) else type(error).__name__
        raise
    finally:
        result['finishedAt']=time.time();result['hostFreeBytes']=shutil.disk_usage('/').free
        OUT.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps({'passed':result['passed'],'evidence':str(OUT),'checks':list(result['checks'])}))


if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('phase',choices=['bootstrap','initialize','expose','reset-baseline','run'])
    parser.add_argument('--zip',type=Path)
    args=parser.parse_args()
    if args.phase=='bootstrap':bootstrap()
    elif args.phase=='initialize':initialize()
    elif args.phase=='expose':expose()
    elif args.phase=='reset-baseline':reset_baseline()
    else:acceptance(args.zip)
