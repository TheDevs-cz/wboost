<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class EditUserInfo
{
    public function __construct(
        public string $email,
        public null|string $name,
        public null|int $preferredPlaceId,
        public null|string $phone,
        public null|string $deliveryStreet,
        public null|string $deliveryCity,
        public null|string $deliveryZip,
        public bool $companyInvoicing,
        public null|string $companyName,
        public null|string $companyId,
        public null|string $invoicingStreet,
        public null|string $invoicingCity,
        public null|string $invoicingZip,
    ) {
    }
}
