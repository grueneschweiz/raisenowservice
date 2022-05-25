<?php

declare(strict_types=1);

namespace RaiseNowConnector;

use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RaiseNowConnector\Client\WeblingAPI;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Exception\RaisenowPaymentDataException;
use RaiseNowConnector\Model\RaisenowPaymentData;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;
use RaiseNowConnector\Util\Mailer;

class WeblingPaymentProcessor
{
    public function __construct(
        private readonly RaisenowPaymentData $payment,
        private readonly int|null $weblingMemberId
    ) {

    }

    /**
     * @throws ConfigException
     * @throws JsonException
     * @throws RaisenowPaymentDataException
     * @throws GuzzleException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function process(): bool
    {
        if (!$this->addPaymentToWebling()) {
            // payment already exists in Webling
            return false;
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

        // payment added
        return true;
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