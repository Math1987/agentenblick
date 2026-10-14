#!/usr/bin/env python3
"""Blind evaluation of frozen experimental heuristics on the disposable bench.

Profiles are scripted HTTP requests, including synthetic browser references.
They are NOT real human sessions or browser automation. Results never establish
an actual human false-positive rate. The final setting is always disabled.
No fixtures, Shopware account, order, email or external network call is created.
"""
import hashlib
import json
import re
import time
import uuid
from datetime import datetime, timezone
from acceptance import CONTAINER, PLUGIN, ROOT, console, execute, sql

OUT = ROOT / '.local/heuristics-acceptance.json'
KEYS = ['AithosAgentenblick.config.heuristicsEnabled', 'AithosAgentenblick.config.heuristicThreshold', 'AithosAgentenblick.config.heuristicEpoch']
NODE_TRAFFIC = r'''
const http = require('node:http');
const config = JSON.parse(process.argv[1]);
const token = config.token;
let cookies = {};
let offered = 0;
const statuses = {};
const gaps = [];
let previous = null;
(async () => {
for (let i = 0; i < config.profile.requests; i++) {
  const headers = {host:'127.0.0.1:18080','user-agent':config.ua,'sw-context-token':token,'x-agentenblick-test-ip':'198.51.100.77',accept:'*/*'};
  if (config.profile.headers !== 'minimal') {
    headers.accept='text/html,application/xhtml+xml';
    if(config.profile.headers!=='privacy') {
      headers['accept-language']='de-DE,de;q=0.9';
      headers['sec-fetch-site']='same-origin';headers['sec-fetch-mode']='navigate';headers['sec-fetch-dest']='document';
      if(config.profile.headers==='chromium') headers['sec-ch-ua']='"Chromium";v="130", "Not-A.Brand";v="99"';
    }
  }
  if(config.profile.referer && i>0) headers.referer='http://127.0.0.1:18080/checkout/cart';
  if(config.profile.cookies && Object.keys(cookies).length) headers.cookie=Object.entries(cookies).map(([k,v])=>k+'='+v).join('; ');
  const now=Date.now();if(previous!==null) gaps.push(now-previous);previous=now;
  await new Promise((resolve,reject) => {
    const req=http.request({host:'127.0.0.1',port:18081,path:'/checkout/cart',method:'GET',headers},res=>{
      statuses[res.statusCode]=(statuses[res.statusCode]||0)+1;
      for(const raw of res.headers['set-cookie']||[]) {const first=raw.split(';')[0];const index=first.indexOf('=');if(index>0) {const name=first.slice(0,index);if(name.startsWith('session-')) offered++;cookies[name]=first.slice(index+1);}}
      res.resume();res.on('end',resolve);res.on('error',reject);
    });req.setTimeout(25000,()=>req.destroy(new Error('timeout')));req.on('error',reject);req.end();
  });
  if(i+1<config.profile.requests) await new Promise(resolve=>setTimeout(resolve,config.profile.pausesMs[i%config.profile.pausesMs.length]));
}
process.stdout.write(JSON.stringify({statuses,cookieOffers:offered,requests:config.profile.requests,minimumGapMs:gaps.length?Math.min(...gaps):null,maximumGapMs:gaps.length?Math.max(...gaps):null}));
})().catch(()=>{process.stderr.write('isolated HTTP profile failed\n');process.exit(1)});
'''


def literal(value):
    return "CONVERT(X'" + str(value).encode().hex() + "' USING utf8mb4) COLLATE utf8mb4_unicode_ci"


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def main():
    evidence = {'startedAt': datetime.now(timezone.utc).isoformat(), 'experimental': True, 'realHumanReferenceAvailable': False, 'humanFalsePositiveCriterionValidated': False, 'profiles': [], 'cleanup': {}, 'passed': False}
    frozen = json.loads((ROOT / 'tests/fixtures/heuristics-v1-frozen.json').read_text())
    manifest = ROOT / 'tests/fixtures/heuristics-heldout.json'
    profiles = json.loads(manifest.read_text())['profiles']
    marker = uuid.uuid4().hex[:12]
    backup = 'agentenblick_accept_heuristic_' + marker
    config_filter = 'configuration_key IN (' + ','.join(literal(key) for key in KEYS) + ')'
    saved = mutated = False
    try:
        require(CONTAINER == 'agentenblick-shop' and re.search(r'Environment\s+test\b', console('about')), 'Only disposable APP_ENV=test bench is allowed')
        source_hash = hashlib.sha256((ROOT / 'src/Heuristics/HeuristicScore.php').read_bytes()).hexdigest()
        require(source_hash == frozen['scoreSourceSha256'], 'Frozen scoring source changed; do not tune it against these held-out profiles')
        deployed_hash = execute('sha256sum', PLUGIN + '/src/Heuristics/HeuristicScore.php').split()[0]
        require(deployed_hash == source_hash, 'The bench scoring source differs from the frozen local source')
        evidence['scoreVersion'] = frozen['version']
        evidence['threshold'] = frozen['threshold']
        evidence['frozenScoreSha256'] = source_hash
        evidence['heldOutManifestSha256'] = hashlib.sha256(manifest.read_bytes()).hexdigest()
        evidence['frozenWeights'] = frozen['weights']
        # Explicitly fail if the operator already enabled the experiment.
        enabled = sql('SELECT COUNT(*) FROM system_config WHERE configuration_key=' + literal(KEYS[0]) + " AND JSON_EXTRACT(configuration_value,'$._value')=true")
        require(int(enabled) == 0, 'Existing enabled experiment must be preserved; acceptance refused')
        sql('CREATE TABLE ' + backup + ' LIKE system_config')
        saved = True
        sql('INSERT INTO ' + backup + ' SELECT * FROM system_config WHERE ' + config_filter)
        mutated = True
        sql('DELETE FROM system_config WHERE ' + config_filter)
        console('system:config:set', '--json', KEYS[0], 'true')
        console('system:config:set', '--json', KEYS[1], str(frozen['threshold']))
        console('cache:clear')
        before_requests = int(sql('SELECT COALESCE(SUM(requests),0) FROM agentenblick_daily'))
        before_sessions = int(sql('SELECT COUNT(*) FROM agentenblick_session'))
        for index, profile in enumerate(profiles):
            ua = profile['ua'] + ' HeldOut/' + marker + '/' + str(index)
            # The tool-recognition pattern also has a bare node case; the suffix
            # deliberately makes this a distinct unseen UA spelling.
            traffic = json.loads(execute('node', '-e', NODE_TRAFFIC, json.dumps({'token': uuid.uuid4().hex, 'ua': ua, 'profile': profile})))
            require(sum(traffic['statuses'].values()) == profile['requests'] and set(traffic['statuses']) == {'200'}, 'A profile did not reach the intended checkout-cart page')
            time.sleep(.3)
            raw = sql("SELECT JSON_OBJECT('requests',COUNT(*),'sessions',COUNT(DISTINCT session_id),'score',COALESCE(MAX(heuristic_score),0),'flags',COALESCE(BIT_OR(heuristic_flags),0)) FROM agentenblick_request WHERE user_agent=" + literal(ua) + " AND evidence='suspected'")
            result = json.loads(raw)
            detected = result['sessions'] > 0
            evidence['profiles'].append({'profile': profile['id'], 'truth': profile['truth'], 'detected': detected, 'observedSuspectedRequests': result['requests'], 'maximumScore': result['score'], 'flags': result['flags'], 'traffic': traffic})
        request_delta = int(sql('SELECT COALESCE(SUM(requests),0) FROM agentenblick_daily')) - before_requests
        session_delta = int(sql('SELECT COUNT(*) FROM agentenblick_session')) - before_sessions
        require(request_delta == sum(profile['requests'] for profile in profiles), 'Capture count differs from the exclusive held-out traffic; results cannot establish false negatives')
        require(session_delta == len(profiles), 'Held-out contexts did not each form exactly one session')
        evidence['captureControl'] = {'requests': request_delta, 'sessions': session_delta, 'exclusiveTrafficRequired': True}
        scripts = [row for row in evidence['profiles'] if row['truth'] == 'script']
        references = [row for row in evidence['profiles'] if row['truth'] == 'synthetic_browser_reference']
        true_positive = sum(row['detected'] for row in scripts)
        false_positive = sum(row['detected'] for row in references)
        evidence['matrix'] = {'scriptProfiles': len(scripts), 'truePositives': true_positive, 'falseNegatives': len(scripts)-true_positive, 'scriptDetectionPct': round(100*true_positive/len(scripts),1), 'syntheticReferenceProfiles': len(references), 'syntheticFalsePositives': false_positive, 'syntheticTrueNegatives': len(references)-false_positive, 'syntheticFalsePositivePct': round(100*false_positive/len(references),1)}
        evidence['scriptTarget90PctReached'] = true_positive / len(scripts) >= .9
        evidence['passed'] = True  # successful measurement, not a claim of human calibration
    except Exception as error:
        evidence['failure'] = str(error) if isinstance(error, AssertionError) else type(error).__name__
        raise
    finally:
        if saved:
            try:
                if mutated:
                    sql('START TRANSACTION; DELETE FROM system_config WHERE ' + config_filter + '; INSERT INTO system_config SELECT * FROM ' + backup + '; COMMIT')
                    console('system:config:set', '--json', KEYS[0], 'false')
                    console('cache:clear')
                sql('DROP TABLE ' + backup)
                evidence['cleanup']['configurationRestoredExperimentOff'] = True
            except Exception:
                evidence['passed'] = False
                evidence['cleanup']['backupRetained'] = backup
        evidence['finishedAt'] = datetime.now(timezone.utc).isoformat()
        OUT.parent.mkdir(exist_ok=True)
        OUT.write_text(json.dumps(evidence,indent=2)+'\n')
    require(evidence['passed'], 'Experiment cleanup failed')
    return evidence


if __name__ == '__main__':
    try:
        result = main()
        print(json.dumps({'measurementComplete': result['passed'], 'matrix': result['matrix'], 'realHumanCriterionValidated': False, 'experimentEnabled': False, 'evidence': str(OUT)}))
    except Exception:
        print(json.dumps({'measurementComplete': False, 'evidence': str(OUT)}))
        raise SystemExit(1)
