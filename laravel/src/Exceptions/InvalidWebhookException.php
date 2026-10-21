<?php

namespace SolosmartDigital\Solosmartpay\Exceptions;

use RuntimeException;

/** The webhook was not sent by Solosmartpay (bad signature), is too old, or is malformed. */
class InvalidWebhookException extends RuntimeException
{
}
