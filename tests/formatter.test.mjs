import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createInterface} from 'node:readline';
import {test} from 'node:test';
import {fileURLToPath} from 'node:url';
import formatter from '../examples/normalize-title.mjs';

test('the shipped formatter runs locally and authenticates POST requests', {timeout: 10000}, async t => {
  assert.equal(typeof formatter.fetch, 'function');
  const server = spawn(process.execPath, [fileURLToPath(new URL('../examples/serve-formatter.mjs', import.meta.url))], {
    env: {...process.env, FORMATTER_TOKEN: 'formatter-fixture', PORT: '0'},
    stdio: ['ignore', 'pipe', 'inherit'],
  });
  t.after(() => server.kill());
  const lines = createInterface({input: server.stdout});
  const [ready] = await once(lines, 'line');
  const url = ready.match(/^Demo function listening on (http:\/\/127\.0\.0\.1:\d+\/invoke)$/)?.[1];
  assert.ok(url, ready);
  const call = {
    protocol: 'drupal-external-tool/1',
    tool: {id: 'external:normalize_title', version: 1},
    invocation_id: 'formatter-example',
    inputs: {title: '  Winter guide  '},
  };
  for (const token of [null, 'formatter-fixturx', 'short', 'formatter-fixture']) {
    const response = await fetch(url, {
      method: 'POST', body: JSON.stringify(call),
      headers: token ? {Authorization: `Bearer ${token}`} : {},
    });
    if (token === 'formatter-fixture') {
      assert.equal(response.status, 200);
      assert.deepEqual(await response.json(), {
        protocol: call.protocol, invocation_id: call.invocation_id, outputs: {normalized: 'Winter guide'},
      });
    } else {
      assert.equal(response.status, 401);
    }
  }
});
