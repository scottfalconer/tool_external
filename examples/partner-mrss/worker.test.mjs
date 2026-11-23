import assert from 'node:assert/strict';
import {test} from 'node:test';
import worker from './worker.mjs';

const env = {FEED_TOKEN: 'feed-test-fixture'};
const episode = {
  id: 'episode-42',
  title: 'Tides & Trails <Part 1>',
  media_url: 'https://media.example.test/42.mp4?lang=en&cut=full',
};
const inputs = {channel: 'Coastal Stories', channel_url: 'https://video.example.test/', episodes: [episode]};
const call = {
  protocol: 'drupal-external-tool/1',
  tool: {id: 'external:partner_mrss', version: 1},
  invocation_id: 'feed-test-42',
  inputs,
};

function invoke(body, token = env.FEED_TOKEN) {
  return worker.fetch(new Request('https://worker.example.test/invoke', {
    method: 'POST',
    headers: {Authorization: `Bearer ${token}`, 'Content-Type': 'application/json'},
    body: JSON.stringify(body),
  }), env);
}

test('renders the two supplied episodes in order and escapes text and attributes', async () => {
  const second = {...episode, id: 'episode-43', title: 'Open Water'};
  const response = await invoke({...call, inputs: {...inputs, episodes: [episode, second]}});
  assert.equal(response.status, 200);
  const result = await response.json();
  assert.equal(result.protocol, call.protocol);
  assert.equal(result.invocation_id, call.invocation_id);
  assert.equal(result.outputs.item_count, 2);
  assert.match(result.outputs.xml, /xmlns:media="http:\/\/search\.yahoo\.com\/mrss\/"/);
  assert.match(result.outputs.xml, /<title>Tides &amp; Trails &lt;Part 1&gt;<\/title>/);
  assert.ok(result.outputs.xml.includes('<media:content url="https://media.example.test/42.mp4?lang=en&amp;cut=full" medium="video"/>'));
  assert.match(result.outputs.xml, /<guid isPermaLink="false">episode-42<\/guid>.*<guid isPermaLink="false">episode-43<\/guid>/);
});

test('a feed with no episodes returns its channel and zero items', async () => {
  const empty = {channel: inputs.channel, channel_url: inputs.channel_url};
  for (const projection of [empty, {...empty, episodes: []}]) {
    const response = await invoke({...call, inputs: projection});
    assert.equal(response.status, 200);
    const {outputs} = await response.json();
    assert.equal(outputs.item_count, 0);
    assert.match(outputs.xml, /<title>Coastal Stories<\/title>/);
    assert.doesNotMatch(outputs.xml, /<item>/);
  }
});

test('rejects an incorrect bearer, unsupported tool calls and invalid feed data', async () => {
  assert.equal((await invoke(call, 'incorrect')).status, 401);
  for (const bad of [
    {...call, tool: {...call.tool, version: 2}},
    {...call, tool: {...call.tool, id: 'external:other'}},
    {...call, inputs: {...inputs, episodes: {}}},
    {...call, inputs: {...inputs, channel: 'Invalid\u0000XML'}},
    {...call, inputs: {...inputs, episodes: [{...episode, media_url: 'file:///video'}]}},
  ]) {
    assert.equal((await invoke(bad)).status, 400);
  }
});

test('publishes the declaration used by the Worker', async () => {
  const response = await worker.fetch(new Request('https://worker.example.test/definition'), env);
  const definition = await response.json();
  assert.equal('external:' + definition.id, call.tool.id);
  assert.equal(definition.contract_version, call.tool.version);
  assert.deepEqual(definition.inputs.episodes.default_value, []);
  assert.equal(definition.outputs.item_count.data_type, 'integer');
});
