# Implement a Drupal external tool

Start with one customer action. For example, an editor wants to apply the
team's title formatting rule. The function accepts a title and returns
formatted text. A preview button can display that text. A save handler can
apply it after Drupal authorizes the write. Both callers can use the same tool
registration.

## Write the function

The [installation walkthrough](README.md) includes a complete `normalize_title`
declaration and the Drush command to register it. The
[matching endpoint](examples/normalize-title.mjs) is a complete JavaScript
Fetch handler. Serve it at your chosen HTTPS URL, set its `FORMATTER_TOKEN`
secret, and give the site operator the declaration and the endpoint address.
Any language that handles an authenticated JSON POST can implement the same
contract.

To try the formatter before deploying it, set `FORMATTER_TOKEN` and run
`node examples/serve-formatter.mjs` from the installed module directory. Its
default URL is `http://127.0.0.1:8787/invoke`. The
[local setup](README.md#run-the-formatter-locally) shows how to bind the site's
credential to that URL and enable `allow_local_http` on the development site.

The declaration names the tool, its positive integer contract version, and the
typed fields under `inputs` and `outputs`. Keep stable names for values that
callers use. Describe what the fields mean so that Tool API consumers can
present them. The site operator selects the connection, operation and execution
permission at registration. Grant that permission to the users who should
invoke this particular tool.

On each invocation the endpoint authenticates the bearer, checks the protocol,
tool ID, version and input types, and returns the declared output fields with
the original invocation ID. A business decision belongs in a successful
response. For example, a publication check can return
`{"allowed":false,"field":"body","message":"Replace TBD before publication."}`
as its outputs. An HTTP error means the caller could not obtain a decision.

## Call it at the point where Drupal needs the result

Inject `tool.runner` into the controller, service or plugin that owns the
feature. A caller can handle a failed Tool API result as follows.

```php
$result = $this->runner
  ->run('external:normalize_title', ['title' => $title])
  ->getResult();
if (!$result->isSuccess()) {
  throw new \RuntimeException('Title formatting is unavailable.');
}
$normalized = $result->getContextValues()['normalized'];
```

The current Drupal account supplies the execution identity. Check entity and
field access when selecting content to send. Keep the payload to the fields the
function needs. A form domain check, for example, only needs the domain portion
of an email address. Drupal still decides whether the caller can publish an
Article or store a submission.

Use the ordinary runner for the current user's tool calls. A site integration
that owns a service account can call
`$delegatedRunner->run($tool_id, $inputs, 'content.prepare.v1', $executor)`
after injecting `tool_external.delegated_runner`. Authorize the caller and
select an active, least-privilege Drupal user in trusted site code. The runner
captures the current actor and uses the native Tool API permission check for
the executor. Supply caller labels in code. Keep account data out of plugin
configuration.

- **An editor publishes a checked Article.** Validate the target published
  revision and enforce the decision on saves that skip validation. Attach
  feedback to a known field. If the check fails, keep the previous revision
  live and let the editor save a draft.
- **A visitor submits a contact form.** Implement the form provider's
  spam-check interface and map the decision into its result type. Follow
  the provider's outage policy. Source Canvas Forms continues its other
  checks if the function fails.
- **A writer asks for approved wording.** Map the read tool in the site's
  Tool API to MCP bridge and grant the reader the declared permission.
  Return a tool failure to the client so the writer can retry.

An MCP lookup needs no site-specific PHP caller when the installed bridge
supports Tool API enumeration and invocation. Calling an existing Drupal write
tool afterward goes through that tool's own permissions and save path. A Drupal
form, queue or editorial rule can invoke a tool directly without an MCP client.

## Change a function and verify it

1. Invoke it through the site's actual feature with an account that has the
   permission. Check the Drupal result and its effect on stored data. Retry
   with an account that lacks permission.
2. Change the endpoint implementation while preserving its declaration and
   invoke the same Drupal feature again. To move hosts, update the logical
   connection URL, allowed host and endpoint-bound secret together. Existing
   callers keep their tool ID.
3. Stop the endpoint on a test site. Check that the feature follows its
   intended outage policy. Match the invocation ID in the `tool_external` log
   to your endpoint log. Keep tokens, form values and article bodies out of
   those logs.

A form can validate more than once, and a caller can time out after the
function has already run. Use side-effect-free checks for validation. A tool
that changes external state needs a caller-supplied idempotency key if the
operation can be retried. Follow the [installation walkthrough](README.md) for
the bounded time and message sizes, site connection settings and the Drupal
test command.
