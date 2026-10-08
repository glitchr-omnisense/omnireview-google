<?php

namespace Omnireview\Google\Tests;

use Omnireview\Exception\InvalidConfigException;
use Omnireview\Exception\InvalidKeyException;
use Omnireview\Exception\NotSupportedException;
use Omnireview\Exception\ProviderException;
use Omnireview\Google\GoogleGateway;
use Omnireview\Google\GoogleGatewayFactory;
use Omnireview\Model\Place;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from Google's documentation (Place Details (New), the
 * Business Profile API's reviews): no key was used. The write-review link
 * answered 200 on 2026-10-08.
 */
final class GoogleGatewayTest extends TestCase
{
    private const PLACE = 'ChIJLU7jZClu5kcR4PcOOO6p3I0';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    /** @param list<string|MockResponse> $answers */
    private function gateway(array $answers, array $options = ['api_key' => 'places-key', 'language' => 'fr']): GoogleGateway
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$url);
            if ($answer instanceof MockResponse) {
                return $answer;
            }
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file.'.json'), ['http_code' => (int) $status]);
        });
        $gateway = (new GoogleGatewayFactory($http))->create($options);
        self::assertInstanceOf(GoogleGateway::class, $gateway);

        return $gateway;
    }

    public function testThePlacesRatingAndReviewsWithTheirAuthors(): void
    {
        $gateway = $this->gateway(['place', 'place']);
        $rating = $gateway->rating(new Place(self::PLACE));

        self::assertSame([4.6, 128, 'https://maps.google.com/?cid=10223305813012340704'], [$rating->value, $rating->count, $rating->url]);
        [$method, $url, $options] = $this->calls[0];
        self::assertSame(['GET', 'https://places.googleapis.com/v1/places/'.self::PLACE.'?languageCode=fr'], [$method, $url]);
        $headers = implode("\n", $options['headers']);
        self::assertStringContainsString('X-Goog-Api-Key: places-key', $headers);
        self::assertStringContainsString('X-Goog-FieldMask: id,displayName,rating,userRatingCount,googleMapsUri', $headers);

        $reviews = $gateway->reviews(new Place(self::PLACE));
        self::assertCount(2, $reviews);
        self::assertSame(['Camille R.', 'https://www.google.com/maps/contrib/1011', 'https://lh3.googleusercontent.com/a/camille', '2026-09-23', 5.0, false], [$reviews[0]->author, $reviews[0]->authorUrl, $reviews[0]->authorPhoto, $reviews[0]->publishedAt?->format('Y-m-d'), $reviews[0]->rating, $reviews[0]->translated]);
        self::assertTrue($reviews[1]->translated, 'Google\'s translation of an English review');
        self::assertStringContainsString('X-Goog-FieldMask: id,reviews,googleMapsUri', implode("\n", $this->calls[1][2]['headers']));
    }

    public function testTheOwnersReviewsAndReply(): void
    {
        $gateway = $this->gateway(['token', 'business-reviews', 'reply'], ['client_id' => 'cid', 'client_secret' => 'cs', 'refresh_token' => 'rt']);
        $place = new Place(self::PLACE, 'Maison Érable', 'accounts/101/locations/202');

        $reviews = $gateway->reviews($place, 10);
        self::assertSame(['AbFvOqm1', 5.0, 'Merci Camille !', 3.0, null], [$reviews[0]->id, $reviews[0]->rating, $reviews[0]->reply?->text, $reviews[1]->rating, $reviews[1]->text]);
        self::assertSame('https://www.google.com/maps/search/?api=1&query=Maison+%C3%89rable&query_place_id='.self::PLACE, $reviews[0]->url, 'no address of its own: the place');
        parse_str((string) $this->calls[0][2]['body'], $grant);
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => 'cid', 'client_secret' => 'cs', 'refresh_token' => 'rt'], $grant);
        self::assertSame('https://mybusiness.googleapis.com/v4/accounts/101/locations/202/reviews?pageSize=10&orderBy=updateTime%20desc', $this->calls[1][1]);

        $reply = $gateway->reply($place, $reviews[1], 'Merci pour votre retour, à bientôt !');
        self::assertSame(['PUT', 'https://mybusiness.googleapis.com/v4/accounts/101/locations/202/reviews/AbFvOqm2/reply'], [$this->calls[2][0], $this->calls[2][1]]);
        self::assertSame(['comment' => 'Merci pour votre retour, à bientôt !'], json_decode((string) $this->calls[2][2]['body'], true));
        self::assertSame('2026-10-08T09:00:00+00:00', $reply->at?->format(\DATE_ATOM));
        self::assertTrue($gateway->capabilities()->reply);
    }

    public function testTermsLinksAndErrors(): void
    {
        $gateway = $this->gateway([]);
        self::assertSame('https://search.google.com/local/writereview?placeid='.self::PLACE, $gateway->writeUrl(new Place(self::PLACE)));
        self::assertSame([0, 5, 'Google Maps', false], [$gateway->terms()->cacheFor, $gateway->terms()->maxReviews, $gateway->terms()->attribution, $gateway->terms()->allowsCaching(60)]);
        self::assertSame(['rating', 'reviews', 'write-url'], $gateway->capabilities()->gives());

        try {
            $gateway->reply(new Place(self::PLACE), new \Omnireview\Model\Review('google', 'r', 5), 'Merci');
            self::fail();
        } catch (NotSupportedException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->gateway(['not-found:404'])->rating(new Place('nope'));
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame('NOT_FOUND', $e->providerCode);
        }
        try {
            $this->gateway(['key-refused:403'])->rating(new Place(self::PLACE));
            self::fail();
        } catch (InvalidKeyException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidConfigException::class);
        (new GoogleGatewayFactory(new MockHttpClient()))->create([]);
    }
}
