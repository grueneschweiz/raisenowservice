<?php

namespace RaiseNowConnector;

use RaiseNowConnector\Util\Auth;
use RaiseNowConnector\Util\Config;

class Controller
{
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

        (new RaisenowPaymentHandler())->handleRequest();
    }
}