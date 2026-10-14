import http from 'node:http';
import { createHash, generateKeyPairSync, sign } from 'node:crypto';
import { writeFile, mkdir } from 'node:fs/promises';
import { networkInterfaces } from 'node:os';
import { setTimeout as delay } from 'node:timers/promises';

const { publicKey, privateKey } = generateKeyPairSync('ed25519');
const jwk = publicKey.export({ format: 'jwk' });
const keyid = createHash('sha256').update(JSON.stringify({ crv: jwk.crv, kty: jwk.kty, x: jwk.x })).digest('base64url');
await writeFile('/tmp/agentenblick-test-jwks.json', JSON.stringify({ keys: [{ ...jwk, kid: keyid }] }));
const nonProxyAddress = Object.values(networkInterfaces()).flat().find(item => item && !item.internal && item.family === 'IPv4')?.address;
if (!nonProxyAddress) throw new Error('An untrusted source interface is required');
const results = [];

async function request(profile, headers, { direct = false, localAddress, path = '/' } = {}) {
  const started = performance.now();
  const result = await new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port: direct ? 80 : 18081, path, localAddress, headers: { host: '127.0.0.1:18080', ...headers } }, response => {
      const hash = createHash('sha256');
      let bytes = 0;
      response.on('data', chunk => { hash.update(chunk); bytes += chunk.length; });
      response.on('end', () => resolve({ profile, status: response.statusCode, bytes, bodySha256: hash.digest('hex'), clientMs: performance.now() - started }));
    });
    req.on('error', reject);
    req.setTimeout(10000, () => req.destroy(new Error('request timeout')));
    req.end();
  });
  results.push(result);
  await delay(100);
}

function signatureHeaders(dictionary, altered = false, expired = false) {
  const created = Math.floor(Date.now() / 1000) - (expired ? 3600 : 0);
  const agent = dictionary ? 'agent2="http://127.0.0.1:18081"' : '"http://127.0.0.1:18081"';
  const component = dictionary ? '"signature-agent";key="agent2"' : '"signature-agent"';
  const params = `("@authority" ${component});created=${created};expires=${created + 300};keyid="${keyid}";alg="ed25519";tag="web-bot-auth"`;
  const base = `"@authority": 127.0.0.1:18080\n${component}: "http://127.0.0.1:18081"\n"@signature-params": ${params}`;
  const bytes = sign(null, Buffer.from(base), privateKey);
  if (altered) bytes[0] ^= 1;
  return { 'user-agent': 'Mozilla/5.0 Chrome/120.0', 'signature-agent': agent, 'signature-input': `sig2=${params}`, signature: `sig2=:${bytes.toString('base64')}:` };
}

await request('human', { 'user-agent': 'Mozilla/5.0 Chrome/120.0', 'x-agentenblick-test-ip': '198.51.100.20' });
await request('false-gptbot', { 'user-agent': 'GPTBot/1.0' });
await request('declared-claude', { 'user-agent': 'Claude-User/1.0' });
await request('ip-verified', { 'user-agent': 'ExampleTestAgent/1.0', 'x-agentenblick-test-ip': '172.29.240.42' });
await request('ip-outside', { 'user-agent': 'ExampleTestAgent/1.0', 'x-agentenblick-test-ip': '198.51.100.42' });
await request('forged-untrusted', { 'user-agent': 'ExampleTestAgent/1.0', 'x-forwarded-for': '172.29.240.42', 'cf-connecting-ip': '172.29.240.42' }, { direct: true, localAddress: nonProxyAddress });
await request('signed-dictionary', signatureHeaders(true));
await request('signed-legacy', signatureHeaders(false));
await request('signature-altered', signatureHeaders(true, true));
await request('signature-expired', signatureHeaders(true, false, true));
await request('privacy-marker', { 'user-agent': 'GPTBot/1.0', 'sw-context-token': 'sensitive-context-marker', cookie: 'session-=sensitive-cookie-marker' }, { path: '/?sensitive-query-marker=person%40example.test' });

await delay(500);
await mkdir('/tmp/agentenblick-evidence', { recursive: true });
await writeFile('/tmp/agentenblick-evidence/traffic.json', JSON.stringify({ runtime: process.version, results }, null, 2));
console.log(JSON.stringify({ runtime: process.version, requests: results.length, results }, null, 2));
