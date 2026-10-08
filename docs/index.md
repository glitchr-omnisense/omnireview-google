---
title: omnireview/google
order: 1
---

# omnireview/google

## Installation

```sh
composer require omnireview/google
```

PHP 8.2 or later, `glitchr/omnireview` and `symfony/http-client` (give the factory your
application's client, a `MockHttpClient` in a test).

- For anyone's place: an API key of a Google Cloud project with the **Places API (New)** enabled,
  restricted to that API (and to the server's addresses).
- For the owner's side: the **Business Profile API** - its access is granted by Google on request,
  per project -, an OAuth 2.0 client, and a refresh token the owner granted once with the scope
  `https://www.googleapis.com/auth/business.manage`.

## Options

| Option | Default | |
|---|---|---|
| `api_key` | | the Places API key |
| `language` | | the reviews' language (`languageCode`): Google translates the others, and says so (`Review::$translated`) |
| `client_id`, `client_secret`, `refresh_token` | | the owner's access (Business Profile) |

The `api_key`, the owner's three, or both.

## Calls

| | Route | |
|---|---|---|
| `rating()` | `GET places.googleapis.com/v1/places/{placeId}`, `X-Goog-Api-Key`, `X-Goog-FieldMask: id,displayName,rating,userRatingCount,googleMapsUri` | the rating, the count, `googleMapsUri` |
| `reviews()` | the same, `X-Goog-FieldMask: id,reviews,googleMapsUri` | up to 5, as Google orders them (relevance); each with its author's name, photo, profile, `publishTime`, `googleMapsUri` |
| `rating()`, `reviews()` for a `Place` with `owner` (`accounts/{a}/locations/{l}`) and the owner's access | `GET mybusiness.googleapis.com/v4/{owner}/reviews` (`pageSize` up to 50, `orderBy=updateTime desc`) | `averageRating`, `totalReviewCount`, the reviews with their replies; a review has no address of its own, its link is the place's on Google Maps |
| `reply()` | `PUT mybusiness.googleapis.com/v4/{owner}/reviews/{reviewId}/reply`, `{"comment": ...}` | the owner's only: `NotSupportedException` otherwise |
| `writeUrl()` | `https://search.google.com/local/writereview?placeid={placeId}` | nothing called |

The owner's token comes from `oauth2.googleapis.com/token` (`grant_type=refresh_token`), kept until a
minute before it lapses. Google has no API to invite a customer: share `writeUrl()`.

## Google's terms, as declared (`terms()`)

From the Places API policies
([developers.google.com/maps/documentation/places/web-service/policies](https://developers.google.com/maps/documentation/places/web-service/policies)),
read on 2026-10-08:

- **Nothing kept but the place ID** (`cacheFor: 0`): no rating nor review cached, pre-fetched or
  stored - the Twig functions fetch them each time. A place ID may be kept, and refreshed after a
  year.
- **Attribution**: the Google Maps logo, or the words "Google Maps" unchanged where space is short
  (the Google logo when no Google map is shown).
- **Authors**: each review with its author's name, photo and profile link (the photo at least where
  space is short).
- Say how the reviews are ordered: by relevance, as Google gives them.
- The Business Profile API is the owner's, on their own account.

## Verified, and not

| | |
|---|---|
| The link to write a review (`search.google.com/local/writereview?placeid=`) | **verified in real**: it answered 200 on 2026-10-08 |
| The Places API (New), the Business Profile API, the token | **not verified in real: no key, no Business Profile access.** Every answer in `Tests/Fixtures` is written from Google's reference (the `Place` and `Review` resources of Places (New); `accounts.locations.reviews` of Business Profile v4) |
| The field mask, the headers, the refresh-token grant, the reply's body | by the tests, against that reference |
