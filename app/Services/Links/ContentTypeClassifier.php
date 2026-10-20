<?php

namespace App\Services\Links;

/**
 * Initial content_type from the normalized host (video|article|product|social|other).
 * The metadata job refines it later. Provisional rules: DEC-007.
 */
class ContentTypeClassifier
{
    public const VIDEO = 'video';

    public const ARTICLE = 'article';

    public const PRODUCT = 'product';

    public const SOCIAL = 'social';

    public const OTHER = 'other';

    public const TYPES = [self::VIDEO, self::ARTICLE, self::PRODUCT, self::SOCIAL, self::OTHER];

    private const DOMAINS = [
        self::VIDEO => ['youtube.com', 'youtu.be', 'vimeo.com', 'tiktok.com'],
        self::SOCIAL => ['twitter.com', 'x.com', 'facebook.com', 'instagram.com', 'linkedin.com', 'reddit.com', 'threads.net'],
        self::PRODUCT => ['ebay.com', 'aliexpress.com'],
    ];

    public function fromHost(string $host): string
    {
        foreach (self::DOMAINS as $type => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $type;
                }
            }
        }

        // Amazon storefronts across country domains: amazon.com, amazon.co.uk, amazon.sa, ...
        if (preg_match('/(^|\.)amazon\.[a-z]{2,3}(\.[a-z]{2})?$/', $host)) {
            return self::PRODUCT;
        }

        return self::OTHER;
    }
}
