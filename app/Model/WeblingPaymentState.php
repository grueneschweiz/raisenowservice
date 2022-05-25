<?php

declare(strict_types=1);

namespace RaiseNowConnector\Model;

enum WeblingPaymentState {
    case Exists;
    case Added;
    case Locked;
}