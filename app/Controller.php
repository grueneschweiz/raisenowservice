<?php

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
use RaiseNowConnector\Util\Auth;
use RaiseNowConnector\Util\Config;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;
use RaiseNowConnector\Util\Mailer;

class Controller
{
    private RaisenowPaymentData $payment;
    private int|null $weblingMemberId;

    public function init(): void
    {
        if (!Config::exists()) {
            http_response_code(404);

            return;
        }

        if (!Auth::webhookSecretIsValid()) {
            http_response_code(401);

            return;
        }

        try {
            $this->processPayment();
        } catch (ConfigException|JsonException $e) {
            // @codeCoverageIgnoreStart
            Logger::error(
                new LogMessage(
                    $e, isset($this->payment) ? [
                    'transactionId' => $this->payment->eppTransactionId,
                    'email' => $this->payment->email
                ] : []
                )
            );
            http_response_code(500);
            // @codeCoverageIgnoreEnd

        } catch (RaisenowPaymentDataException $e) {
            Logger::error(
                new LogMessage($e, [
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
                    $e, isset($this->payment) ? [
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
    private function processPayment(): void
    {
        $this->payment = RaisenowPaymentData::fromRequestData();

        $this->updateAndGetMemberFromWebling();

        if (!$this->weblingMemberId) {
            // unsure, if a corresponding record exists in webling -> handle manually
            Logger::info(
                new LogMessage(
                    "Payment matched multiple members. Failed to disambiguate. Notifying accountant and exiting.",
                    [
                        'transactionId' => $this->payment->eppTransactionId,
                        'email' => $this->payment->email
                    ]
                )
            );
            Mailer::notifyAccountantError(
                "Failed to process payment. Please enter payment manually.",
                $this->payment
            );
            http_response_code(200);

            return;
        }

        if (! $this->addPaymentToWebling()) {
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
                        'memberId' => $this->weblingMemberId
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
        $weblingService = new WeblingServiceAPI();

        // search member in webling that matches the payee name and address
        $match = $weblingService->matchMember($this->payment);

        switch ($match['status']) {
            case WeblingServiceAPI::MATCH_EXACT:
                // found exactly one existing record in webling -> use it
                $memberData = $match['matches'][0];
                Logger::debug(
                    new LogMessage(
                        "Payment matched member with id {$memberData['id']}.",
                        [
                            'transactionId' => $this->payment->eppTransactionId,
                            'email' => $this->payment->email
                        ]
                    )
                );
                break;

            case WeblingServiceAPI::MATCH_MULTIPLE:
                // found multiple records in webling -> use the main record
                $memberData = $weblingService->mainMember($match['matches'][0]['id']);
                Logger::debug(
                    new LogMessage(
                        "Payment matched multiple members. Main member has id {$memberData['id']}.",
                        [
                            'transactionId' => $this->payment->eppTransactionId,
                            'email' => $this->payment->email
                        ]
                    )
                );
                break;

            case WeblingServiceAPI::MATCH_NONE:
                // found no record in webling -> create a new one
                $memberData = $weblingService->addMember($this->payment);
                Logger::debug(
                    new LogMessage(
                        "Payment matched no one. Newly created member has id {$memberData['id']}.",
                        [
                            'transactionId' => $this->payment->eppTransactionId,
                            'email' => $this->payment->email
                        ]
                    )
                );
                break;

            case WeblingServiceAPI::MATCH_AMBIGUOUS:
            default:
                $this->weblingMemberId = null;
                return;
        }

        // maybe complete missing address
        // maybe add newsletter subscription
        if (empty($memberData['address1'])
            || empty($memberData['zip'])
            || empty($memberData['city'])
            || $this->payment->newsletter
        ) {
            $memberData = $weblingService->updateMember($memberData['id'], $this->payment);
            Logger::debug(
                new LogMessage("Updated member data in Webling.", [
                    'transactionId' => $this->payment->eppTransactionId,
                    'email' => $this->payment->email,
                    'memberId' => $memberData['id']
                ])
            );
        }

        $this->weblingMemberId = $memberData['id'];
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
        $webling->addPayment($this->weblingMemberId, $this->payment);
        Logger::debug(
            new LogMessage(
                "Payment successfully added to Webling.",
                [
                    'transactionId' => $this->payment->eppTransactionId,
                    'email' => $this->payment->email,
                    'memberId' => $this->weblingMemberId
                ]
            )
        );

        return true;
    }
}