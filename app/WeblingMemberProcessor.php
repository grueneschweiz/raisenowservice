<?php

declare(strict_types=1);

namespace RaiseNowConnector;

use GuzzleHttp\Exception\GuzzleException;
use RaiseNowConnector\Client\WeblingServiceAPI;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Model\RaisenowPaymentData;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;

class WeblingMemberProcessor
{
    private WeblingServiceAPI $weblingService;
    private array|null $weblingMember;

    public function __construct(private readonly RaisenowPaymentData $payment)
    {
        $this->weblingService = new WeblingServiceAPI();
    }

    /**
     * @throws ConfigException
     * @throws GuzzleException
     */
    public function process(): int|null
    {
        $this->updateAndGetMemberFromWebling();

        if (!$this->weblingMember) {
            return null;
        }

        return $this->weblingMember['id'];
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
                    $this->createLogMsg(
                        "Payment matched member with id {$this->weblingMember['id']}."
                    )
                );
                $this->maybeCompleteMissingMemberData();
                break;

            case WeblingServiceAPI::MATCH_MULTIPLE:
                // found multiple records in webling -> use the main record
                $this->weblingMember = $this->weblingService->mainMember($match['matches'][0]['id']);
                Logger::debug(
                    $this->createLogMsg(
                        "Payment matched multiple members. Main member has id {$this->weblingMember['id']}."
                    )
                );
                $this->maybeCompleteMissingMemberData();
                break;

            case WeblingServiceAPI::MATCH_NONE:
                // found no record in webling -> create a new one
                $this->weblingMember = $this->weblingService->addMember($this->payment);
                Logger::debug(
                    $this->createLogMsg(
                    "Payment matched no one. Newly created member has id {$this->weblingMember['id']}."
                    )
                );
                break;

            case WeblingServiceAPI::MATCH_AMBIGUOUS:
            default:
                // unsure, if a corresponding record exists in webling -> handle manually
                $this->weblingMember = null;
                Logger::info(
                    $this->createLogMsg(
                        "Payment matched multiple members. Failed to disambiguate."
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
            $this->weblingMember = $this->weblingService->updateMember($this->weblingMember['id'], $this->payment);
            Logger::debug(
                $this->createLogMsg("Updated member data in Webling.")
            );
        }
    }

    private function createLogMsg(string $msg): LogMessage
    {
        return new LogMessage(
            $msg,
            [
                'transactionId' => $this->payment->eppTransactionId,
                'email' => $this->payment->email,
                'memberId' => $this->weblingMember['id'] ?? '',
            ]
        );
    }
}