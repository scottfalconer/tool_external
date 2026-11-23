# Require a passkey review before publishing

As an editor, I want a reviewer to approve the exact article I’m about to
publish. If I change the headline after review, the reviewer should see the
new version.

Drupal sends a frozen copy of the candidate fields, their SHA-256 digest and
the candidate’s generation. The PHP endpoint returns `pending` with a review
link. The reviewer opens the link, reads the fields and approves with an
enrolled passkey. On retry, the endpoint returns `approved` for that candidate.
A change to its fields or generation starts a fresh review.

The [tool declaration](tool.json) defines the input and five output fields.
The [PHP handler](src/Application.php) turns a call into an approval request
or a decision. The [protected action](src/AuthorizePublication.php) records
authorization for a particular snapshot. The PHP service uses the passkey
verifier and signed receipts from [Verified Human Approval](https://github.com/scottfalconer/verified-human-approval/tree/06ce28c92682ed3713e6b0cdcdc40b80122e1418).

## Try the provider locally

Use PHP 8.3+, Composer, PDO SQLite, sodium, OpenSSL and mbstring. Start in a
checkout of this repository **outside your Drupal document root**. Install
the pinned approval library in a temporary directory. The configuration,
generated credentials and approval database will also stay in that directory.

```sh
approval_demo=$(mktemp -d)
git clone -q https://github.com/scottfalconer/verified-human-approval.git "$approval_demo/library"
git -C "$approval_demo/library" checkout -q 06ce28c92682ed3713e6b0cdcdc40b80122e1418
composer install --working-dir="$approval_demo/library"
export VHA_AUTOLOAD="$approval_demo/library/vendor/autoload.php"
cd examples/verified-human-approval
php tests/provider-test.php
```

The test uses a software authenticator to sign a real WebAuthn challenge. It
checks the signed receipt, a changed title, an expired approval and a revoked
credential. To try the same flow with your own passkey in a browser, use the
same shell and start the example server.

```sh
export VHA_PROVIDER_CONFIG="$approval_demo/private/provider.json"
php bin/setup.php "$VHA_PROVIDER_CONFIG" http://localhost:8788 local-demo-site
php -S localhost:8788 -t public public/router.php >"$approval_demo/server.log" 2>&1 &
provider_pid=$!
```

1. Run `php bin/request-review.php`. This [sample client](bin/request-review.php)
   sends a synthetic article titled “Winter service hours.” Its response
   includes a `pending` decision, an approval ID, a digest and a `review_url`.
2. Open [the local enrollment page](http://localhost:8788/enroll) and sign
   in as `editor` with the browser password printed by setup. Enter the
   one-use enrollment code and register your passkey.
3. Open the returned review URL, read the title and approve with your passkey.
   Run `php bin/request-review.php` again. The decision is now `approved`
   for the same digest and approval ID.
4. Run `php bin/request-review.php "Revised winter hours"`. The changed
   snapshot gets its own pending review. Stop the server with
   `kill "$provider_pid"` when you finish.

## Wire it to Drupal

Configure an endpoint-bound connection named `verified_human_approval` for
`external:verified_human_publish`. Use the provider’s `/invoke` URL, its
private `site_token` and your Drupal site's UUID when configuring the provider.
Keep the provider and its dependencies on their own host or local process.
Register [tool.json](tool.json) with the module’s [registration helper](../register-declaration.php).
From the Drupal project root, use the absolute path to the declaration in
your example checkout and the helper from the installed module. Register it
as a `trigger` because the first call creates a durable review request.

```sh
declaration=/absolute/path/to/tool_external/examples/verified-human-approval/tool.json
vendor/bin/drush php:script web/modules/contrib/tool_external/examples/register-declaration.php -- \
  "$declaration" verified_human_approval trigger
```

The site’s publication handler supplies the candidate and owns the final save.
Check the editor’s Drupal permissions before sending fields. Allow a protected
save only when the call succeeds, the decision is `approved` and the digest
matches the current candidate. Consume the approval ID once in the same
transaction as the save and check the generation to reject stale retries. A
pending decision can show the review link. A denial or provider outage keeps
the candidate unpublished. The base module supplies the tool call. Connecting
it to every protected save path requires a site integration.
