<?php

declare(strict_types=1);

namespace RaiseNowConnector;

use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RaiseNowConnector\Client\WeblingAPI;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Exception\RaisenowPaymentDataException;
use RaiseNowConnector\Exception\WeblingAPIException;
use RaiseNowConnector\Exception\WeblingMissingAccountingPeriodException;
use RaiseNowConnector\Model\RaisenowPaymentData;
use RaiseNowConnector\Model\WeblingPaymentState;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;
use RaiseNowConnector\Util\Mailer;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

class WeblingPaymentProcessor
{
    private WeblingAPI $webling;
    private LockInterface $lock;

    public function __construct(
        private readonly RaisenowPaymentData $payment,
        private readonly int|null $weblingMemberId
    ) {
        $this->webling = new WeblingAPI();
    }

    /**
     * @throws ConfigException
     * @throws JsonException
     * @throws RaisenowPaymentDataException
     * @throws GuzzleException
     * @throws WeblingAPIException
     * @throws WeblingMissingAccountingPeriodException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function process(): WeblingPaymentState
    {
        if (!$this->lock()) {
            return WeblingPaymentState::Locked;
        }

        try {
            $added = $this->addPaymentToWebling();
        } finally {
            $this->unlock();
        }

        if (!$added) {
            // payment already exists in Webling
            return WeblingPaymentState::Exists;
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
        return WeblingPaymentState::Added;
    }

    private function lock(): bool
    {
        $factory = new LockFactory(new FlockStore());
        $this->lock = $factory->createLock($this->payment->eppTransactionId);
        return $this->lock->acquire();
    }

    /**
     * @return bool false if payment already exists in Webling, true if added
     *
     * @throws ConfigException
     * @throws GuzzleException
     * @throws WeblingAPIException
     * @throws WeblingMissingAccountingPeriodException
     */
    private function addPaymentToWebling(): bool
    {
        if ($this->webling->paymentExists($this->payment)) {
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
        $this->webling->addPayment($this->weblingMemberId, $this->payment);
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

    private function unlock(): void
    {
        $this->lock->release();
    }
}