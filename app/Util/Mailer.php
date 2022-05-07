<?php
declare(strict_types=1);

namespace RaiseNowConnector\Util;

use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Model\RaisenowPaymentData;

class Mailer
{
    /**
     * @throws ConfigException
     */
    public static function notifyAdmin(string $message, RaisenowPaymentData $payment): void
    {
        $subject = 'RaiseNow -> Webling: ERROR';
        $to = Config::get('adminEmail');
        $config = Config::name();

        $msg = <<<EOL
Hi Admin,

There was an error while processing a RaiseNow donation. 
We will also inform the accountant, so you will only have to worry about the technical stuff.


Diagnostic Info
===============
Config: $config
Error Message: $message


Payment Details
===============
$payment
EOL;

        mb_send_mail($to, $subject, wordwrap($msg, 70));
    }

    /**
     * @throws ConfigException
     */
    public static function notifyAccountantError(string $message, RaisenowPaymentData $payment): void
    {
        $subject = 'RaiseNow -> Webling: ERROR';
        $to = Config::get('accountantEmail');
        $config = Config::name();

        $msg = <<<EOL
Dear Accountant,

There was an error while processing a RaiseNow donation. Please enter the payment manually.


Payment Details
===============
$payment


Diagnostic Info
===============
Config: $config
Error Message: $message
EOL;

        mb_send_mail($to, $subject, wordwrap($msg, 70));
    }

    /**
     * @throws ConfigException
     */
    public static function notifyAccountantDonorMessage(RaisenowPaymentData $payment): void
    {
        $subject = 'RaiseNow donor message';
        $to = Config::get('accountantEmail');
        $config = Config::name();

        $msg = <<<EOL
Dear Accountant,

$payment->firstName $payment->lastName donated {$payment->getFormattedAmount()} and left the following message:

Message
=======
$payment->message


Payment Details
===============
$payment


Diagnostic Info
===============
Config: $config
EOL;

        mb_send_mail($to, $subject, wordwrap($msg, 70));
    }
}