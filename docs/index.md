# omnireview/google

Google reviews for [omnisense/omnireview](https://github.com/glitchr-omnisense/omnireview): any
place's rating, number of ratings and up to five reviews through the **Places API (New)**; for the
place's owner, every review and replying through the **Business Profile API** (v4); the link to
write a review. The gateway calls:

| Operation | API | Request |
|---|---|---|
| rating | Places API (New), Place Details | `GET https://places.googleapis.com/v1/places/{placeId}`, `X-Goog-FieldMask: id,displayName,rating,userRatingCount,googleMapsUri` |
| reviews | the same | `X-Goog-FieldMask: id,reviews,googleMapsUri` |
| rating, reviews (owner) | Business Profile API v4, `accounts.locations.reviews.list` | `GET https://mybusiness.googleapis.com/v4/{accounts/a/locations/l}/reviews` |
| reply (owner) | Business Profile API v4, `updateReply` | `PUT https://mybusiness.googleapis.com/v4/{accounts/a/locations/l}/reviews/{reviewId}/reply` |
| token (owner) | OAuth 2.0 | `POST https://oauth2.googleapis.com/token`, `grant_type=refresh_token` |
| write link | - | `https://search.google.com/local/writereview?placeid={placeId}`, nothing called |

The base addresses are the constants `GoogleGateway::PLACES`, `BUSINESS` and `TOKEN`. Google has no
sandbox for these APIs.

## Installation

```sh
composer require omnireview/google
```

Requires PHP 8.2, `omnisense/omnireview` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application with `Omnireview\Bridge\Symfony\OmnireviewBundle`, the factory is registered as
soon as the package is installed, with the application's `http_client`.

## Credentials

- **For anyone's place**: an API key of a Google Cloud project with the Places API (New) enabled,
  restricted to that API (and to the server's addresses). The place's ID (`ChIJ...`) is found once
  (Google's Place ID finder, or a Places search) and kept: it is the one thing Google lets an
  application keep.
- **For the owner's side**: access to the Business Profile API, which Google grants on request,
  per project; an OAuth 2.0 client (its client id and secret); a refresh token the owner granted
  once with the scope `https://www.googleapis.com/auth/business.manage`; and the location's name
  `accounts/<account>/locations/<location>`, given as `Place::$owner`.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | `null` | the key, or the owner's three | the Places API key, sent as `X-Goog-Api-Key` |
| `language` | string | `null` | no | `languageCode` of the Places calls when a call gives no `$language` |
| `client_id` | string | `null` | for the owner's side | the OAuth client |
| `client_secret` | string | `null` | for the owner's side | |
| `refresh_token` | string | `null` | for the owner's side | the owner's |

Neither the key nor all three of the owner's options is an `InvalidConfigException` when the
gateway is built. An empty string is "not set" (an environment variable left empty).

`Place` fields read: `id` (the place ID), `owner` (the Business Profile location, for the owner's
side), `name` (used in the Google Maps link of the owner's side).

## Capabilities

| Capability | Supported |
|---|---|
| `RatingInterface` | yes: the Places API; the Business Profile with the owner's access and `Place::$owner` |
| `ReviewsInterface` | yes: 5 at most from the Places API; up to 50 from the Business Profile |
| `ReplyInterface` | only with `client_id`, `client_secret`, `refresh_token` (and `Place::$owner`) |
| `InviteInterface` | no: Google has no invitation API (share `writeUrl()`) |
| `WriteUrlInterface` | yes |

`capabilities()->gives()`: `rating, reviews, write-url`, and `reply` with the owner's three.

## Behaviour

**Which side.** A `Place` with `owner`, on a gateway with the owner's three options, is read from
the Business Profile; any other `Place` from the Places API (which needs `api_key`: without it,
an `InvalidKeyException` `The Places API needs an API key (the api_key option).`).

**Rating, Places.** One Place Details call, `languageCode` = the call's language or the
`language` option (left out when neither is set). `value` is `rating`, `count`
`userRatingCount`, `url` `googleMapsUri`; a field left out is `0` or `null`.

**Rating, owner.** The reviews list with `pageSize=1`: `value` is `averageRating`, `count`
`totalReviewCount`, `url` `https://www.google.com/maps/search/?api=1&query=<name or id>&query_place_id=<id>`.

**Reviews, Places.** The place's `reviews` field, in Google's order (relevance), sliced to
`$limit` (Google gives 5 at most). Each review: `id` its resource name
(`places/<id>/reviews/<id>`), `rating`, `text` the `text.text` (Google's translation into the
asked language) or else `originalText.text`, `language` its language code, `translated` true when
`text` and `originalText` differ in language, `author`, `authorUrl`, `authorPhoto` from
`authorAttribution` (`displayName`, `uri`, `photoUri`), `publishedAt` from `publishTime`, `url`
the review's `googleMapsUri` (else the place's). No `reply`, no `title`.

**Reviews, owner.** `pageSize` = `$limit` between 1 and 50, `orderBy=updateTime desc`; `$language`
and `language` are not sent. Each review: `id` its `reviewId` (else `name`), `rating` from
`starRating` (`ONE` to `FIVE` as 1 to 5, anything else 0), `text` its `comment`, `author` and
`authorPhoto` from `reviewer`, `publishedAt` from `createTime`, `reply` from `reviewReply`
(`comment`, `updateTime`), `url` the place's Google Maps search link (a Business Profile review
has no address of its own). One page only: no `nextPageToken` is followed.

**Reply.** `PUT .../reviews/{reviewId}/reply` with `{"comment": text}`, `reviewId` the `Review::$id`
of an owner's review (URL-encoded). The `Reply` is Google's `comment` and `updateTime`. Without the
owner's three, or with a `Place` without `owner`, a `NotSupportedException` before any call.

**Token.** Asked on the first owner's call: `POST` with `grant_type=refresh_token`, the client id
and secret and the refresh token, as a form. It is kept in the gateway until 60 seconds before it
expires (`expires_in`, 3600 when Google does not say), then asked again.

**Write link.** `https://search.google.com/local/writereview?placeid=<id>`, built without a call.

**Every call** goes through `Answer::send()`, with a 10-second timeout.

**Terms** (`terms()`), from the Places API policies
([developers.google.com/maps/documentation/places/web-service/policies](https://developers.google.com/maps/documentation/places/web-service/policies)),
read on 2026-10-08:

| | |
|---|---|
| `cacheFor` | `0`: nothing kept but the place ID (no rating nor review cached, pre-fetched or stored) |
| `maxReviews` | `5` |
| `attribution` | `Google Maps` |
| `authors`, `link` | `true` |
| `noIndex` | `false` |

and in `rules`: the Google Maps logo, or the words "Google Maps" unchanged where space is short
(the Google logo when no Google map is shown); each review's author credited with their name,
photo and profile link (the photo at least where space is short); how the reviews are ordered and
filtered said (by relevance, as Google gives them); nothing kept but the place ID; the Business
Profile API for the place's owner, on their own account.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnireview\Google\GoogleGatewayFactory;
use Omnireview\Model\Place;
use Symfony\Component\HttpClient\HttpClient;

$google = (new GoogleGatewayFactory(HttpClient::create()))->create([
    'api_key' => getenv('GOOGLE_PLACES_KEY'),
    'language' => 'fr',
    'client_id' => getenv('GOOGLE_CLIENT_ID'),             // the owner's side, when granted
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET'),
    'refresh_token' => getenv('GOOGLE_REFRESH_TOKEN'),
]);

$anyone = new Place(getenv('GOOGLE_PLACE_ID'));
$rating = $google->rating($anyone);
printf("%.1f/5 from %d - %s\n", $rating->value, $rating->count, $rating->url);
foreach ($google->reviews($anyone, 5) as $review) {
    printf("%s - %s (%s)\n", $review->author, $review->text, $review->publishedAt?->format('Y-m-d'));
}
echo $google->writeUrl($anyone), "\n";

if ($google->capabilities()->reply) {
    $mine = new Place(getenv('GOOGLE_PLACE_ID'), 'Maison Érable', getenv('GOOGLE_LOCATION'));   // accounts/<a>/locations/<l>
    foreach ($google->reviews($mine, 50) as $review) {
        if (null === $review->reply) {
            $google->reply($mine, $review, 'Merci pour votre avis !');
        }
    }
}
```

Not run live (no key). In Symfony:

```yaml
omnireview:
    gateways:
        google:
            factory: google
            options:
                api_key: '%env(GOOGLE_PLACES_KEY)%'
                language: fr
                client_id: '%env(default::GOOGLE_CLIENT_ID)%'
                client_secret: '%env(default::GOOGLE_CLIENT_SECRET)%'
                refresh_token: '%env(default::GOOGLE_REFRESH_TOKEN)%'
```

```twig
{{ omnireview_widget('google', place_id) }}
```

The core's harness asks Google for real with the keys in `docker/.env`:
`docker compose run --rm omnireview bare --live`.

## Errors

| Case | Exception | `providerCode` |
|---|---|---|
| HTTP 401 or 403 | `InvalidKeyException`, `HTTP <status>, <status>: <message>` | Google's `error.status` (`PERMISSION_DENIED`...) |
| another 4xx (an unknown place: 404) | `ProviderException` | Google's `error.status` (`NOT_FOUND`) |
| the refresh token refused, or no `access_token` | `InvalidKeyException`, `The owner's refresh token was refused: <error_description>` | Google's `error` (`invalid_grant`) |
| a Places call without `api_key` | `InvalidKeyException` | - |
| no answer, a 5xx, a 429 | `UnreachableException` | the HTTP status |
| a 2xx body that is not JSON | `ProviderException` | - |
| `reply()` without the owner's access | `NotSupportedException` | - |
| neither `api_key` nor the owner's three | `InvalidConfigException`, when the gateway is built | - |

## Verified, and not

Tested offline (`Tests/GoogleGatewayTest.php`, on `MockHttpClient`): the Places rating and
reviews with their authors, Google's translation flagged, the `X-Goog-Api-Key` and field-mask
headers, `languageCode`; the owner's reviews and reply - the refresh-token grant's form, the
reviews' URL with `pageSize` and `orderBy`, the reply's `PUT` and body, `updateTime`; the terms,
the write link, `NotSupportedException` without the owner, `NOT_FOUND` as a `ProviderException`, a
403 as an `InvalidKeyException`, no option as an `InvalidConfigException`. The answers in
`Tests/Fixtures` are written from Google's reference (the `Place` and `Review` resources of Places
(New); `accounts.locations.reviews` of Business Profile v4), not captured.

Not verified: **no call was made to Google** - no Places key, no Business Profile access. The
link to write a review answered 200 on 2026-10-08.

## Responsibility

An independent, unofficial client, not affiliated with Google LLC. Whoever runs it complies with
Google's terms ([Google Maps Platform Terms of Service](https://cloud.google.com/maps-platform/terms),
[Business Profile APIs terms](https://developers.google.com/my-business/content/terms)): see the
README's Responsibility section.
