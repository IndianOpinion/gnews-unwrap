<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

final class Version
{
    public const VERSION = '0.1.0';
    public const REPOSITORY = 'https://github.com/IndianOpinion/gnews-unwrap';

    public static function userAgent(): string
    {
        return 'gnews-unwrap/' . self::VERSION . ' (+' . self::REPOSITORY . ')';
    }
}
