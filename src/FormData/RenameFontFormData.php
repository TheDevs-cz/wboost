<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class RenameFontFormData
{
    #[Assert\NotBlank(message: 'Zadejte název písma.')]
    #[Assert\Length(max: 120)]
    // One line of visible text: the name ends up in CSS and in canvas documents.
    #[Assert\Regex(pattern: '/[\x{00}-\x{1F}\x{7F}-\x{9F}\x{2028}\x{2029}]/u', match: false, message: 'Název písma nesmí obsahovat řídicí znaky ani zalomení řádku.')]
    public null|string $name = null;
}
