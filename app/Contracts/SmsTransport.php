<?php

namespace App\Contracts;

interface SmsTransport
{
    public function send(string $phoneNumber, string $message, ?string $serviceId = null): array;

    public function fetchDeliveryStatus(string $uniqueId): ?array;
}
