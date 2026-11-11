<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap;

/**
 * The resolution methods, in the order the resolver tries them.
 */
enum Method: string
{
    case Cache = 'cache';
    case QueryParam = 'query_param';
    case OfflineDecode = 'offline_decode';
    case BatchExecute = 'batch_execute';
    case HttpRedirect = 'http_redirect';
    case HtmlParse = 'html_parse';
    case None = 'none';

    /**
     * The methods a caller can switch on or off, in resolution order.
     *
     * @return list<self>
     */
    public static function switchable(): array
    {
        return [
            self::Cache,
            self::QueryParam,
            self::OfflineDecode,
            self::BatchExecute,
            self::HttpRedirect,
            self::HtmlParse,
        ];
    }

    public function usesNetwork(): bool
    {
        return $this === self::BatchExecute || $this === self::HttpRedirect;
    }
}
