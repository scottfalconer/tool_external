// A Fetch handler for the normalize_title declaration in the module README.
export default {
  async fetch(request, env) {
    if (request.method !== 'POST') return new Response(null, {status: 405, headers: {Allow: 'POST'}});
    if (!env.FORMATTER_TOKEN || request.headers.get('Authorization') !== `Bearer ${env.FORMATTER_TOKEN}`) {
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
