# omnireview/google

**Google reviews** for [glitchr/omnireview](https://github.com/glitchr-studio/omnireview): any
place's rating, number of ratings and up to five reviews through the Places API (New); for the
place's owner, every review and replying through the Business Profile API.

```php
use Omnireview\Google\GoogleGatewayFactory;
use Omnireview\Model\Place;

$gateway = (new GoogleGatewayFactory($httpClient))->create(['api_key' => getenv('GOOGLE_PLACES_KEY'), 'language' => 'fr']);

$place = new Place('ChIJLU7jZClu5kcR4PcOOO6p3I0');     // the place ID: the one thing Google lets you keep
$gateway->rating($place);                              // 4.6 from 128, with its Google Maps link
$gateway->reviews($place);                             // up to five, with their authors
$gateway->writeUrl($place);                            // search.google.com/local/writereview?placeid=...
```

Written from Google's documentation of the Places API (New) and the Business Profile API, read on
2026-10-08. **Not verified in real: no key** - but for the link to write a review, which answered.

[Documentation](docs/index.md): the options, the calls, Google's terms, what was verified.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
