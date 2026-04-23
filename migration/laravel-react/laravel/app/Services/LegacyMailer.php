<?php

namespace App\Services;

class LegacyMailer
{
    public function getLegacyAutoloadPath(): string
    {
        return rtrim(config('legacy.legacy_root'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'phpmailer'
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    public function ensureLegacyMailerLoaded(): void
    {
        $autoload = $this->getLegacyAutoloadPath();
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}
