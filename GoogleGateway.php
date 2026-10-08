<?php

namespace Omnireview\Google;

use Omnireview\Exception\InvalidKeyException;
use Omnireview\Exception\NotSupportedException;
use Omnireview\Exception\ProviderException;
use Omnireview\Http\Answer;
use Omnireview\Model\Capabilities;
use Omnireview\Model\Place;
use Omnireview\Model\Rating;
use Omnireview\Model\Reply;
use Omnireview\Model\Review;
use Omnireview\Model\Terms;
use Omnireview\RatingInterface;
use Omnireview\ReplyInterface;
use Omnireview\ReviewsInterface;
use Omnireview\WriteUrlInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google: the Places API (New) for anyone's place - its rating, its
 * number of ratings, up to five reviews -, and the Business Profile API for
 * the owner's - every review, and replying.
 *
 *   - Places: GET https://places.googleapis.com/v1/places/{placeId}, X-Goog-Api-Key, X-Goog-FieldMask;
 *   - Business Profile: OAuth 2.0 (the owner's refresh token, scope business.manage),
 *     GET https://mybusiness.googleapis.com/v4/{accounts/a/locations/l}/reviews,
 *     PUT .../reviews/{reviewId}/reply.
 */
final class GoogleGateway implements RatingInterface, ReviewsInterface, ReplyInterface, WriteUrlInterface
{
    public const PLACES = 'https://places.googleapis.com/v1/places/';
    public const BUSINESS = 'https://mybusiness.googleapis.com/v4/';
    public const TOKEN = 'https://oauth2.googleapis.com/token';
    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    private ?string $token = null;
    private int $expires = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly ?string $clientId = null,
        #[\SensitiveParameter] private readonly ?string $clientSecret = null,
        #[\SensitiveParameter] private readonly ?string $refreshToken = null,
        private readonly ?string $language = null,
    ) {
    }

    public function getName(): string
    {
        return 'google';
    }

    public function getTitle(): string
    {
        return 'Google';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(rating: true, reviews: true, reply: $this->owner(), invite: false, writeUrl: true);
    }

    public function terms(): Terms
    {
        return new Terms(
            cacheFor: 0,
            maxReviews: 5,
            attribution: 'Google Maps',
            authors: true,
            link: true,
            noIndex: false,
            rules: [
                'Show the Google Maps logo, or the words "Google Maps" unchanged where space is short; the Google logo when no Google map is shown.',
                'Credit each review\'s author: their name, photo and profile link (the photo at least, where space is short).',
                'Say how the reviews are ordered and filtered: by relevance, as Google gives them.',
                'Keep nothing but the place ID: no other content cached, pre-fetched or stored.',
                'The Business Profile API is for the place\'s owner, on their own account.',
            ],
            source: 'https://developers.google.com/maps/documentation/places/web-service/policies',
        );
    }

    public function rating(Place $place): Rating
    {
        if (null !== $place->owner && $this->owner()) {
            $data = $this->business('GET', $place->owner.'/reviews', ['query' => ['pageSize' => 1]]);

            return new Rating('google', (float) ($data['averageRating'] ?? 0), (int) ($data['totalReviewCount'] ?? 0), url: self::maps($place));
        }
        $data = $this->place($place, 'id,displayName,rating,userRatingCount,googleMapsUri');

        return new Rating('google', (float) ($data['rating'] ?? 0), (int) ($data['userRatingCount'] ?? 0), url: $data['googleMapsUri'] ?? null);
    }

    public function reviews(Place $place, int $limit = 5, ?string $language = null): array
    {
        if (null !== $place->owner && $this->owner()) {
            $data = $this->business('GET', $place->owner.'/reviews', ['query' => ['pageSize' => max(1, min(50, $limit)), 'orderBy' => 'updateTime desc']]);

            return array_slice(array_map(fn (array $r) => new Review(
                'google',
                (string) ($r['reviewId'] ?? $r['name'] ?? ''),
                (float) (self::STARS[$r['starRating'] ?? ''] ?? 0),
                isset($r['comment']) ? (string) $r['comment'] : null,
                author: $r['reviewer']['displayName'] ?? null,
                authorPhoto: $r['reviewer']['profilePhotoUrl'] ?? null,
                publishedAt: self::date($r['createTime'] ?? null),
                // The Business Profile API gives no review its own address: the place on Google Maps.
                url: self::maps($place),
                reply: isset($r['reviewReply']['comment']) ? new Reply((string) $r['reviewReply']['comment'], self::date($r['reviewReply']['updateTime'] ?? null)) : null,
            ), (array) ($data['reviews'] ?? [])), 0, $limit);
        }

        $data = $this->place($place, 'id,reviews,googleMapsUri', $language);

        return array_slice(array_map(static fn (array $r) => new Review(
            'google',
            (string) ($r['name'] ?? ''),
            (float) ($r['rating'] ?? 0),
            $r['text']['text'] ?? $r['originalText']['text'] ?? null,
            language: $r['text']['languageCode'] ?? $r['originalText']['languageCode'] ?? null,
            author: $r['authorAttribution']['displayName'] ?? null,
            authorUrl: $r['authorAttribution']['uri'] ?? null,
            authorPhoto: $r['authorAttribution']['photoUri'] ?? null,
            publishedAt: self::date($r['publishTime'] ?? null),
            url: $r['googleMapsUri'] ?? $data['googleMapsUri'] ?? null,
            translated: isset($r['text']['languageCode'], $r['originalText']['languageCode']) && $r['text']['languageCode'] !== $r['originalText']['languageCode'],
        ), (array) ($data['reviews'] ?? [])), 0, $limit);
    }

    public function reply(Place $place, Review $review, string $text): Reply
    {
        if (!$this->owner() || null === $place->owner) {
            throw NotSupportedException::operation('google', 'reply', 'replying is the owner\'s, through the Business Profile API: client_id, client_secret, refresh_token, and the Place\'s owner ("accounts/<a>/locations/<l>")');
        }
        $data = $this->business('PUT', $place->owner.'/reviews/'.rawurlencode($review->id).'/reply', ['json' => ['comment' => $text]]);

        return new Reply((string) ($data['comment'] ?? $text), self::date($data['updateTime'] ?? null));
    }

    public function writeUrl(Place $place): string
    {
        return 'https://search.google.com/local/writereview?placeid='.rawurlencode($place->id);
    }

    private static function maps(Place $place): string
    {
        return 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $place->name ?? $place->id, 'query_place_id' => $place->id]);
    }

    private function owner(): bool
    {
        return null !== $this->clientId && null !== $this->clientSecret && null !== $this->refreshToken;
    }

    /** @return array<string, mixed> */
    private function place(Place $place, string $fields, ?string $language = null): array
    {
        if (null === $this->apiKey) {
            throw new InvalidKeyException('google', 'The Places API needs an API key (the api_key option).');
        }
        $answer = Answer::send($this->http, 'google', 'GET', self::PLACES.rawurlencode($place->id), [
            'headers' => ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => $fields],
            'query' => array_filter(['languageCode' => $language ?? $this->language]),
        ]);

        return $this->read($answer);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function business(string $method, string $path, array $options): array
    {
        $answer = Answer::send($this->http, 'google', $method, self::BUSINESS.$path, ['headers' => ['Authorization' => 'Bearer '.$this->token()]] + $options);

        return $this->read($answer);
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->expires) {
            return $this->token;
        }
        $answer = Answer::send($this->http, 'google', 'POST', self::TOKEN, ['body' => ['grant_type' => 'refresh_token', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'refresh_token' => $this->refreshToken]]);
        $data = json_decode($answer->body, true);
        if ($answer->status >= 400 || !isset($data['access_token'])) {
            throw new InvalidKeyException('google', 'The owner\'s refresh token was refused: '.(\is_array($data) ? ($data['error_description'] ?? $data['error'] ?? '') : ''), \is_array($data) ? ($data['error'] ?? null) : null);
        }
        $this->token = (string) $data['access_token'];
        $this->expires = time() + max(0, (int) ($data['expires_in'] ?? 3600) - 60);

        return $this->token;
    }

    /** @return array<string, mixed> */
    private function read(Answer $answer): array
    {
        if ($answer->status < 400) {
            return '' === trim($answer->body) ? [] : $answer->json();
        }
        $error = (array) (json_decode($answer->body, true)['error'] ?? []);
        $message = \sprintf('HTTP %d, %s: %s', $answer->status, $error['status'] ?? '', $error['message'] ?? mb_substr(trim($answer->body), 0, 200));
        throw \in_array($answer->status, [401, 403], true) ? new InvalidKeyException('google', $message, $error['status'] ?? null) : new ProviderException('google', $message, $error['status'] ?? null);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return \is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
