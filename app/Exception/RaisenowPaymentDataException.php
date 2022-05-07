<?php
declare(strict_types=1);

namespace RaiseNowConnector\Exception;

use RaiseNowConnector\Model\RaisenowPaymentData;
use RuntimeException;

class RaisenowPaymentDataException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly RaisenowPaymentData $payment
    ) {
        parent::__construct($message);
    }

    public function getPayment(): RaisenowPaymentData
    {
        return $this->payment;
    }
}