// Adapt a Fetch handler to a Node loopback endpoint for local development.
import {webcrypto} from 'node:crypto';
import {createServer} from 'node:http';

export function serveLoopback(handleRequest) {
  // Node 18 exposes Web Crypto through node:crypto in file entry points.
  globalThis.crypto ??= webcrypto;
  const server = createServer(async (incoming, outgoing) => {
    try {
      const chunks = [];
      let bytes = 0;
      for await (const chunk of incoming) {
        bytes += chunk.length;
        if (bytes > 65536) {
          outgoing.writeHead(413).end();
          return;
        }
        chunks.push(chunk);
      }
      const request = new Request(new URL(incoming.url, 'http://127.0.0.1'), {
        method: incoming.method,
        headers: incoming.headers,
        body: ['GET', 'HEAD'].includes(incoming.method) ? undefined : Buffer.concat(chunks),
      });
      const response = await handleRequest(request);
      outgoing.writeHead(response.status, Object.fromEntries(response.headers));
      outgoing.end(Buffer.from(await response.arrayBuffer()));
    } catch {
      outgoing.writeHead(500).end();
    }
  });
  server.listen(Number(process.env.PORT ?? 8787), '127.0.0.1', () => {
    console.info(`Demo function listening on http://127.0.0.1:${server.address().port}/invoke`);
  });
}
