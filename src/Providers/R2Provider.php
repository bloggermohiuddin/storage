<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * R2Provider — Cloudflare R2 storage provider.
 * Automatically overrides region to 'auto' and disables ACL headers.
 */
class R2Provider extends BaseS3Provider
{
    public function __construct(array $config)
    {
        // Enforce region = 'auto' for R2
        $config['region'] = 'auto';

        // Enforce no ACL headers for R2 as they are not supported
        $config['no_acl'] = true;

        // Auto-build endpoint if account_id is provided
        if (empty($config['endpoint']) && !empty($config['account_id'])) {
            $config['endpoint'] = sprintf('https://%s.r2.cloudflarestorage.com', $config['account_id']);
        }

        parent::__construct($config);
    }
}
