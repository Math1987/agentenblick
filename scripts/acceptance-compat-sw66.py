#!/usr/bin/env python3
"""Exercise the plugin lifecycle against the separate disposable 6.6 database."""
import importlib.util
import json
import argparse
import re
import hashlib
import shutil
import zipfile
from pathlib import Path
import time
import urllib.error
import urllib.request

spec = importlib.util.spec_from_file_location('compat_setup', Path(__file__).with_name('setup-compat-bench.py'))
compat = importlib.util.module_from_spec(spec)
spec.loader.exec_module(compat)
ROOT = compat.ROOT
OUT = ROOT / '.local/compat-sw66-acceptance.json'
DESTINATION = compat.TARGET + '/custom/plugins/AithosAgentenblick'
RELEASE = None
ZIP = None
EXPECTED_TABLES = 10


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def sql(statement):
    return compat.sql('USE agentenblick_sw66; ' + statement)


def http(method, path, body=None, token=None, ua='AgentenblickCompatibility/1.0'):
    headers = {'Host': 'agentenblick-sw66.localhost:18080', 'User-Agent': ua}
    data = json.dumps(body).encode() if body is not None else None
    if data is not None:
        headers['Content-Type'] = 'application/json'
    if token:
        headers['Authorization'] = 'Bearer ' + token
    request = urllib.request.Request('http://127.0.0.1:18080'+path, method=method, data=data, headers=headers)
    try:
        response = urllib.request.build_opener(urllib.request.ProxyHandler({})).open(request, timeout=30)
    except urllib.error.HTTPError as response:
        return response.code, response.read()
    with response:
        return response.status, response.read()


def snapshot():
    return json.loads(sql("SELECT JSON_OBJECT('requests',(SELECT COUNT(*) FROM agentenblick_request),'sessions',(SELECT COUNT(*) FROM agentenblick_session),'dailyRequests',(SELECT COALESCE(SUM(requests),0) FROM agentenblick_daily),'dailySessions',(SELECT COALESCE(SUM(sessions),0) FROM agentenblick_daily))"))


def tables():
    return sql("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='agentenblick_sw66' AND TABLE_NAME LIKE 'agentenblick\\_%' ORDER BY TABLE_NAME").splitlines()


def clear_cache():
    compat.execute('python3', '-c', 'import pathlib,shutil;p=pathlib.Path("'+compat.TARGET+'/var/cache");shutil.rmtree(p,ignore_errors=True);p.mkdir()')


def git_release(ref, version=None):
    archive = ROOT / '.local/compat-sw66-release.tar'
    compat.run('git', '-C', str(ROOT), 'archive', '--format=tar', '--output='+str(archive), ref, 'src', 'composer.json')
    compat.run('docker', 'cp', str(archive), compat.CONTAINER+':/tmp/agentenblick-sw66-release.tar')
    compat.execute('python3', '-c', 'import shutil;shutil.rmtree("'+DESTINATION+'/src",ignore_errors=True)')
    compat.execute('tar', '-xf', '/tmp/agentenblick-sw66-release.tar', '-C', DESTINATION)
    archive.unlink()
    compat.execute('rm', '/tmp/agentenblick-sw66-release.tar', user='root')
    if version is not None:
        compat.execute('python3', '-c', 'import json,pathlib,sys;p=pathlib.Path(sys.argv[1]);d=json.loads(p.read_text());d["version"]=sys.argv[2];p.write_text(json.dumps(d,indent=2))', DESTINATION+'/composer.json', version)
    clear_cache()


def release(version=None):
    if ZIP is None:
        git_release(RELEASE, version)
        return
    remote = '/tmp/agentenblick-sw66-candidate.zip'
    compat.run('docker', 'cp', str(ZIP), compat.CONTAINER+':'+remote)
    code = '''import pathlib,shutil,zipfile,sys,json
destination=pathlib.Path(sys.argv[1]);archive=pathlib.Path(sys.argv[2])
shutil.rmtree(destination);destination.mkdir()
with zipfile.ZipFile(archive) as z:
 for info in z.infolist():
  relative=pathlib.PurePosixPath(info.filename).relative_to('AithosAgentenblick')
  if not relative.parts:continue
  target=destination.joinpath(*relative.parts)
  if info.is_dir():target.mkdir(parents=True,exist_ok=True)
  else:
   target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(z.read(info))
if sys.argv[3]:
 p=destination/'composer.json';manifest=json.loads(p.read_text());manifest['version']=sys.argv[3];p.write_text(json.dumps(manifest,indent=2))
'''
    compat.execute('python3', '-c', code, DESTINATION, remote, version or '')
    compat.execute('rm', '-f', remote, user='root')
    clear_cache()


def reset_baseline():
    require(compat.TARGET == '/tmp/agentenblick-sw66' and compat.DATABASE == 'agentenblick_sw66', 'Unexpected isolated target')
    require(snapshot() == {'requests':0,'sessions':0,'dailyRequests':0,'dailySessions':0}, 'Reset requires an empty disposable compatibility installation')
    compat.console('plugin:uninstall', 'AithosAgentenblick')
    require(tables() == [], 'Reset uninstall did not remove the current schema')
    git_release('4786b97')
    compat.console('plugin:refresh')
    compat.console('plugin:install', '--activate', 'AithosAgentenblick')
    compat.console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'true')
    compat.console('system:config:set', '--json', 'AithosAgentenblick.config.heuristicsEnabled', 'false')
    compat.console('scheduled-task:register')
    compat.console('cache:clear')


def health_fixture():
    channel, domain = sql("SELECT LOWER(HEX(sales_channel_id)),LOWER(HEX(id)) FROM sales_channel_domain ORDER BY id LIMIT 1").split('\t')
    sql("INSERT INTO agentenblick_health_snapshot VALUES (UNHEX('"+domain+"'),'"+channel+"','{\"fixture\":true}','{}',UTC_TIMESTAMP(3)); INSERT INTO agentenblick_health_daily (day,domain_id,sales_channel_id,agent,evidence,route,status,code,reason_code,requests) VALUES (UTC_DATE(),UNHEX('"+domain+"'),'"+channel+"','GPTBot','declared','frontend.navigation.page',403,'allowed_but_blocked','robots_allow',7)")
    return sql("SELECT CONCAT(snapshot_json,':',policy_json,':',(SELECT SUM(requests) FROM agentenblick_health_daily)) FROM agentenblick_health_snapshot")


def failure_files(seed=False):
    code = '''import pathlib,sys,json
paths=[pathlib.Path(sys.argv[1]+p) for p in ('/var/log/agentenblick/errors.json','/var/agentenblick/errors.json')]
if sys.argv[2]=='seed':
 for p in paths:p.parent.mkdir(parents=True,exist_ok=True);p.write_text('{"database":7}')
print(json.dumps([p.exists() for p in paths]))
'''
    return json.loads(compat.execute('python3', '-c', code, compat.TARGET, 'seed' if seed else 'read'))


def installed_checksums():
    code = '''$root=$argv[1];$manifest=json_decode(file_get_contents($root.'/checksum.json'),true,512,JSON_THROW_ON_ERROR);$count=0;foreach($manifest['hashes'] as $path=>$expected){if(!hash_equals($expected,hash_file($manifest['algorithm'],$root.'/'.$path))){throw new RuntimeException('Installed release checksum mismatch');}++$count;}echo json_encode(['algorithm'=>$manifest['algorithm'],'filesVerified'=>$count,'allMatched'=>true]);'''
    return json.loads(compat.execute('php', '-r', code, DESTINATION))


def main(reset=False):
    state = json.loads(compat.STATE.read_text())
    require(state.get('pluginInstalled') and state.get('database') == 'agentenblick_sw66', 'Provision the isolated compatibility bench first')
    require(shutil.disk_usage('/').free >= compat.LIMIT, 'System disk below 3 GiB')
    result = {'startedAt': time.time(), 'shopware': '6.6.10.29', 'sourceRelease': RELEASE,
              'baselineRef': state['baselineRef'], 'databaseIsolated': True, 'offline': True,
              'outboundEmailDisabled': True, 'checks': {}, 'passed': False}
    if ZIP:
        result['artifact'] = {'filename':ZIP.name,'sha256':hashlib.sha256(ZIP.read_bytes()).hexdigest(),'finalInstallUnmodified':True}
    try:
        if reset:
            reset_baseline()
        result['runtime'] = json.loads(compat.execute('php', '-r', 'echo json_encode(["php"=>PHP_VERSION,"sodium"=>extension_loaded("sodium"),"openssl"=>extension_loaded("openssl")]);'))
        result['databaseVersion'] = sql('SELECT VERSION()')
        initial_tables = tables()
        require(len(initial_tables) == 6, 'The baseline did not start at the intended six-table schema')
        prior = snapshot()
        status, _ = http('GET', '/', ua='GPTBot/1.0 CompatibilityBaseline')
        require(status == 200, 'Baseline storefront request failed')
        time.sleep(.5)
        before = snapshot()
        require(before['requests'] == prior['requests'] + 1 and before['dailyRequests'] == prior['dailyRequests'] + 1, 'Baseline request was not captured exactly once')
        result['checks']['baselineCapture'] = before
        release('0.1.1')  # fixture-only version bump exercises the real update command
        compat.console('plugin:refresh')
        compat.console('plugin:update', 'AithosAgentenblick')
        compat.console('scheduled-task:register')
        compat.console('cache:clear')
        updated_tables = tables()
        require(len(updated_tables) == EXPECTED_TABLES, 'The current migrations did not create the expected table count')
        require(snapshot() == before, 'Upgrade changed prior observations')
        after_schema = sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='agentenblick_sw66' AND TABLE_NAME='agentenblick_request' AND COLUMN_NAME IN ('pending_context','stage','failure_mask','heuristic_score')")
        require(after_schema == '4', 'One of the actual migration columns is absent')
        compat.console('database:migrate', 'AithosAgentenblick', '--all')
        require(snapshot() == before, 'Repeated migrations changed observations')
        result['checks']['upgrade'] = {'from': '0.1.0', 'toFixtureVersion': '0.1.1', 'versionOnlyOverrideForUpdate':True,'tablesBefore': 6, 'tablesAfter': EXPECTED_TABLES, 'observationsPreserved': True, 'migrationsIdempotent': True}
        selftest = json.loads(compat.console('agentenblick:selftest'))
        result['checks']['selftest'] = selftest
        token_status, token_body = http('POST', '/api/oauth/token', {'grant_type':'password','client_id':'administration','scopes':'write','username':state['adminUsername'],'password':state['adminPassword']})
        require(token_status == 200, 'Isolated admin authentication failed')
        token = json.loads(token_body)['access_token']
        endpoints = ['summary?days=7', 'agents?days=7', 'export?days=7', 'journey/summary?days=7', 'journey/sessions?days=7', 'heuristics/settings', 'benchmark/status']
        if EXPECTED_TABLES >= 10:
            endpoints.append('health')
        for suffix in endpoints:
            endpoint = '/api/_action/agentenblick/' + suffix
            anonymous, _ = http('GET', endpoint)
            authenticated, _ = http('GET', endpoint, token=token)
            require(anonymous == 401 and authenticated == 200, 'Admin endpoint compatibility failed: ' + suffix.split('?')[0])
            result['checks']['api_'+suffix.split('?')[0]] = {'anonymous':anonymous,'admin':authenticated}
        compat.console('plugin:deactivate', 'AithosAgentenblick')
        before_disabled = snapshot()
        disabled_status, _ = http('GET', '/', ua='GPTBot/1.0 CompatibilityDisabled')
        time.sleep(.4)
        require(disabled_status == 200 and snapshot() == before_disabled, 'Deactivated plugin still captured traffic or changed the response')
        compat.console('plugin:activate', 'AithosAgentenblick')
        require(snapshot() == before_disabled, 'Reactivation changed historical data')
        result['checks']['deactivateReactivate'] = {'storefront':disabled_status,'noCaptureWhenInactive':True,'observationsPreserved':True}
        health_before = health_fixture() if EXPECTED_TABLES >= 10 else None
        require(failure_files(seed=True) == [True,True], 'Failure counter fixtures were not created')
        compat.console('plugin:uninstall', '--keep-user-data', 'AithosAgentenblick')
        require(tables() == updated_tables and snapshot() == before_disabled, 'Keep-data uninstall lost observations or schema')
        require(failure_files() == [True,True], 'Keep-data uninstall deleted local failure counters')
        compat.console('plugin:install', '--activate', 'AithosAgentenblick')
        require(snapshot() == before_disabled, 'Reinstall after keep-data changed observations')
        if health_before is not None:
            require(sql("SELECT CONCAT(snapshot_json,':',policy_json,':',(SELECT SUM(requests) FROM agentenblick_health_daily)) FROM agentenblick_health_snapshot") == health_before, 'Keep-data reinstall lost Health data')
        result['checks']['keepDataUninstallReinstall'] = {'tablesPreserved':EXPECTED_TABLES,'observationsPreserved':True,'healthDataPreserved':health_before is not None,'failureFilesPreserved':True}
        compat.console('plugin:uninstall', 'AithosAgentenblick')
        require(tables() == [], 'Destructive uninstall retained analytics tables')
        require(sql("SELECT COUNT(*) FROM system_config WHERE configuration_key LIKE 'AithosAgentenblick.%'") == '0', 'Destructive uninstall retained plugin state')
        require(failure_files() == [False,False], 'Destructive uninstall retained local failure counters')
        result['checks']['deleteDataUninstall'] = {'analyticsTablesRemaining':0,'pluginSettingsRemaining':0,'localFailureFilesRemaining':0}
        release(None if ZIP else '0.1.0')
        compat.console('plugin:refresh')
        compat.console('plugin:install', '--activate', 'AithosAgentenblick')
        compat.console('system:config:set', '--json', 'AithosAgentenblick.config.offline', 'true')
        compat.console('system:config:set', '--json', 'AithosAgentenblick.config.heuristicsEnabled', 'false')
        compat.console('scheduled-task:register')
        compat.console('assets:install')
        compat.console('cache:clear')
        require(len(tables()) == EXPECTED_TABLES, 'Final clean reinstall failed')
        require(snapshot() == {'requests':0,'sessions':0,'dailyRequests':0,'dailySessions':0}, 'Fresh reinstall unexpectedly retained observations')
        final_selftest = json.loads(compat.console('agentenblick:selftest'))
        require(final_selftest['ok'] and final_selftest['offline'], 'Final clean installation selftest failed')
        if EXPECTED_TABLES >= 10:
            require(json.loads(compat.console('agentenblick:health'))['offline'], 'Final Health local CLI failed')
            require(sql('SELECT (SELECT COUNT(*) FROM agentenblick_health_snapshot)+(SELECT COUNT(*) FROM agentenblick_health_daily)') == '0', 'Final clean reinstall retained Health fixtures')
        result['checks']['finalReinstall'] = {'version':'0.1.0','tables':EXPECTED_TABLES,'offline':True,'heuristicsEnabled':False,'observations':0,'healthRows':0,'selftestOk':True,'apacheReload':False}
        if ZIP:
            result['checks']['installedChecksums'] = installed_checksums()
        result['passed'] = True
    except Exception as error:
        result['failure'] = str(error) if isinstance(error, AssertionError) else type(error).__name__
        raise
    finally:
        result['finishedAt'] = time.time()
        OUT.write_text(json.dumps(result,indent=2)+'\n')
    return result


def final_health(ref):
    """Sync a reviewed immutable release and leave the isolated UI ready."""
    global RELEASE
    require(re.fullmatch(r'[a-f0-9]{7,40}', ref) is not None, 'Use an immutable reviewed Git commit')
    RELEASE = ref
    release('0.1.0')
    compat.console('plugin:refresh')
    compat.console('database:migrate', 'AithosAgentenblick', '--all')
    compat.console('scheduled-task:register')
    compat.console('assets:install')
    compat.console('cache:clear')
    selftest = json.loads(compat.console('agentenblick:selftest'))
    health = json.loads(compat.console('agentenblick:health'))
    require(selftest['ok'] and selftest['offline'] and health['offline'], 'Final offline self-test failed')
    require(len(tables()) == 10, 'Final Health migrations did not create ten tables')
    state = json.loads(compat.STATE.read_text())
    status, body = http('POST', '/api/oauth/token', {'grant_type':'password','client_id':'administration','scopes':'write','username':state['adminUsername'],'password':state['adminPassword']})
    require(status == 200, 'Final admin authentication failed')
    token = json.loads(body)['access_token']
    anonymous, _ = http('GET', '/api/_action/agentenblick/health')
    authenticated, body = http('GET', '/api/_action/agentenblick/health', token=token)
    require(anonymous == 401 and authenticated == 200 and json.loads(body)['offline'], 'Final Health ACL or offline state failed')
    domain = next(d for d in health['domains'] if d['url'].startswith('http://'))
    probe, _ = http('POST', '/api/_action/agentenblick/health/probe', {'domainId':domain['id'],'autoTest':False}, token=token)
    require(probe == 404, 'A local domain unexpectedly became probe eligible')
    result = {'finishedAt':time.time(),'sourceRelease':ref,'shopware':'6.6.10.29','php':selftest['php'],'tables':10,'selftestOk':True,'cliHealthLocal':True,'offline':True,'anonymousHealth':anonymous,'adminHealth':authenticated,'localDomainProbeRejected':probe,'assetsInstalled':True,'apacheReload':False,'uiVisuallyVerified':False}
    (ROOT/'handoffs/evidence/lot-6-health-sw66.json').write_text(json.dumps(result,indent=2)+'\n')
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--final-health', help='Immutable reviewed Git commit to sync after the lifecycle test')
    parser.add_argument('--release', help='Immutable reviewed source commit for lifecycle evidence')
    parser.add_argument('--zip', type=Path, help='Final release ZIP to install instead of a Git archive')
    parser.add_argument('--sha256', help='Required exact checksum when using --zip')
    parser.add_argument('--expected-tables', type=int, default=10, choices=[8,10])
    parser.add_argument('--reset-baseline', action='store_true', help='Uninstall the empty 6.6 fixture and reinstall the six-table baseline')
    args = parser.parse_args()
    RELEASE = args.release
    EXPECTED_TABLES = args.expected_tables
    require(args.final_health or (RELEASE is not None and re.fullmatch(r'[a-f0-9]{7,40}', RELEASE)), 'Pass --release with the reviewed immutable source commit')
    if args.zip:
        ZIP = args.zip.resolve()
        require(args.sha256 and hashlib.sha256(ZIP.read_bytes()).hexdigest() == args.sha256, 'Candidate ZIP checksum differs')
        with zipfile.ZipFile(ZIP) as archive:
            for entry in archive.infolist():
                path = Path(entry.filename)
                require(not path.is_absolute() and '..' not in path.parts and path.parts[0] == 'AithosAgentenblick', 'Unsafe ZIP path')
                require((entry.external_attr >> 16) & 0o170000 != 0o120000, 'Symlinks are forbidden in the release ZIP')
    try:
        result = final_health(args.final_health) if args.final_health else main(args.reset_baseline)
        print(json.dumps(result if args.final_health else {'passed':result['passed'],'evidence':str(OUT),'checks':list(result['checks'])}))
    except Exception:
        print(json.dumps({'passed':False,'evidence':str(OUT)}))
        raise
