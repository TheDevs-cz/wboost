<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/** The palette colour is used by manuals — deleting it needs the explicit "remove from manuals" confirmation (F7 P2). */
final class ProjectColorUsedInManuals extends \Exception
{
}
