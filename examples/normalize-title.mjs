// A Fetch handler for the normalize_title declaration in the module README.
// Web Crypto verifies the HMAC in native code, including its tag comparison.
async function hasBearerToken(request, token) {
  const header = request.headers.get('Authorization');
  if (!header || typeof token !== 'string' || !token) return false;
  const encode = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', encode.encode(token),
    {name: 'HMAC', hash: 'SHA-256'}, false, ['sign', 'verify']);
  const expected = await crypto.subtle.sign('HMAC', key, encode.encode(`Bearer ${token}`));
  return crypto.subtle.verify('HMAC', key, expected, encode.encode(header));
}

export default {
  async fetch(request, env) {
    if (request.method !== 'POST') return new Response(null, {status: 405, headers: {Allow: 'POST'}});
    if (!await hasBearerToken(request, env.FORMATTER_TOKEN)) {
      return new Response(null, {status: 401});
    }
    const call = await request.json().catch(() => null);
    if (call?.protocol !== 'drupal-external-tool/1' || call.tool?.id !== 'external:normalize_title' ||
        call.tool.version !== 1 || typeof call.invocation_id !== 'string' || !call.invocation_id ||
        typeof call.inputs?.title !== 'string') {
      return new Response(null, {status: 400});
    }
    return Response.json({
      protocol: call.protocol,
      invocation_id: call.invocation_id,
      outputs: {normalized: call.inputs.title.trim()},
    });
  },
};
