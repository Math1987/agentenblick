import http from 'node:http';
import { createHash, createPrivateKey, generateKeyPairSync, sign } from 'node:crypto';
import { readFile, writeFile, stat, unlink } from 'node:fs/promises';
import { setTimeout as delay } from 'node:timers/promises';

const [mode, directory, run, stageText] = process.argv.slice(2);
if (!/^\/tmp\/agentenblick-phase-probe-[a-f0-9]{16}$/.test(directory || '') || !/^[a-f0-9]{16}$/.test(run || '')) throw new Error('Invalid disposable directory');
if (mode === 'setup') {
    const { publicKey, privateKey } = generateKeyPairSync('ed25519');
    const jwk = publicKey.export({ format: 'jwk' });
    const kid = createHash('sha256').update(JSON.stringify({ crv: jwk.crv, kty: jwk.kty, x: jwk.x })).digest('base64url');
    await writeFile(`${directory}/key.pem`, privateKey.export({ format: 'pem', type: 'pkcs8' }), { mode: 0o600 });
    await writeFile(`${directory}/jwks.json`, JSON.stringify({ keys: [{ ...jwk, kid }] }));
    console.log(JSON.stringify({ prepared: true }));
} else if (mode === 'run') {
    await writeFile(`${directory}/traffic.pid`, String(process.pid));
    const stage = Number(stageText);
    if (![0, 1, 2, 3].includes(stage)) throw new Error('Invalid stage');
    const privateKey = createPrivateKey(await readFile(`${directory}/key.pem`));
    const jwk = JSON.parse(await readFile(`${directory}/jwks.json`, 'utf8')).keys[0];
    const log = `${directory}/phases.jsonl`;
    const results = [];
    let rowsSeen = 0;
    let sizeSeen = 0;
    for (let index = 0; index < 1024; index += 1) {
        const temperature = index < 4 ? 0 : index < 24 ? 1 : 2;
        const population = index % 4;
        const sample = stage * 10000 + index;
        const headers = { host: '127.0.0.1:18080', 'accept-encoding': 'identity', connection: 'close', 'x-agentenblick-phase-run': run, 'x-agentenblick-phase-sample': `${stage}:${temperature}:${population}:${sample}`, 'user-agent': 'Mozilla/5.0 Chrome/120.0', 'x-agentenblick-test-ip': '198.51.100.20' };
        if (population === 1) headers['user-agent'] = 'Claude-User/1.0';
        if (population === 2) { headers['user-agent'] = 'ExampleTestAgent/1.0'; headers['x-agentenblick-test-ip'] = '172.29.240.42'; }
        if (population === 3) {
            const now = Math.floor(Date.now() / 1000);
            const agent = '"http://127.0.0.1:18081"';
            const params = `("@authority" "signature-agent");created=${now};expires=${now + 300};keyid="${jwk.kid}";alg="ed25519";tag="web-bot-auth"`;
            const base = `"@authority": 127.0.0.1:18080\n"signature-agent": ${agent}\n"@signature-params": ${params}`;
            headers['signature-agent'] = agent;
            headers['signature-input'] = `sig=${params}`;
            headers.signature = `sig=:${sign(null, Buffer.from(base), privateKey).toString('base64')}:`;
        }
        const started = performance.now();
        const response = await new Promise((resolve, reject) => {
            const request = http.request({ host: '127.0.0.1', port: 18081, method: 'GET', path: '/', agent: false, headers }, (incoming) => {
                const digest = createHash('sha256');
                let bytes = 0;
                incoming.on('data', (chunk) => { digest.update(chunk); bytes += chunk.length; });
                incoming.on('error', reject);
                incoming.on('end', () => resolve({ status: incoming.statusCode, bytes, bodySha256: digest.digest('hex'), clientMs: performance.now() - started }));
            });
            request.on('error', reject);
            request.setTimeout(15000, () => request.destroy(new Error('Disposable HTTP timeout')));
            request.end();
        });
        // Wait for shutdown evidence before the next request so terminate workers
        // cannot overlap and distort the following sample. This wait is untimed.
        const deadline = performance.now() + 15000;
        let row;
        while (performance.now() < deadline) {
            const size = (await stat(log)).size;
            if (size > sizeSeen) {
                const lines = (await readFile(log, 'utf8')).trim().split('\n').filter(Boolean);
                if (lines.length > rowsSeen) {
                    if (lines.length !== rowsSeen + 1) throw new Error('Unexpected extra shutdown evidence');
                    row = JSON.parse(lines.at(-1));
                    rowsSeen = lines.length;
                    sizeSeen = size;
                    break;
                }
            }
            await delay(2);
        }
        if (!row || row.stage !== stage || row.sample !== sample || row.population !== population || row.temperature !== temperature || row.open_timers !== 0) throw new Error('Missing or mismatched shutdown evidence');
        results.push({ ...row, ...response });
    }
    await writeFile(`${directory}/stage-${stage}.json`, JSON.stringify({ stage, results }));
    await unlink(`${directory}/traffic.pid`);
    console.log(JSON.stringify({ stage, samples: results.filter((row) => row.temperature === 2).length, shutdownRows: rowsSeen }));
} else throw new Error('Invalid operation');
