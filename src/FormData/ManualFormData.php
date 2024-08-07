<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use WBoost\Web\Message\Manual\AddManual;
use WBoost\Web\Value\ManualType;

final class ManualFormData
{
    public string $name = '';
    public null|ManualType $type = null;
}
