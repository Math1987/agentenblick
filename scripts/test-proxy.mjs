// Local test harness only. Never package or expose this controllable proxy in production.
import http from 'node:http';
import { isIP } from 'node:net';
import { readFile } from 'node:fs/promises';

const directory = '/tmp/agentenblick-test-jwks.json';
http.createServer(async (incoming, outgoing) => {
  if (incoming.url === '/.well-known/http-message-signatures-directory') {
    try {
      outgoing.writeHead(200, { 'content-type': 'application/json' });
      outgoing.end(await readFile(directory));
    } catch {
      outgoing.writeHead(503).end();
    }
    return;
  }
  const headers = { ...incoming.headers, host: '127.0.0.1:18080' };
  const simulated = headers['x-agentenblick-test-ip'];
  for (const key of ['forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'cf-connecting-ip', 'x-agentenblick-test-ip']) delete headers[key];
  headers['x-forwarded-for'] = typeof simulated === 'string' && isIP(simulated) ? simulated : '198.51.100.30';
  const upstream = http.request({ host: '127.0.0.1', port: 80, method: incoming.method, path: incoming.url, headers }, response => {
    outgoing.writeHead(response.statusCode, response.headers);
    response.pipe(outgoing);
  });
  upstream.on('error', () => outgoing.writeHead(502).end());
  incoming.pipe(upstream);
}).listen(18081, '127.0.0.1');
