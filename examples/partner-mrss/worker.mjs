import {hasBearerToken} from '../bearer-token.mjs';
import declaration from './tool.json' with {type: 'json'};

// XML 1.0 permits tab, newline, carriage return and these Unicode ranges.
const invalidXml = /[^\u0009\u000a\u000d\u0020-\ud7ff\ue000-\ufffd\u{10000}-\u{10ffff}]/u;
const entities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;'};

function xmlText(text) {
  if (typeof text !== 'string' || !text.trim() || invalidXml.test(text)) {
    throw new TypeError('Expected nonempty XML text.');
  }
  return text.replace(/[&<>"']/g, character => entities[character]);
}

function feedLink(text) {
  const escaped = xmlText(text);
  const url = new URL(text);
  if (url.protocol !== 'https:' || url.username || url.password) {
    throw new TypeError('Expected an HTTPS link without embedded credentials.');
  }
  return escaped;
}

function renderFeed({channel, channel_url, episodes = []}) {
  const items = episodes.map(({id, title, media_url}) =>
    `<item><guid isPermaLink="false">${xmlText(id)}</guid><title>${xmlText(title)}</title>` +
    `<media:content url="${feedLink(media_url)}" medium="video"/></item>`);
  const xml = '<?xml version="1.0" encoding="UTF-8"?>' +
    '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>' +
    `<title>${xmlText(channel)}</title><link>${feedLink(channel_url)}</link>` +
    `<description>Published episodes</description>${items.join('')}</channel></rss>`;
  return {xml, item_count: items.length};
}

export default {
  async fetch(request, env) {
    const {pathname} = new URL(request.url);
    if (pathname === '/definition' && request.method === 'GET') return Response.json(declaration);
    if (pathname !== '/invoke') return new Response(null, {status: 404});
    if (request.method !== 'POST') return new Response(null, {status: 405, headers: {Allow: 'POST'}});
    if (!await hasBearerToken(request, env.FEED_TOKEN)) return new Response(null, {status: 401});

    const call = await request.json().catch(() => null);
    if (call?.protocol !== 'drupal-external-tool/1' || call.tool?.id !== `external:${declaration.id}` ||
        call.tool.version !== declaration.contract_version || typeof call.invocation_id !== 'string' || !call.invocation_id) {
      return new Response(null, {status: 400});
    }
    try {
      return Response.json({
        protocol: call.protocol,
        invocation_id: call.invocation_id,
        outputs: renderFeed(call.inputs),
      });
    } catch (error) {
      if (error instanceof TypeError) return new Response(null, {status: 400});
      throw error;
    }
  },
};
