<?php

declare(strict_types=1);

namespace RaiseNowConnector;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Exception\RaisenowPaymentDataException;
use RaiseNowConnector\Exception\WeblingAPIException;
use RaiseNowConnector\Exception\WeblingMissingAccountingPeriodException;
use RaiseNowConnector\Model\RaisenowPaymentData;
use RaiseNowConnector\Model\WeblingPaymentState;
use RaiseNowConnector\Util\Logger;
use RaiseNowConnector\Util\LogMessage;
use RaiseNowConnector\Util\Mailer;

class RaisenowPaymentHandler
{
    private const RETRY_AFTER_IF_LOCKED = 300; // seconds
    private const RETRY_AFTER_IF_MISSING_ACCOUNTING_PERIOD = 24 * 60 * 60; // seconds

    private RaisenowPaymentData $payment;


    public function handleRequest(): void
    {
        try {
            $this->payment = RaisenowPaymentData::fromRequestData();

            $weblingMemberId = (new WeblingMemberProcessor($this->payment))->process();

            if (!$weblingMemberId) {
                Mailer::notifyAccountantError(
                    "Failed to process payment. Please enter payment manually.",
                    $this->payment
                );

                http_response_code(200);
                return;
            }

            $paymentStatus = (new WeblingPaymentProcessor($this->payment, $weblingMemberId))->process();

            $statusCode = match ($paymentStatus) {
                WeblingPaymentState::Added => 201,
                WeblingPaymentState::Exists => 200,
                WeblingPaymentState::Locked => 503,
            };

            if ($paymentStatus === WeblingPaymentState::Locked) {
                header('Retry-After: ' . (self::RETRY_AFTER_IF_LOCKED));
            }

            http_response_code($statusCode);
        } catch (WeblingMissingAccountingPeriodException $e) {
            Logger::error(
                new LogMessage(
                    (string)$e, isset($this->payment) ? [
                    'transactionId' => $this->payment->eppTransactionId,
                    'email' => $this->payment->email
                ] : []
                )
            );
            /** @noinspection PhpUnhandledExceptionInspection */
            Mailer::notifyAccountantError(
                "Failed to process payment due to MISSING ACCOUNTING PERIOD in Webling. We'll try again as soon as the accounting period exists.",
                $this->payment
            );

            header('Retry-After: ' . (self::RETRY_AFTER_IF_MISSING_ACCOUNTING_PERIOD));
            http_response_code(503);
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

            // we can't recover from this error, so return a 200 to avoid retries
            http_response_code(200);
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
        } catch (ConfigException|JsonException|WeblingAPIException|\Exception $e) {
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
        }
    }
}