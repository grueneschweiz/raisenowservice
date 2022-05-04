<?php declare(strict_types=1);

namespace RaiseNowConnector\Client;

use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\ArrayShape;
use JsonException;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Exception\PersistanceException;
use RaiseNowConnector\Exception\WeblingAccountingConfigException;
use RaiseNowConnector\Exception\WeblingAPIException;
use RaiseNowConnector\Model\RaisenowPaymentData;
use RaiseNowConnector\Model\WeblingAccountingConfig;
use RaiseNowConnector\Util\ClientFactory;
use RaiseNowConnector\Util\Config;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;

class WeblingAPI
{
    private const REQUEST_TIMEOUT = 90;  // seconds

    private Client $client;

    /**
     * @var array [DATE_ATOM => $period_id]
     */
    private array $periodId;

    /**
     * @throws ConfigException
     */
    public function __construct()
    {
        $apiUrl = rtrim(Config::get('weblingApiUrl'), '/').'/';

        $this->client = ClientFactory::create([
            'base_uri'    => $apiUrl,
            'timeout'     => self::REQUEST_TIMEOUT,
            'http_errors' => true,
            'headers'     => [
                'apikey' => Config::get('weblingApiKey'),
            ],
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws ConfigException
     */
    public function paymentExists(RaisenowPaymentData $payment): bool
    {
        $periodId = $this->getPeriodId($payment->created);

        $filter = sprintf(
            'state = "paid" AND $links.payment.receipt = "%s" AND $parents.$id = %d',
            $payment->eppTransactionId,
            $periodId
        );

        $response = $this->client->get('/api/1/debitor', ['query' => ['filter' => $filter]]);

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WeblingAPIException("Invalid response from debitor endpoint of Webling. Response: {$response->getBody()}.");
        }

        if ( ! array_key_exists('objects', $data)) {
            /** @noinspection JsonEncodingApiUsageInspection */
            $json = json_encode($data, JSON_PRETTY_PRINT);
            throw new WeblingAPIException("Unexpected response from debitor endpoint of Webling. Response: $json.");
        }

        return count($data['objects']) > 0;
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    public function addPayment(mixed $memberId, RaisenowPaymentData $payment): void
    {
        $accountingConfig = $this->obtainAccountingConfig($payment->created);

        $data = [
            'properties' => [
                'title'   => $payment->sourceUrl,
                'date'    => $payment->created->format(DATE_ATOM),
                'duedate' => $payment->created->format(DATE_ATOM),
            ],
            'parents'    => [
                $accountingConfig->periodId,
            ],
            'links'      => [
                'revenue'         => [
                    self::createEntryGroup(
                        $payment,
                        $accountingConfig->periodId,
                        $accountingConfig->donationAccountId,
                        $accountingConfig->debtorAccountId
                    )
                ],
                'payment'         => [
                    self::createEntryGroup(
                        $payment,
                        $accountingConfig->periodId,
                        $accountingConfig->debtorAccountId,
                        $accountingConfig->bankAccountId
                    )
                ],
                'member'          => [
                    $memberId
                ],
                'debitorcategory' => [
                    Config::get('debtorCategoryId')
                ],
            ],
        ];

        $this->client->post('/api/1/debitor', ['json' => $data]);
    }

    #[ArrayShape(['properties' => "array", 'parents' => "array[]", 'links' => "\int[][]"])]
    private static function createEntryGroup(
        RaisenowPaymentData $payment,
        int $periodId,
        int $creditAccountId,
        int $debitAccountId
    ): array {
        return [
            'properties' => [
                'amount'  => $payment->amount / 100,
                'receipt' => $payment->eppTransactionId,
            ],
            'parents'    => [
                [
                    'properties' => [
                        'date'  => $payment->created->format(DATE_ATOM),
                        'title' => $payment->sourceUrl,
                    ],
                    'parents'    => [
                        $periodId,
                    ],
                ],
            ],
            'links'      => [
                'credit' => [
                    $creditAccountId,
                ],
                'debit'  => [
                    $debitAccountId
                ],
            ],
        ];
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    private function getPeriodId(DateTimeInterface $date): int
    {
        $dateKey = $date->format(DATE_ATOM);

        if (isset($this->periodId[$dateKey])) {
            return $this->periodId[$dateKey];
        }

        $filter = sprintf(
            '$parents.$id = %d AND `from` <= "%s" AND `to` >= "%s"',
            Config::get('periodGroupId'),
            $date->format(DATE_ATOM),
            $date->format(DATE_ATOM)
        );

        $response = $this->client->get('/api/1/period', ['query' => ['filter' => $filter]]);

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WeblingAPIException("Invalid response from period endpoint of Webling. Response: {$response->getBody()}.");
        }

        if ( ! array_key_exists('objects', $data)) {
            /** @noinspection JsonEncodingApiUsageInspection */
            $json = json_encode($data, JSON_PRETTY_PRINT);
            throw new WeblingAPIException("Unexpected response from period endpoint of Webling. Response: $json.");
        }

        if (count($data['objects']) !== 1) {
            throw new WeblingAPIException("No accounting period found in Webling for {$date->format(DATE_ATOM)}");
        }

        $periodId = $data['objects'][0];

        $this->periodId[$dateKey] = $periodId;

        return $periodId;
    }

    /**
     * @throws GuzzleException
     */
    private function getAccountId(int $periodId, int $accountTemplateId): int
    {
        $filter = sprintf(
            '$parents.$parents.$id = %d AND $links.accounttemplate.$id = %s',
            $periodId,
            $accountTemplateId
        );

        $response = $this->client->get('/api/1/account', ['query' => ['filter' => $filter]]);

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WeblingAPIException("Invalid response from account endpoint of Webling. Response: {$response->getBody()}.");
        }

        if ( ! array_key_exists('objects', $data)) {
            /** @noinspection JsonEncodingApiUsageInspection */
            $json = json_encode($data, JSON_PRETTY_PRINT);
            throw new WeblingAPIException("Unexpected response from account endpoint of Webling. Response: $json.");
        }

        if (count($data['objects']) !== 1) {
            throw new WeblingAPIException("No account found in Webling for periodId $periodId and accountTemplateId $accountTemplateId");
        }

        return $data['objects'][0];
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    private function obtainAccountingConfig(DateTimeInterface $date): WeblingAccountingConfig
    {
        $periodId                  = $this->getPeriodId($date);
        $donationAccountTemplateId = Config::get('donationAccountTemplateId');
        $debtorAccountTemplateId   = Config::get('debtorAccountTemplateId');
        $bankAccountTemplateId     = Config::get('bankAccountTemplateId');

        $filename = Config::name()."-$periodId-".md5("$donationAccountTemplateId|$debtorAccountTemplateId|$bankAccountTemplateId");

        try {
            return WeblingAccountingConfig::loadFromFile($filename);
        } catch (PersistanceException|WeblingAccountingConfigException $e) {
            Logger::debug(new LogMessage($e->getMessage()));
        }

        $accountingConfig = new WeblingAccountingConfig(
            $periodId,
            $this->getAccountId($periodId, Config::get('donationAccountTemplateId')),
            $this->getAccountId($periodId, Config::get('debtorAccountTemplateId')),
            $this->getAccountId($periodId, Config::get('bankAccountTemplateId')),
        );

        $accountingConfig->persist($filename);

        return $accountingConfig;
    }
}