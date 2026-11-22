import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const declaration = JSON.parse(readFileSync(new URL('./feed-tool.json', import.meta.url), 'utf8'));

// A full checkout can exercise the optional content example's Fetch handler.
const campaignHandler = process.argv[2]
  ? (await import(pathToFileURL(process.argv[2]))).handleRequest
  : (request) => invoke(request, 'external:campaign_prepare', prepareCampaign);

function prepareCampaign({ title, campaign_code }) {
  const violations = /^CAM-\d{3}$/.test(campaign_code) ? [] : [{
    input: 'campaign_code', code: 'invalid_campaign', message: 'Invalid campaign code.',
  }];
  return { campaign_label: `${campaign_code}: ${title.trim()}`, violations };
}

function escapeXml(text) {
  return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&apos;');
}

function renderFeed({ channel, channel_url, episodes = [] }) {
  const items = episodes.map(({ title, media_url }) =>
    `<item><title>${escapeXml(title)}</title><media:content url="${escapeXml(media_url)}"/></item>`);
  return {
    xml: '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>'
      + `<title>${escapeXml(channel)}</title><link>${escapeXml(channel_url)}</link>`
      + items.join('') + '</channel></rss>',
    item_count: items.length,
  };
}

async function invoke(request, toolId, execute) {
  if (request.headers.get('authorization') !== 'Bearer fixture-only') {
    return new Response(null, { status: 401 });
  }
  const call = await request.json();
  if (call.protocol !== 'drupal-external-tool/1' || call.tool?.id !== toolId ||
      call.tool.version !== 1 || typeof call.invocation_id !== 'string') {
    return new Response(null, { status: 400 });
  }
  return Response.json({
    protocol: call.protocol, invocation_id: call.invocation_id, outputs: execute(call.inputs),
  });
}

const server = createServer(async (req, res) => {
  let body = '';
  for await (const part of req) body += part;
  if (req.url.startsWith('/feed/')) {
    if (req.method === 'GET' && req.url === '/feed/definition') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(declaration));
      return;
    }
    const request = new Request('http://localhost/feed', { method: req.method, headers: req.headers, body });
    const response = await invoke(request, 'external:' + declaration.id, renderFeed);
    res.writeHead(response.status, Object.fromEntries(response.headers));
    if (req.url === '/feed/bad-output') {
      const call = await response.json();
      call.outputs.item_count = 'many';
      res.end(JSON.stringify(call));
    }
    else {
      res.end(Buffer.from(await response.arrayBuffer()));
    }
    return;
  }
  if (req.url === '/invalid') {
    res.writeHead(200, { 'Content-Type': 'application/json' }).end('{broken');
    return;
  }
  if (req.url === '/oversize') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.flushHeaders();
    res.end(' '.repeat(70000));
    return;
  }
  if (req.url === '/redirect') {
    res.writeHead(307, { Location: '/invoke' }).end();
    return;
  }
  if (req.url === '/continue') {
    res.writeContinue();
  }
  if (req.url === '/early-hints' || req.url === '/early-hints/text') {
    res.writeEarlyHints({ link: '</fixture.css>; rel=preload; as=style' });
    if (req.url === '/early-hints/text') {
      res.writeHead(200, { 'Content-Type': 'text/plain' }).end('ready');
      return;
    }
  }
  const request = new Request(`http://127.0.0.1${req.url}`, {
    method: req.method,
    headers: req.headers,
    body,
  });
  const response = await campaignHandler(request, 'fixture-only');
  if (req.url === '/slow_headers') {
    const socket = res.socket;
    socket.write('HTTP/1.1 200 OK\r\n');
    for (let i = 0; i < 6; i++) {
      await new Promise(resolve => setTimeout(resolve, 100));
      if (socket.destroyed) return;
      socket.write(`X-Slow-${i}: true\r\n`);
    }
    socket.end('Content-Type: application/json\r\nConnection: close\r\n\r\n'
      + await response.text());
    return;
  }
  res.writeHead(response.status, Object.fromEntries(response.headers));
  if (req.url === '/slow') {
    res.flushHeaders();
    await new Promise(resolve => setTimeout(resolve, 400));
  }
  res.end(Buffer.from(await response.arrayBuffer()));
});
server.listen(Number(process.env.PORT ?? 18787), '127.0.0.1', () => console.log(`READY ${server.address().port}`));

