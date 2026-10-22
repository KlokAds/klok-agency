<?php

namespace App\Console\Commands;

use App\Services\Ticker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agency:tick')]
#[Description('Send due reminders, close ended ad runs and send the daily summary')]
class AgencyTick extends Command
{
    public function handle(Ticker $ticker): int
    {
        $r = $ticker->run();
        $this->info("ended {$r['ended']} · reminders {$r['reminders']} · summary ".($r['summary'] ? 'sent' : '—'));

        return self::SUCCESS;
    }
}
