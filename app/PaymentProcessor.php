<?php

declare(strict_types=1);

namespace RaiseNowConnector;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use RaiseNowConnector\Client\WeblingAPI;
use RaiseNowConnector\Client\WeblingServiceAPI;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Exception\RaisenowPaymentDataException;
use RaiseNowConnector\Model\RaisenowPaymentData;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;
use RaiseNowConnector\Util\Mailer;

class PaymentProcessor
{
    private RaisenowPaymentData $payment;
    private array|null $weblingMember;
    private WeblingServiceAPI $weblingService;


    public function init(): void
    {
        try {
            $this->payment = RaisenowPaymentData::fromRequestData();

            if (!$this->processMemberData()) {
                http_response_code(200);
                return;
            }

            $this->processPaymentData();

        } catch (ConfigException|JsonException $e) {
            // @codeCoverageIgnoreStart
            Logger::error(
                new LogMessage(
                    (string)$e,
                    isset($this->payment) ? [
                        'transactionId' => $this->payment->eppTransactionId,
                        'email' => $this->payment->email
                    ] : []
                )
            );
            http_response_code(500);
            // @codeCoverageIgnoreEnd

        } catch (RaisenowPaymentDataException $e) {
            Logger::error(
                new LogMessage((string)$e, [
                    'transactionId' => $e->getPayment()->eppTransactionId,
                    'email' => $e->getPayment()->email
                ])
            );
            /** @noinspection PhpUnhandledExceptionInspection */
            Mailer::notifyAdmin(
                "Invalid data received from Raisenow:\n{$e->getMessage()}",
                $e->getPayment()
            );
            /** @noinspection PhpUnhandledExceptionInspection */
            Mailer::notifyAccountantError(
                "Failed to process payment. Please enter payment manually.",
                $e->getPayment()
            );

            http_response_code(400);
        } catch (GuzzleException $e) {
            Logger::warning(
                new LogMessage(
                    (string)$e,
                    isset($this->payment) ? [
                        'transactionId' => $this->payment->eppTransactionId,
                        'email' => $this->payment->email
                    ] : []
                )
            );
            /** @noinspection PhpUnhandledExceptionInspection */
            Mailer::notifyAccountantError(
                "Failed to process payment. Please enter payment manually.",
                $this->payment
            );

            if ($e instanceof RequestException && $e->hasResponse()) {
                /** @noinspection NullPointerExceptionInspection */
                http_response_code($e->getResponse()->getStatusCode());
            } elseif ($e instanceof ConnectException) {
                http_response_code(502);
            }
        }
    }


    /**
     * @throws ConfigException
     * @throws JsonException
     * @throws RaisenowPaymentDataException
     * @throws GuzzleException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    private function processMemberData(): bool
    {
        $this->weblingService = new WeblingServiceAPI();
        $this->updateAndGetMemberFromWebling();

        if (!$this->weblingMember) {
            Mailer::notifyAccountantError(
                "Failed to process payment. Please enter payment manually.",
                $this->payment
            );

            return false;
        }

        return true;
    }


    /**
     * @throws ConfigException
     * @throws JsonException
     * @throws RaisenowPaymentDataException
     * @throws GuzzleException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    private function processPaymentData(): void
    {
        if (!$this->addPaymentToWebling()) {
            // payment already exists in Webling
            http_response_code(200);

            return;
        }

        // notify accountant, if donor left a message
        if (!empty($this->payment->message)) {
            Mailer::notifyAccountantDonorMessage($this->payment);
            Logger::debug(
                new LogMessage(
                    "Accountant notified about message in payment.",
                    [
                        'transactionId' => $this->payment->eppTransactionId,
                        'email' => $this->payment->email,
                        'memberId' => $this->weblingMember['id']
                    ]
                )
            );
        }

        http_response_code(201);
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    private function updateAndGetMemberFromWebling(): void
    {
        // search member in webling that matches the payee name and address
        $match = $this->weblingService->matchMember($this->payment);

        switch ($match['status']) {
            case WeblingServiceAPI::MATCH_EXACT:
                // found exactly one existing record in webling -> use it
                $this->weblingMember = $match['matches'][0];
                Logger::debug(
                    new LogMessage(
                        "Payment matched member with id {$this->weblingMember['id']}.",
                        [
                            'transactionId' => $this->payment->eppTransactionId,
                            'email' => $this->payment->email
                        ]
                    )
                );
                $this->maybeCompleteMissingMemberData();
                break;

            case WeblingServiceAPI::MATCH_MULTIPLE:
                // found multiple records in webling -> use the main record
                $this->weblingMember = $this->weblingService->mainMember($match['matches'][0]['id']);
                Logger::debug(
                    new LogMessage(
                        "Payment matched multiple members. Main member has id {$this->weblingMember['id']}.",
                        [
                            'transactionId' => $this->payment->eppTransactionId,
                            'email' => $this->payment->email
                        ]
                    )
                );
                $this->maybeCompleteMissingMemberData();
                break;

            case WeblingServiceAPI::MATCH_NONE:
                // found no record in webling -> create a new one
                $this->weblingMember = $this->weblingService->addMember($this->payment);
                Logger::debug(
                    new LogMessage(
                        "Payment matched no one. Newly created member has id {$this->weblingMember['id']}.",
                        [
                            'transactionId' => $this->payment->eppTransactionId,
                            'email' => $this->payment->email
                        ]
                    )
                );
                break;

            case WeblingServiceAPI::MATCH_AMBIGUOUS:
            default:
                // unsure, if a corresponding record exists in webling -> handle manually
                $this->weblingMember = null;
                Logger::info(
                    new LogMessage(
                        "Payment matched multiple members. Failed to disambiguate. Notifying accountant and exiting.",
                        [
                            'transactionId' => $this->payment->eppTransactionId,
                            'email' => $this->payment->email
                        ]
                    )
                );
                break;
        }
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    private function maybeCompleteMissingMemberData(): void
    {
        // maybe complete missing address
        // maybe add newsletter subscription
        if (empty($this->weblingMember['address1'])
            || empty($this->weblingMember['zip'])
            || empty($this->weblingMember['city'])
            || $this->payment->newsletter
        ) {
            $memberData = $this->weblingService->updateMember($this->weblingMember['id'], $this->payment);
            Logger::debug(
                new LogMessage("Updated member data in Webling.", [
                    'transactionId' => $this->payment->eppTransactionId,
                    'email' => $this->payment->email,
                    'memberId' => $memberData['id']
                ])
            );
        }
    }

    /**
     * @return bool false if payment already exists in Webling, true if added
     *
     * @throws ConfigException
     * @throws GuzzleException
     */
    private function addPaymentToWebling(): bool
    {
        $webling = new WeblingAPI();

        if ($webling->paymentExists($this->payment)) {
            Logger::info(
                new LogMessage(
                    "Payment {$this->payment->eppTransactionId} is already in Webling. Aborting.",
                    [
                        'transactionId' => $this->payment->eppTransactionId,
                        'email' => $this->payment->email
                    ]
                )
            );

            return false;
        }

        // add payment to webling
        $webling->addPayment($this->weblingMember['id'], $this->payment);
        Logger::debug(
            new LogMessage(
                "Payment successfully added to Webling.",
                [
                    'transactionId' => $this->payment->eppTransactionId,
                    'email' => $this->payment->email,
                    'memberId' => $this->weblingMember['id']
                ]
            )
        );

        return true;
    }
}