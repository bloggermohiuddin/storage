<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * MinIOProvider — MinIO storage provider.
 */
class MinIOProvider extends BaseS3Provider
{
    public function __construct(array $config)
    {
        // MinIO requires path-style endpoints
        $config['use_path_style_endpoint'] = true;

        // Default region is us-east-1 if empty
        if (empty($config['region'])) {
            $config['region'] = 'us-east-1';
        }

        parent::__construct($config);
    }
}
