# Give a distributor a custom Media RSS feed

“I want to manage episodes in Drupal and give a distributor a feed in the format they require.”

Drupal chooses the published episodes the reader can view and supplies each episode's ID, title and media URL. The [Worker](worker.mjs) turns those fields into RSS items with stable GUIDs and `media:content` elements. The [tool declaration](tool.json) describes the input list and the two outputs, `xml` and `item_count`.

## Try one episode

From the root of a checkout of this repository, set a local test token and start the Worker with Node.js 22 or later.

```sh
FEED_TOKEN=local-demo-only node examples/partner-mrss/serve.mjs
```

In a second terminal, send the same envelope Drupal uses. This prints one episode's XML. The ampersand in its title appears as `&amp;` in the feed.

```sh
curl --fail --silent --show-error http://127.0.0.1:8787/invoke \
  -H 'Authorization: Bearer local-demo-only' -H 'Content-Type: application/json' \
  --data '{
    "protocol": "drupal-external-tool/1",
    "invocation_id": "feed-preview",
    "tool": {"id": "external:partner_mrss", "version": 1},
    "inputs": {
      "channel": "Coastal Stories",
      "channel_url": "https://video.example.test/",
      "episodes": [{
        "id": "episode-42",
        "title": "Tides & Trails",
        "media_url": "https://media.example.test/42.mp4"
      }]
    }
  }' | jq -r '.outputs.xml'
```

Run the [Worker tests](worker.test.mjs) from the module root with `node --test examples/partner-mrss/worker.test.mjs`. They cover a multi-episode feed, an empty catalog, authentication and malformed inputs.

## Use it from Drupal

Use `examples/partner-mrss/worker.mjs` as your bundler's entry point so it includes [tool.json](tool.json) and the [shared bearer check](../bearer-token.mjs). Serve the bundle at an HTTPS endpoint ending in `/invoke` and set a dedicated `FEED_TOKEN` secret. Follow the [connection setup](../../README.md#configure-one-connection) using the connection name `partner_feed`, the Worker's endpoint and secret, and `external:partner_mrss` in `allowed_tools`.

Copy [tool.json](tool.json) from this checkout to the site's Composer root as `partner-mrss.json`. From that directory, register it with the helper supplied by the installed Drupal module.

```sh
examples="$PWD/web/modules/contrib/tool_external/examples"
vendor/bin/drush php:script "$examples/register-declaration.php" -- \
  "$PWD/partner-mrss.json" partner_feed transform
```

To preview through Drupal, set `FEED_UID` to a Drupal user ID with the registered tool permission. This call returns a one-item feed in `outputs.xml`.

```sh
vendor/bin/drush tool:run external:partner_mrss --uid="$FEED_UID" --json \
  --input='{"channel":"Coastal Stories","channel_url":"https://video.example.test/","episodes":[{"id":"episode-42","title":"Tides & Trails","media_url":"https://media.example.test/42.mp4"}]}'
```

In the site's feed controller, select published episodes and check entity and field view access for the feed reader. Invoke `external:partner_mrss` through `tool.runner` with those rows. On success, serve the `xml` output as `application/rss+xml`. An empty selection produces a valid feed with zero items. If the call fails, the controller can return HTTP 503 so a distributor knows to retry.
