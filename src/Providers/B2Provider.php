<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * B2Provider — Backblaze B2 storage provider.
 */
class B2Provider extends BaseS3Provider
{
    public function __construct(array $config)
    {
        // Enforce path style endpoints
        $config['use_path_style_endpoint'] = true;

        // Auto-build endpoint if only region is provided
        if (empty($config['endpoint']) && !empty($config['region'])) {
            $config['endpoint'] = sprintf('https://s3.%s.backblazeb2.com', $config['region']);
        }

        parent::__construct($config);
    }
}
