// Web Crypto verifies the HMAC in native code, including its tag comparison.
export async function hasBearerToken(request, token) {
  const header = request.headers.get('Authorization');
  if (!header || typeof token !== 'string' || !token) return false;
  const encode = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', encode.encode(token),
    {name: 'HMAC', hash: 'SHA-256'}, false, ['sign', 'verify']);
  const expected = await crypto.subtle.sign('HMAC', key, encode.encode(`Bearer ${token}`));
  return crypto.subtle.verify('HMAC', key, expected, encode.encode(header));
}
