# External Tools

External Tools registers an HTTPS function as a native Drupal Tool API tool.
The function author describes its inputs and outputs. The site operator chooses
the endpoint, credential and Drupal permission. Drupal discovers the tool from
local configuration and contacts the function only when a caller invokes it.

Tool API is the only required contributed module. A Drupal controller, hook or
queue worker can call the registered tool directly. The caller decides when to
invoke it, which data the current account can access and what to do if it
fails. ECA or an MCP bridge can consume the same registration on sites that
install them.

## What can I build?

- **“I want a custom Media RSS feed for my distribution partner.”** Drupal
  selects the published episodes the caller can share. A JavaScript Worker
  turns their IDs, titles and video URLs into Media RSS XML and returns the
  item count. To change the partner's format, edit the Worker.
  [Feed walkthrough](https://github.com/scottfalconer/tool_external/tree/main/examples/partner-mrss) ·
  [Worker code](https://github.com/scottfalconer/tool_external/blob/main/examples/partner-mrss/worker.mjs)
- **“I want a reviewer to approve the exact article I'm about to publish.”**
  A site-specific publish hook sends the article's field values to a PHP
  approval service. The reviewer opens a link and approves with a passkey.
  When the editor retries, the hook checks that the approval matches the
  article before saving. Changing the title or body requires a fresh review.
  [Approval walkthrough](https://github.com/scottfalconer/tool_external/tree/main/examples/verified-human-approval) ·
  [Provider code](https://github.com/scottfalconer/tool_external/blob/main/examples/verified-human-approval/src/Application.php)

For a first call, follow the title formatter walkthrough below. The
[developer guide](DEVELOPING.md) shows how to connect a tool to a site feature.

## Install

This alpha declares Drupal `^11.2` and requires PHP 8.3 or later with cURL. The
tested combination is Drupal 11.4.13 and Tool API `1.0.0-beta12`. The alpha
pins that beta because its plugin execution and schema serialization use those
upstream interfaces. The commands below assume a Composer-managed Drupal
project with Drush. Place the release ZIP in a local directory and run these
commands from the project root. Composer's artifact repository requires the PHP
ZIP extension on the machine running Composer.

```sh
composer config repositories.external-tools artifact \
  /absolute/path/to/package-directory
composer require 'drupal/tool_external:0.1.0-alpha5' 'drupal/tool:1.0.0-beta12'
vendor/bin/drush en tool_external -y
```

The module starts with outbound execution disabled and an empty connection
list.

## Configure one connection

This example connects a title formatter. Replace the example URL with the HTTPS
invocation endpoint you operate or trust. Provision the same dedicated bearer
token at the endpoint and in the Drupal environment variable `FORMATTER_TOKEN`.
Add the following to the site's `settings.php`, then run
`vendor/bin/drush cache:rebuild`.

```php
$formatter_url = 'https://functions.example.com/invoke';

$config['tool_external.settings']['connections']['formatter'] = [
  'url' => $formatter_url,
  'secret' => 'formatter_token',
  'timeout' => 2.0,
];
$settings['tool_external']['enabled'] = TRUE;
$settings['tool_external']['allowed_hosts'][] = 'functions.example.com';
$settings['tool_external']['secrets']['formatter_token'] = [
  'url' => $formatter_url,
  'token' => getenv('FORMATTER_TOKEN'),
  'allowed_tools' => ['external:normalize_title'],
];
```

The connection uses a credential reference. Supply its value in runtime
settings from the site's environment or secret manager and bind it to its exact
endpoint. The optional `allowed_tools` list limits that credential to the named
registrations. Omitting the list preserves alpha2 behavior. Configure
destinations you operate or trust. The hosting platform owns outbound network
restrictions. On Drupal's Permissions page (`/admin/people/permissions`), grant
the invoking role the permission named at registration. The default is
`execute external tools`.

## Run the formatter locally

On a development host shared by Drupal and Node 18+, set the same private
`FORMATTER_TOKEN` in Node, Drush and the web PHP environment. Start the
included endpoint from the site's Composer root.

```sh
node web/modules/contrib/tool_external/examples/serve-formatter.mjs
```

The default endpoint is `http://127.0.0.1:8787/invoke`. For this local test,
set `$formatter_url` in the connection example to that URL, add `127.0.0.1` to
`allowed_hosts` and set
`$settings['tool_external']['allow_local_http'] = TRUE;`. The credential must
use the same exact URL. Deployed connections use HTTPS.

## Register the tool

Save this reviewed declaration as `normalize-title.json` in the project root.

```json
{
  "id": "normalize_title",
  "label": "Normalize a title",
  "description": "Remove leading and trailing whitespace from a title.",
  "contract_version": 1,
  "inputs": {"title": {"data_type": "string"}},
  "outputs": {"normalized": {"data_type": "string"}}
}
```

The declaration uses exactly the six top-level keys shown above. Its version is
a positive integer. Inputs and outputs can declare `string`, `integer`,
`float`, `boolean`, `map`, `list` or `any`. Use `properties` for a typed map
and `item_definition` for a list.

The [registration helper](examples/register-declaration.php) accepts a readable
local JSON file (up to 65,536 bytes), a connection name, a Tool API operation
and an optional Drupal permission, in that order. The available operations are
`explain`, `read`, `transform`, `trigger` and `write`. Register the example
under the `formatter` connection as a `transform`. This command assumes the
standard `web` document root.

```sh
examples="$PWD/web/modules/contrib/tool_external/examples"
vendor/bin/drush php:script "$examples/register-declaration.php" -- \
  "$PWD/normalize-title.json" formatter transform
```

The command creates the exportable config entity
`tool_external.tool.normalize_title`. Create it once, then use Drupal's
configuration workflow to deploy changes. PHP installers can call
`ExternalTool::fromDeclaration()` directly. Both paths translate the
declaration into native Tool API inputs and outputs. Consumers discover
`external:normalize_title` through Tool API. To switch providers, configure
another approved endpoint and its bound credential on the same logical
connection.

## Implement the endpoint

Drupal sends a JSON `POST` with an `Authorization: Bearer …` header. In this
example, the body carries `protocol: "drupal-external-tool/1"`,
`tool: {"id":"external:normalize_title","version":1}`, a unique
`invocation_id`, and `inputs`. The `context` includes the Drupal site UUID,
actor and executor IDs and roles, and caller label.

The endpoint checks the bearer token, protocol, tool ID and version, trims
`inputs.title`, and returns a successful HTTP status with uncompressed
`application/json`. For input `{"title":"  Winter guide  "}`, return the
following JSON with the invocation ID from the request.

```json
{
  "protocol": "drupal-external-tool/1",
  "invocation_id": "<copy the request invocation_id>",
  "outputs": {"normalized": "Winter guide"}
}
```

## Invoke from Drupal

Run the tool in the current Drupal account's context. In a Drupal service,
inject `tool.runner`.

```php
$result = \Drupal::service('tool.runner')
  ->run('external:normalize_title', ['title' => '  Winter guide  '])
  ->getResult();
if ($result->isSuccess()) {
  $normalized = $result->getContextValues()['normalized'];
}
```

Tool API enforces the configured permission and validates the declared values.
Drupal callers that select entity or field data for inputs must check access to
that data. The endpoint receives the input values and account context and
controls what happens in its own system.

Direct calls use the current Drupal account for both actor and executor.
Trusted site code that has already authorized a specific execution account can
inject `tool_external.delegated_runner` and call
`run($tool_id, $inputs, 'content.prepare.v1', $executor)`. It captures the
initiating account before Tool API checks and switches to the executor. A
caller label is a short code identifier. When upgrading from alpha4, replace
the old `tool_external_context` plugin configuration with this call. Legacy
configuration fails before HTTP. The v1 wire fields keep their existing shape.

Calls are synchronous. The default platform deadline cap is three seconds, and
the default request and response limits are 65,536 bytes each. The transport
rejects redirects, unsuccessful HTTP statuses and malformed results. Use the
`tool_external` log channel to correlate the invocation ID, outcome and
duration with the endpoint's logs. A timeout can occur after the endpoint has
acted, so a caller that retries a mutation needs an application-level
idempotency key.

## Run the tests

Use a development installation with `web/core`, the matching `drupal/core-dev`,
and Node.js on the test machine (verified with Node 24). The HTTP tests start
their own loopback fixture under PHPUnit. For an in-memory database, enable
PHP's PDO SQLite driver. You can also supply a dedicated test database in
`SIMPLETEST_DB`.

Install the matching core development tools with their dependencies. For the
tested version, use `composer require --dev -W 'drupal/core-dev:11.4.13'`. From
the Drupal Composer project root, run the packaged suite directly.

```sh
SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit \
  -c web/core/phpunit.xml.dist \
  --fail-on-skipped web/modules/contrib/tool_external/tests/src
```

Released under GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
