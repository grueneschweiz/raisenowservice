<?php
declare(strict_types=1);

namespace RaiseNowConnector\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Exception\OAuthTokenException;
use RaiseNowConnector\Exception\PersistanceException;
use RaiseNowConnector\Exception\WeblingServiceAPIException;
use RaiseNowConnector\Model\OAuthToken;
use RaiseNowConnector\Model\RaisenowPaymentData;
use RaiseNowConnector\Util\ClientFactory;
use RaiseNowConnector\Util\Config;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;

class WeblingServiceAPI
{
    private const REQUEST_TIMEOUT = 90;  // seconds

    public const MATCH_NONE = 'no_match';
    public const MATCH_EXACT = 'match';
    public const MATCH_AMBIGUOUS = 'ambiguous';
    public const MATCH_MULTIPLE = 'multiple';

    private ?OAuthToken $token;
    private Client $client;

    /**
     * @throws JsonException
     * @throws GuzzleException
     * @throws ConfigException
     */
    public function __construct()
    {
        $this->obtainToken();
        $this->initClient();
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     * @throws JsonException
     */
    private function obtainToken(): void
    {
        $this->loadTokenIfExists();

        if ($this->hasValidToken()) {
            return;
        }

        $this->requestNewToken();
    }

    private function loadTokenIfExists(): void
    {
        try {
            $this->token = OAuthToken::loadFromFile(Config::name());
        } catch (OAuthTokenException|PersistanceException $e) {
            Logger::debug(new LogMessage($e->getMessage()));
        }
    }

    /**
     * @throws ConfigException
     */
    private function hasValidToken(): bool
    {
        if (!isset($this->token) || $this->token->dueForRenewal()) {
            return false;
        }

        try {
            $this->initClient();
            $response = $this->client->get('/api/v1/auth');
        } catch (GuzzleException $e) {
            Logger::warning(new LogMessage("Could not test oAuth token of Webling service: {$e->getMessage()}."));
        }

        return isset($response) && 200 === $response->getStatusCode();
    }

    /**
     * @throws ConfigException
     */
    private function initClient(): void
    {
        $apiUrl = rtrim(Config::get('weblingServiceApiUrl'), '/') . '/';

        $options = [
            'base_uri' => $apiUrl,
            'timeout' => self::REQUEST_TIMEOUT,
            'http_errors' => true,
        ];

        if (isset($this->token)) {
            $options['headers'] = ['Authorization' => $this->token->getToken()];
        }

        $this->client = ClientFactory::create($options);
    }

    /**
     * @throws JsonException
     * @throws GuzzleException
     * @throws ConfigException
     */
    private function requestNewToken(): void
    {
        $this->token = null;

        $data = array(
            'grant_type' => 'client_credentials',
            'client_id' => Config::get('weblingServiceClientId'),
            'client_secret' => Config::get('weblingServiceClientSecret'),
            'scope' => ''
        );

        try {
            $this->initClient();
            $response = $this->client->post('/oauth/token', ['json' => $data]);
        } catch (GuzzleException $e) {
            Logger::error(new LogMessage("Could not obtain oAuth token from Webling service."));
            throw $e;
        }

        try {
            $tokenData = json_decode((string)$response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Logger::error(new LogMessage("Invalid token JSON from Webling service."));
            throw $e;
        }

        $this->token = new OAuthToken(
            $tokenData->access_token,
            $tokenData->token_type,
            '',
            '',
            null,
            $tokenData->expires_in
        );

        $this->token->persist(Config::name());
    }

    /**
     * @throws GuzzleException
     */
    public function matchMember(RaisenowPaymentData $paymentData)
    {
        $data = [
            'email1' => ['value' => $paymentData->email],
            'firstName' => ['value' => $paymentData->firstName],
            'lastName' => ['value' => $paymentData->lastName],
            'zip' => ['value' => $paymentData->zip],
        ];

        $response = $this->client->post('/api/v1/member/match', ['json' => $data]);

        try {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            /** @noinspection JsonEncodingApiUsageInspection */
            Logger::debug(new LogMessage("Data sent to Webling service:\n" . json_encode($data, JSON_PRETTY_PRINT)));
            throw new WeblingServiceAPIException(
                "Invalid response from member/match endpoint of Webling service. Response: {$response->getBody()}."
            );
        }

        if (!array_key_exists('status', $body)) {
            /** @noinspection JsonEncodingApiUsageInspection */
            Logger::debug(new LogMessage("Data sent to Webling service:\n" . json_encode($data, JSON_PRETTY_PRINT)));
            throw new WeblingServiceAPIException(
                "Invalid response from member/match endpoint of Webling service. Response: {$response->getBody()}."
            );
        }

        return $body;
    }

    /**
     * @throws GuzzleException
     */
    public function mainMember(int $memberId)
    {
        $response = $this->client->get("/api/v1/member/$memberId/main");

        try {
            $member = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WeblingServiceAPIException(
                "Invalid response from member/$memberId/main endpoint of Webling service. Response: {$response->getBody()}."
            );
        }

        return $member;
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    public function addMember(RaisenowPaymentData $paymentData): array
    {
        $data = self::convertToMemberUpsertData($paymentData);
        $data['groups'] = [
            'value' => [Config::get('groupIdForNewMembers')],
            'mode' => 'append',
        ];

        $create = $this->client->post('/api/v1/member', ['json' => $data]);
        $newMemberId = $create->getBody();

        $get = $this->client->get("/api/v1/member/$newMemberId");

        try {
            $member = json_decode((string)$get->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WeblingServiceAPIException(
                "Invalid response from member/$newMemberId endpoint of Webling service. Response: {$get->getBody()}."
            );
        }

        return $member;
    }

    /**
     * @throws ConfigException
     */
    private static function convertToMemberUpsertData(RaisenowPaymentData $paymentData): array
    {
        $lang = $paymentData->language[0];

        $gender = match ($paymentData->salutation) {
            'ms' => 'f',
            'mr' => 'm',
            'neutral' => 'n',
        };

        $salutation = $gender . strtoupper($lang);

        $data = [
            'email1' => [
                'value' => $paymentData->email,
                'mode' => 'replaceEmpty',
            ],
            'gender' => [
                'value' => $gender,
                'mode' => 'replaceEmpty',
            ],
            'salutationFormal' => [
                'value' => $salutation,
                'mode' => 'replaceEmpty',
            ],
            'salutationInformal' => [
                'value' => $salutation,
                'mode' => 'replaceEmpty',
            ],
            'firstName' => [
                'value' => $paymentData->firstName,
                'mode' => 'replaceEmpty',
            ],
            'lastName' => [
                'value' => $paymentData->lastName,
                'mode' => 'replaceEmpty',
            ],
            'company' => [
                'value' => $paymentData->company,
                'mode' => 'replaceEmpty',
            ],
            'address1' => [
                'value' => $paymentData->address1,
                'mode' => 'replaceEmpty',
            ],
            'address2' => [
                'value' => $paymentData->address2,
                'mode' => 'replaceEmpty',
            ],
            'zip' => [
                'value' => $paymentData->zip,
                'mode' => 'replaceEmpty',
            ],
            'city' => [
                'value' => $paymentData->city,
                'mode' => 'replaceEmpty',
            ],
            'country' => [
                'value' => mb_strtolower($paymentData->country),
                'mode' => 'replaceEmpty',
            ],
            'language' => [
                'value' => $lang,
                'mode' => 'replaceEmpty',
            ],
            'entryChannel' => [
                'value' => "RaiseNow donation: $paymentData->sourceUrl",
                'mode' => 'addIfNew',
            ],
            Config::get('donorField') => [
                'value' => 'donor',
                'mode' => 'replaceEmpty',
            ],
        ];

        if (!in_array($lang, ['d', 'f', 'i'])) {
            unset($data['language']);
        }

        if ($paymentData->newsletter && in_array($lang, ['d', 'f'])) {
            $newsletterFieldKey = Config::get('newsletterField' . strtoupper($lang));
            $data[$newsletterFieldKey] = [
                'value' => 'yes',
                'mode' => 'replace'
            ];
        }

        return $data;
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    public function updateMember(int $id, RaisenowPaymentData $paymentData): array
    {
        $data = self::convertToMemberUpsertData($paymentData);

        $update = $this->client->put("/api/v1/member/$id", ['json' => $data]);
        $memberId = (string)$update->getBody();

        $get = $this->client->get("/api/v1/member/$memberId");

        try {
            $member = json_decode((string)$get->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WeblingServiceAPIException(
                "Invalid response from member/$memberId endpoint of Webling service. Response: {$get->getBody()}."
            );
        }

        return $member;
    }
}