#!/usr/bin/env python3
"""Real HTTP Health acceptance on the disposable APP_ENV=test bench only.

The exact fictional HTTPS origin is mapped to real loopback HTTP by a temporary
test-only client. PublicNetworkClient stays unchanged; DNS/TLS to a public shop
are not claimed. Own channel/domain rows isolate all observations and cleanup.
"""
import argparse
import importlib.util
import json
from pathlib import Path
import re
import time
import urllib.error
import urllib.request
import uuid
from acceptance import CONTAINER, PLUGIN, ROOT, console, execute, sql, run

HOST = 'agentenblick-health-fixture.example'
ORIGIN = 'https://' + HOST
STATE = ROOT / '.local/health-http-state.json'
OUT = ROOT / '.local/health-http-acceptance.json'
KEY = 'AithosAgentenblick.config.offline'


def literal(value):
    return "CONVERT(X'"+str(value).encode().hex()+"' USING utf8mb4) COLLATE utf8mb4_unicode_ci"


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def save(state):
    STATE.parent.mkdir(exist_ok=True)
    STATE.write_text(json.dumps(state,indent=2)+'\n')
    STATE.chmod(0o600)


def write_remote(path, body):
    code='from pathlib import Path;import sys;p=Path(sys.argv[1]);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(sys.stdin.read())'
    subprocess_input('docker','exec','-i','-u','root',CONTAINER,'python3','-c',code,path,input=body)


def subprocess_input(*args,input):
    import subprocess
    result=subprocess.run(args,input=input,text=True,capture_output=True)
    if result.returncode:
        raise RuntimeError('Fixture command failed: '+result.stderr[-1000:])
    return result.stdout


def http(path, token=None, body=None, fixture=False, context=None, capability=None):
    headers={'Content-Type':'application/json','Accept':'application/json'}
    if token:
        headers['Authorization']='Bearer '+token
    if fixture:
        headers['Host']=HOST
        headers['User-Agent']='GPTBot/1.0 HealthHTTPAcceptance'
        if context:
            headers['sw-context-token']=context
        if capability:
            headers['X-Agentenblick-Health-Probe']=capability
    req=urllib.request.Request('http://127.0.0.1:18080'+path,data=json.dumps(body).encode() if body is not None else None,headers=headers)
    try:
        response=urllib.request.build_opener(urllib.request.ProxyHandler({})).open(req,timeout=35)
    except urllib.error.HTTPError as response:
        return response.code,dict(response.headers),response.read()
    with response:
        return response.status,dict(response.headers),response.read()


def counts(channel):
    return json.loads(sql("SELECT JSON_OBJECT('requests',(SELECT COUNT(*) FROM agentenblick_request WHERE sales_channel_id='"+channel+"'),'sessions',(SELECT COUNT(*) FROM agentenblick_session WHERE sales_channel_id='"+channel+"'),'dailyRequests',(SELECT COALESCE(SUM(requests),0) FROM agentenblick_daily WHERE sales_channel_id='"+channel+"'))"))


def clone_entity(table, old_id, new_id, overrides):
    columns=sql("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"+table+"' AND GENERATION_EXPRESSION='' ORDER BY ORDINAL_POSITION").splitlines()
    values=[]
    for col in columns:
        values.append('UNHEX(\''+new_id+'\')' if col=='id' else overrides.get(col,'`'+col+'`'))
    sql('INSERT INTO `'+table+'` ('+','.join('`'+c+'`' for c in columns)+') SELECT '+','.join(values)+" FROM `"+table+"` WHERE id=UNHEX('"+old_id+"')")


def setup():
    require(not STATE.exists(),'An existing Health fixture must be cleaned up first')
    require(CONTAINER=='agentenblick-shop' and re.search(r'Environment\s+test\b',console('about')),'Only the disposable test bench is allowed')
    marker=uuid.uuid4().hex[:12]
    state={'marker':marker,'channel':uuid.uuid4().hex,'domain':uuid.uuid4().hex,'log':'/tmp/agentenblick-health-'+marker+'.jsonl',
           'configBackup':'agentenblick_health_accept_config_'+marker,'proxyBackup':'agentenblick_health_accept_proxy_'+marker,
           'servicesBackup':'/tmp/agentenblick-health-services-'+marker+'.php','baselineDailyRequests':int(sql('SELECT COALESCE(SUM(requests),0) FROM agentenblick_daily'))}
    save(state)
    sql('CREATE TABLE '+state['configBackup']+' LIKE system_config')
    state['configSaved']=True;save(state)
    sql('INSERT INTO '+state['configBackup']+' SELECT * FROM system_config WHERE configuration_key='+literal(KEY))
    sql('CREATE TABLE '+state['proxyBackup']+' LIKE agentenblick_state')
    state['proxySaved']=True;save(state)
    sql('INSERT INTO '+state['proxyBackup']+" SELECT * FROM agentenblick_state WHERE name LIKE 'proxy_%'")
    state['proxyBackupReady']=True;save(state)
    base=sql("SELECT LOWER(HEX(d.id)),LOWER(HEX(d.sales_channel_id)) FROM sales_channel_domain d JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND d.url='http://127.0.0.1:18080' LIMIT 1").split('\t')
    require(len(base)==2,'The isolated source channel is missing')
    clone_entity('sales_channel',base[1],state['channel'],{'name':literal('Agentenblick Health HTTP Fixture'),'access_key':literal(uuid.uuid4().hex),'active':'1'})
    state['channelCreated']=True;save(state)
    clone_entity('sales_channel_domain',base[0],state['domain'],{'sales_channel_id':"UNHEX('"+state['channel']+"')",'url':literal(ORIGIN)})
    state['domainCreated']=True;save(state)
    execute('cp','/var/www/html/config/services_test.php',state['servicesBackup'],user='root')
    state['servicesSaved']=True;save(state)
    run('docker','cp',str(ROOT/'scripts/health-http-fixture.php'),CONTAINER+':'+PLUGIN+'/scripts/health-http-fixture.php')
    write_remote('/tmp/agentenblick-health-fixture.json',json.dumps({'host':HOST,'channel':state['channel'],'domain':state['domain'],'log':state['log']}))
    config='''<?php
declare(strict_types=1);
use Symfony\\Component\\DependencyInjection\\ContainerBuilder;
use Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\ContainerConfigurator;
use function Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\service;
require_once %s;
$base = require %s;
return static function(ContainerConfigurator $config, ContainerBuilder $container) use ($base): void {
    $base($config, $container);
    $services = $config->services();
    $services->set(Agentenblick\\Acceptance\\HealthHttpFixtureSubscriber::class)->file(%s)->tag('kernel.event_subscriber');
    $services->set('agentenblick.acceptance.health_http_client', Symfony\\Contracts\\HttpClient\\HttpClientInterface::class)->file(%s)->factory([Agentenblick\\Acceptance\\HealthHttpFixture::class, 'client']);
    $services->set(Aithos\\Agentenblick\\Health\\ProbeTransport::class)->args([service(Aithos\\Agentenblick\\Health\\ProbeTokens::class), service(Shopware\\Core\\System\\SystemConfig\\SystemConfigService::class), service('agentenblick.acceptance.health_http_client')]);
};
'''%(repr(PLUGIN+'/scripts/health-http-fixture.php'),repr(state['servicesBackup']),repr(PLUGIN+'/scripts/health-http-fixture.php'),repr(PLUGIN+'/scripts/health-http-fixture.php'))
    write_remote('/var/www/html/config/services_test.php',config)
    state['servicesMutated']=True;save(state)
    hosts_code='from pathlib import Path;import sys;p=Path("/etc/hosts");p.write_text(p.read_text()+"\\n93.184.216.34 "+sys.argv[1]+" # agentenblick-health-"+sys.argv[2]+"\\n")'
    execute('python3','-c',hosts_code,HOST,marker,user='root')
    state['hostsAdded']=True;save(state)
    write_remote('/etc/apache2/sites-available/agentenblick-health-fixture.conf','''<VirtualHost *:80>
ServerName agentenblick-health-fixture.example
DocumentRoot /var/www/html/public
SetEnv HTTPS on
<Directory /var/www/html/public>
Options -Indexes
AllowOverride All
Require all granted
CGIPassAuth On
</Directory>
<FilesMatch \\.php$>
SetHandler "proxy:unix:/var/run/php/php8.2-fpm.sock|fcgi://localhost"
</FilesMatch>
ErrorLog /tmp/agentenblick-health-fixture-error.log
CustomLog /dev/null combined
</VirtualHost>
''')
    execute('a2ensite','agentenblick-health-fixture.conf',user='root')
    state['vhostAdded']=True;save(state)
    execute('apache2ctl','configtest',user='root')
    execute('apache2ctl','graceful',user='root')
    console('system:config:set','--json',KEY,'false')
    state['configMutated']=True;save(state)
    console('cache:clear')
    return state


def cleanup():
    if not STATE.exists():
        return {'alreadyClean':True}
    state=json.loads(STATE.read_text())
    if state.get('servicesSaved'):
        execute('cp',state['servicesBackup'],'/var/www/html/config/services_test.php',user='root')
    if state.get('vhostAdded'):
        execute('a2dissite','agentenblick-health-fixture.conf',user='root')
        execute('rm','/etc/apache2/sites-available/agentenblick-health-fixture.conf',user='root')
    if state.get('hostsAdded'):
        code='from pathlib import Path;import sys;p=Path("/etc/hosts");p.write_text("\\n".join(x for x in p.read_text().splitlines() if "# agentenblick-health-"+sys.argv[1] not in x)+"\\n")'
        execute('python3','-c',code,state['marker'],user='root')
    if state.get('configSaved'):
        if state.get('configMutated'):
            sql('START TRANSACTION; DELETE FROM system_config WHERE configuration_key='+literal(KEY)+'; INSERT INTO system_config SELECT * FROM '+state['configBackup']+'; COMMIT')
        sql('DROP TABLE '+state['configBackup'])
    if state.get('proxySaved'):
        if state.get('proxyBackupReady'):
            sql("START TRANSACTION; DELETE FROM agentenblick_state WHERE name LIKE 'proxy_%'; INSERT INTO agentenblick_state SELECT * FROM "+state['proxyBackup']+'; COMMIT')
        sql('DROP TABLE '+state['proxyBackup'])
    if state.get('channelCreated'):
        for table in ['request','session','daily','journey_daily','health_daily','health_snapshot']:
            sql("DELETE FROM agentenblick_"+table+" WHERE sales_channel_id='"+state['channel']+"'")
        sql("DELETE FROM sales_channel_domain WHERE sales_channel_id=UNHEX('"+state['channel']+"'); DELETE FROM sales_channel WHERE id=UNHEX('"+state['channel']+"')")
    console('cache:clear')
    execute('apache2ctl','configtest',user='root')
    execute('apache2ctl','graceful',user='root')
    for path in [state['servicesBackup'],state['log'],'/tmp/agentenblick-health-fixture.json','/tmp/agentenblick-health-fixture-error.log']:
        execute('rm','-f',path,user='root')
    restored=int(sql('SELECT COALESCE(SUM(requests),0) FROM agentenblick_daily'))==state['baselineDailyRequests']
    require(restored,'Non-fixture request totals changed during the exclusive window')
    STATE.unlink()
    result={'ownedChannelAndDomainRemoved':True,'ownObservationsRemoved':True,'configurationRestored':True,'hostsAndVhostRemoved':True,'existingRequestTotalsUnchanged':True}
    if OUT.exists():
        evidence=json.loads(OUT.read_text());evidence['cleanup']=result;OUT.write_text(json.dumps(evidence,indent=2)+'\n')
    return result


def main(keep):
    evidence={'startedAt':time.time(),'passed':False,'transportProof':'Real loopback HTTP via a test-only exact-origin mapper; unchanged PublicNetworkClient with public fixture DNS/IP. Public Internet DNS/TLS are not validated.','cleanup':{}}
    state=None
    try:
        state=setup()
        code,_,body=http('/api/oauth/token',body={'grant_type':'password','client_id':'administration','scopes':'write','username':'admin','password':'shopware'})
        require(code==200,'Admin authentication failed')
        token=json.loads(body)['access_token']
        require(counts(state['channel'])['requests']==0,'Fixture channel should be empty')
        status,_,body=http('/api/_action/agentenblick/health/probe',token,{'domainId':state['domain'],'autoTest':True})
        require(status==200 and json.loads(body).get('completed') is True,'Explicit Health HTTP probe failed')
        calls=[json.loads(line) for line in execute('cat',state['log']).splitlines()]
        require(len(calls)==11,'The probe did not perform exactly 11 real HTTP requests')
        require(counts(state['channel'])=={'requests':0,'sessions':0,'dailyRequests':0},'Internal probes contaminated analytics')
        status,_,body=http('/api/_action/agentenblick/health?domainId='+state['domain'],token)
        require(status==200,'Saved Health API failed')
        health=json.loads(body)
        resources={row['key']:row for row in health['snapshot']['resources']}
        evidence['probeObserved']={'httpCalls':calls,'resources':[{k:row.get(k) for k in ['key','statusCode','state','reasonCode','probeMethod']} for row in resources.values()]}
        # Shopware's genuine CORS preflight returns before the fixture controller.
        # An OPTIONS 200 cannot validate MCP; an observed GET 500 is tested below.
        require(resources['ucp']['statusCode']==500 and resources['mcp']['statusCode']==200,'Protocol/preflight HTTP statuses differ')
        require(resources['mcp']['protocolNotValidated'] is True,'OPTIONS incorrectly claimed protocol validation')
        require([r['statusCode'] for r in health['snapshot']['autoTests']]==[403,429,200],'Auto-test server-UA results mismatch')
        evidence['probe']={'httpRequests':11,'resources':[{k:row[k] for k in ['key','statusCode','state','probeMethod']} for row in resources.values()],'autoTestStatuses':[403,429,200],'analyticsRequests':0,'analyticsSessions':0,'mcpProtocolValidated':False}
        context='health-private-context-'+uuid.uuid4().hex
        private_query='health-private-query-'+uuid.uuid4().hex
        private_email='health-private-email@example.invalid'
        for path,expected in [('/allowed403',403),('/allowed429',429),('/blocked',200),('/.well-known/ucp',500),('/store-api/_mcp',500),('/llms.txt',200),('/search',200)]:
            status,_,_=http(path+'?email='+private_email+'&q='+private_query,fixture=True,context=context)
            require(status==expected,'Fixture visitor response status differs')
            time.sleep(.25)
        observed=counts(state['channel'])
        require(observed=={'requests':7,'sessions':1,'dailyRequests':7},'Visitor observations did not form the expected single session')
        status,_,body=http('/api/_action/agentenblick/health?domainId='+state['domain'],token)
        health=json.loads(body)
        finding_counts={}
        for row in health['findings']:
            finding_counts[row['code']]=finding_counts.get(row['code'],0)+row['requests']
        require(finding_counts=={'allowed_but_blocked':2,'disallowed_but_observed':1,'ucp_error':1,'mcp_error':1},'Observed robots/protocol contradictions differ')
        after=health['afterLlms']
        require(after['sessionsReadLlms']==1 and after['sessionsWithLaterRequests']==1,'Successful llms response did not precede later activity')
        steps={r['stage']:r['sessions'] for r in after['steps']}
        require(steps['search']==1 and sum(steps.values())==1,'After-llms report inferred unobserved stages')
        evidence['visitors']={'requests':7,'sessions':1,'findings':finding_counts,'afterLlms':after}
        php="require '/var/www/html/vendor/autoload.php';$p=(new Doctrine\\DBAL\\Tools\\DsnParser(['mysql'=>'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware');$t=new Aithos\\Agentenblick\\Health\\ProbeTokens(Doctrine\\DBAL\\DriverManager::getConnection($p));echo $t->issue('GET','"+ORIGIN+"/llms.txt','GPTBot/1.0 HealthHTTPAcceptance');"
        capability=execute('php','-r',php)
        require(http('/llms.txt',fixture=True,context=context,capability=capability)[0]==200,'Capability request failed')
        time.sleep(.25)
        require(counts(state['channel'])['requests']==7,'Valid capability failed to exclude its one request')
        http('/llms.txt',fixture=True,context=context,capability=capability)
        http('/search',fixture=True,context=context,capability='fixed-probe-marker')
        time.sleep(.3)
        require(counts(state['channel'])['requests']==9,'Replayed or fixed headers suppressed real observations')
        evidence['oneUseExclusion']={'validExcluded':1,'replayCaptured':1,'fixedHeaderCaptured':1}
        columns=sql("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('agentenblick_request','agentenblick_session','agentenblick_health_snapshot','agentenblick_health_daily','agentenblick_state') AND DATA_TYPE IN ('varchar','char','text','mediumtext','json')").splitlines()
        matches=0
        for line in columns:
            table,column=line.split('\t')
            where=' OR '.join('CAST(`'+column+'` AS CHAR) LIKE '+literal('%'+value+'%') for value in [context,private_query,private_email,capability,'fixed-probe-marker'])
            matches+=int(sql('SELECT COUNT(*) FROM `'+table+'` WHERE '+where))
        require(matches==0,'Forbidden visitor or capability data was retained')
        evidence['minimization']={'columnsScanned':len(columns),'forbiddenMarkerMatches':0}
        before_log=execute('cat',state['log'])
        console('system:config:set','--json',KEY,'true');console('cache:clear')
        status,_,body=http('/api/_action/agentenblick/health/probe',token,{'domainId':state['domain'],'autoTest':True})
        require(status==503 and json.loads(body).get('reasonCode')=='offline','Offline explicit probe was not refused')
        status,_,body=http('/api/_action/agentenblick/health?domainId='+state['domain'],token)
        require(status==200 and json.loads(body)['offline'] is True,'Offline saved report unavailable')
        local=json.loads(console('agentenblick:health','--domain='+state['domain']))
        require(local['offline'] is True and local['snapshot'] is not None,'Offline CLI report unavailable')
        require(execute('cat',state['log'])==before_log,'Offline/read-only operations performed an HTTP request')
        evidence['offline']={'explicitProbeStatus':503,'savedApiStatus':200,'savedCli':True,'additionalFixtureHttpRequests':0}
        evidence['passed']=True
    except Exception as error:
        evidence['failure']=str(error) if isinstance(error,AssertionError) else type(error).__name__
        raise
    finally:
        evidence['finishedAt']=time.time()
        OUT.parent.mkdir(exist_ok=True);OUT.write_text(json.dumps(evidence,indent=2)+'\n')
        if evidence['passed'] and keep:
            evidence['cleanup']={'pendingBrowserReview':True};OUT.write_text(json.dumps(evidence,indent=2)+'\n')
        else:
            cleanup()
    return evidence


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--keep-for-browser',action='store_true');parser.add_argument('--cleanup',action='store_true');args=parser.parse_args()
    if args.cleanup:
        print(json.dumps(cleanup()))
    else:
        try:
            result=main(args.keep_for_browser);print(json.dumps({'passed':result['passed'],'evidence':str(OUT),'pendingBrowserReview':args.keep_for_browser}))
        except Exception:
            print(json.dumps({'passed':False,'evidence':str(OUT)}));raise
