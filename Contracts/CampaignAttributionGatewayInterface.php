<?php

declare(strict_types=1);

namespace DbCms\Modules\Analytics\Contracts;

interface CampaignAttributionGatewayInterface
{
    /** @return array<string, mixed> */
    public function report(string $campaign, string $content, string $dateFrom, string $dateTo): array;
}
