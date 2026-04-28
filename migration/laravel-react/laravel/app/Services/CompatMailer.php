<?php

namespace App\Services;

class CompatMailer
{
    public function getAutoloadPath(): string
    {
        return rtrim((string) config('compat.compat_root'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'phpmailer'
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    public function ensureLoaded(): void
    {
        $autoload = $this->getAutoloadPath();
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}
