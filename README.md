# External Tools

External Tools registers an HTTPS function as a native Drupal Tool API tool. The function author describes its inputs and outputs. The site operator chooses the endpoint, credential and Drupal permission. Drupal discovers the tool from local configuration and contacts the function only when a caller invokes it.

## Install

This alpha requires Drupal `^11.2`, PHP 8.3 or later with cURL, and Drupal Tool API `1.0.0-beta12`. The commands below assume a Composer-managed Drupal project with Drush. Place the release ZIP in a local directory and run these commands from the project root. Composer's artifact repository requires the PHP ZIP extension on the machine running Composer.

```sh
composer config repositories.external-tools artifact /absolute/path/to/package-directory
composer require 'drupal/tool_external:0.1.0-alpha1' 'drupal/tool:1.0.0-beta12'
vendor/bin/drush en tool_external -y
```

The module starts with outbound execution disabled and an empty connection list.

## Configure one connection

This example connects a title formatter. Replace the example URL with the HTTPS invocation endpoint you operate or trust. Provision the same dedicated bearer token at the endpoint and in the Drupal environment variable `FORMATTER_TOKEN`. Add the following to the site's `settings.php`, then run `vendor/bin/drush cache:rebuild`.

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
];
```

The connection uses a credential reference. Its token stays in runtime settings. Bind each credential to its exact endpoint. On Drupal's Permissions page (`/admin/people/permissions`), grant the invoking role the permission named at registration. The default is `execute external tools`.

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

The declaration uses exactly the six top-level keys shown above. Its version is a positive integer. Inputs and outputs can declare `string`, `integer`, `float`, `boolean`, `map`, `list` or `any`. Use `properties` for a typed map and `item_definition` for a list.

The [registration helper](examples/register-declaration.php) accepts a readable local JSON file (up to 65,536 bytes), a connection name, a Tool API operation and an optional Drupal permission, in that order. The available operations are `explain`, `read`, `transform`, `trigger` and `write`. Register the example under the `formatter` connection as a `transform`. This command assumes the standard `web` document root.

```sh
vendor/bin/drush php:script "$PWD/web/modules/contrib/tool_external/examples/register-declaration.php" -- "$PWD/normalize-title.json" formatter transform
```

The command creates the exportable config entity `tool_external.tool.normalize_title`. Create it once, then use Drupal's configuration workflow to deploy changes. Consumers discover `external:normalize_title` through Tool API. To switch providers, configure another approved endpoint and its bound credential on the same logical connection.

## Implement the endpoint

Drupal sends a JSON `POST` with an `Authorization: Bearer …` header. In this example, the body carries `protocol: "drupal-external-tool/1"`, `tool: {"id":"external:normalize_title","version":1}`, a unique `invocation_id`, and `inputs`. The `context` includes the Drupal site UUID, actor and executor IDs and roles, and caller label.

The endpoint checks the bearer token, protocol, tool ID and version, trims `inputs.title`, and returns a successful HTTP status with uncompressed `application/json`. For input `{"title":"  Winter guide  "}`, return the following JSON with the invocation ID from the request.

```json
{
  "protocol": "drupal-external-tool/1",
  "invocation_id": "<copy the request invocation_id>",
  "outputs": {"normalized": "Winter guide"}
}
```

## Invoke from Drupal

Run the tool in the current Drupal account's context. In a Drupal service, inject `tool.runner`.

```php
$result = \Drupal::service('tool.runner')
  ->run('external:normalize_title', ['title' => '  Winter guide  '])
  ->getResult();
if ($result->isSuccess()) {
  $normalized = $result->getContextValues()['normalized'];
}
```

Tool API enforces the configured permission and validates the declared values. Drupal callers that select entity or field data for inputs must check access to that data. The endpoint receives the input values and account context and controls what happens in its own system.

Calls are synchronous. The default platform deadline cap is three seconds, and the default request and response limits are 65,536 bytes each. The transport rejects redirects, unsuccessful HTTP statuses and malformed results. Use the `tool_external` log channel to correlate the invocation ID, outcome and duration with the endpoint's logs. A timeout can occur after the endpoint has acted, so a caller that retries a mutation needs an application-level idempotency key.

## Run the tests

Use a development installation with `web/core` and PHPUnit in `vendor/bin/phpunit` from the matching `drupal/core-dev` package. The runner uses Python 3 and starts a Node.js HTTP fixture on loopback. Node.js 24 supports the fixture's JSON import attributes. The default in-memory SQLite test database requires PHP's PDO SQLite driver. To use a dedicated test database instead, set `SIMPLETEST_DB` to its connection URL.

From the Drupal Composer project root, pass the project directory to the [test runner](tests/run-tests.py).

```sh
python3 web/modules/contrib/tool_external/tests/run-tests.py "$PWD"
```

Released under GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
