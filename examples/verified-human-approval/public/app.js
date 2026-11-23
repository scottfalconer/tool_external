'use strict';

const result = document.querySelector('#result');
const csrf = document.querySelector('meta[name="csrf"]').content;
const from64 = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), character => character.charCodeAt(0));
const to64 = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function post(path, data) {
  const response = await fetch(path, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(data)});
  const value = await response.json();
  if (!response.ok) throw new Error(value.message || value.error || 'Approval request failed.');
  return value;
}

function options(value, registration = false) {
  const normalized = {...value, challenge: from64(value.challenge)};
  for (const key of ['allowCredentials', 'excludeCredentials']) {
    if (normalized[key]) normalized[key] = normalized[key].map(item => ({...item, id: from64(item.id)}));
  }
  if (registration) normalized.user = {...value.user, id: from64(value.user.id)};
  return normalized;
}

function serialize(credential, registration = false) {
  const response = {clientDataJSON: to64(credential.response.clientDataJSON)};
  if (registration) {
    response.attestationObject = to64(credential.response.attestationObject);
    response.transports = credential.response.getTransports?.() || [];
  } else {
    response.authenticatorData = to64(credential.response.authenticatorData);
    response.signature = to64(credential.response.signature);
    response.userHandle = credential.response.userHandle === null ? null : to64(credential.response.userHandle);
  }
  return {id: credential.id, rawId: to64(credential.rawId), type: credential.type, response, clientExtensionResults: credential.getClientExtensionResults()};
}

for (const button of document.querySelectorAll('button')) {
  button.addEventListener('click', async () => {
    button.disabled = true;
    result.textContent = '';
    try {
      if (button.id === 'enroll') {
        const prepared = await post('/enroll/options', {});
        const credential = await navigator.credentials.create({publicKey: options(prepared.options, true)});
        await post('/enroll/complete', {code: document.querySelector('#code').value, response: serialize(credential, true)});
        result.textContent = 'Passkey enrolled. Open the review link supplied by Drupal.';
      } else if (button.id === 'approve') {
        const prefix = `/review/${button.dataset.request}`;
        const prepared = await post(`${prefix}/begin`, {});
        const credential = await navigator.credentials.get({publicKey: options(prepared.options)});
        const completed = await post(`${prefix}/complete`, {ceremonyId: prepared.ceremonyId, assertion: serialize(credential)});
        result.textContent = completed.status === 'completed' ? 'Candidate authorized. Return to Drupal and retry publication; this page has not published it.' : `Approval status: ${completed.status}`;
      } else {
        const cancelled = await post(`/review/${button.dataset.request}/cancel`, {});
        result.textContent = `Approval status: ${cancelled.status}`;
      }
    } catch (error) {
      result.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
}
