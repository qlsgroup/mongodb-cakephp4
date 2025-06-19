<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Exception;

use Exception;

class SshExtensionNotEnabledException extends Exception
{
    public function __construct()
    {
        parent::__construct("ssh2 extension not enabled, but an ssh connection was configured");
    }
}