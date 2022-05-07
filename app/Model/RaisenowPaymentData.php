<?php

declare(strict_types=1);

namespace RaiseNowConnector\Model;

use DateTimeImmutable;
use RaiseNowConnector\Exception\RaisenowPaymentDataException;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;

use function date_create_immutable;

class RaisenowPaymentData
{
    private function __construct(
        public string $language,
        public string $salutation,
        public string $firstName,
        public string $lastName,
        public string $company,
        public string $address1,
        public string $address2,
        public string $zip,
        public string $city,
        public string $country,
        public string $email,
        public int $amount, // Cents
        public DateTimeImmutable|false $created,
        public string $eppTransactionId,
        public string $sourceUrl,
        public string $paymentMethod,
        public bool $newsletter,
        public string $message,
    ) {
    }

    /**
     * @throws RaisenowPaymentDataException
     */
    public static function fromRequestData(): RaisenowPaymentData
    {
        $payment = new RaisenowPaymentData(
            language: mb_strtolower(self::getPostData('language')),
            salutation: mb_strtolower(self::getPostData('stored_customer_salutation')),
            firstName: self::getPostData('stored_customer_firstname'),
            lastName: self::getPostData('stored_customer_lastname'),
            company: self::getPostData('stored_customer_company'),
            address1: self::getAddress1(),
            address2: self::getAddress2(),
            zip: self::getPostData('stored_customer_zip_code'),
            city: self::getPostData('stored_customer_city'),
            country: mb_strtoupper(self::getPostData('stored_customer_country')),
            email: self::getPostData('stored_customer_email'),
            amount: (int)filter_var(self::getPostData('amount'), FILTER_VALIDATE_INT),
            created: date_create_immutable(self::getPostData('created')),
            eppTransactionId: self::getPostData('epp_transaction_id'),
            sourceUrl: self::getPostData('stored_rnw_source_url'),
            paymentMethod: self::getPostData('payment_method'),
            newsletter: self::getNewsletter(),
            message: self::getPostData('stored_customer_message'),
        );

        $payment->validate();

        return $payment;
    }

    private static function getPostData(string $key): string
    {
        if (!self::postDataExists($key)) {
            $context = [];
            if (self::postDataExists('epp_transaction_id')) {
                $context['transactionId'] = self::getPostData('epp_transaction_id');
            }
            if (self::postDataExists('stored_customer_email')) {
                $context['email'] = self::getPostData('stored_customer_email');
            }

            Logger::info(new LogMessage("Post data received from webhook is missing key: $key", $context));

            return '';
        }

        return trim(strip_tags((string)$_POST['data'][$key]));
    }

    private static function postDataExists(string $key): bool
    {
        return array_key_exists('data', $_POST) && array_key_exists($key, $_POST['data']);
    }

    private static function getAddress1(): string
    {
        return trim(
            self::getPostData('stored_customer_street')
            . ' '
            . self::getPostData('stored_customer_street_number')
        );
    }

    private static function getAddress2(): string
    {
        return trim(
            self::getPostData('stored_customer_street2')
            . ' '
            . self::getPostData('stored_customer_pobox')
        );
    }

    private static function getNewsletter(): bool
    {
        if (self::postDataExists('stored_customer_email_permission')) {
            return filter_var(self::getPostData('stored_customer_email_permission'), FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * @throws RaisenowPaymentDataException
     */
    private function validate(): void
    {
        if (!in_array($this->language, ['de', 'fr', 'it', 'en'])) {
            throw new RaisenowPaymentDataException(
                "Invalid payment data. Given language is not valid: $this->language",
                $this
            );
        }

        if (!in_array($this->salutation, ['mr', 'ms', 'neutral'])) {
            throw new RaisenowPaymentDataException(
                "Invalid payment data. Given salutation is not valid: $this->salutation",
                $this
            );
        }

        if (!in_array($this->country, ['CH', 'DE', 'FR', 'IT', 'AT'])) {
            // other countries are treated differently in webling, so we have to handle these manually
            throw new RaisenowPaymentDataException(
                "Invalid payment data. Payment from unknown country: $this->country",
                $this
            );
        }

        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new RaisenowPaymentDataException(
                "Invalid payment data. Given email address is not valid: $this->email",
                $this
            );
        }

        if (!$this->created) {
            throw new RaisenowPaymentDataException(
                "Invalid payment data. Failed to parse date: {'created']}",
                $this
            );
        }

        if ($this->sourceUrl && !filter_var($this->sourceUrl, FILTER_VALIDATE_URL)) {
            throw new RaisenowPaymentDataException("Invalid payment data. Invalid source url: $this->sourceUrl", $this);
        }

        if (empty($this->firstName) || empty($this->lastName)) {
            throw new RaisenowPaymentDataException("Invalid payment data. Missing first or last name.", $this);
        }
    }

    public function __toString(): string
    {
        $created = $this->created ? $this->created->format('d.m.Y H:i:s') : 'UNKNOWN';
        $timestamp = date_create_immutable()->format('d.m.Y H:i:s');

        $string = <<<EOL
Amount              {$this->getFormattedAmount()}
Form URL            $this->sourceUrl   

Email               $this->email
Language            $this->language
Salutation          $this->salutation

Name                $this->firstName $this->lastName
Company             $this->company
Address 1           $this->address1
Address 2           $this->address2
ZIP City            $this->zip $this->city
Country             $this->country

Newsletter          $this->newsletter

Payment Timestamp   $created
Current Timestamp   $timestamp
Transaction Id      $this->eppTransactionId
Payment Method      $this->paymentMethod
EOL;

        if ($this->message) {
            $string .= <<<EOL


Message from $this->firstName $this->lastName
=============================
$this->message
EOL;
        }

        return $string;
    }

    public function getFormattedAmount(): string
    {
        return sprintf('%.2f', $this->amount / 100) . ' CHF';
    }
}