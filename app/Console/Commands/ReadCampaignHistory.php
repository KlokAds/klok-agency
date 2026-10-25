<?php

namespace App\Console\Commands;

use App\Services\CampaignBilling;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Reads every linked campaign's whole life from Pilot again, from its start, so clients see each
 * campaign's full spend and results. Billing is untouched: it counts only from bill_from.
 */
#[Signature('campaigns:read-history')]
#[Description("Read each linked campaign's daily spend and results from its start (for the client portal)")]
class ReadCampaignHistory extends Command
{
    public function handle(CampaignBilling $billing): int
    {
        try {
            $this->info($billing->readHistory().' campaign days read.');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
