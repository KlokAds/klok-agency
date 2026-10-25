<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FxRateHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_console_with_the_key_reads_the_dollar_rate(): void
    {
        config(['services.console.key' => 'console-key']);

        $this->getJson('/hooks/fx-rate')->assertNotFound();
        $this->getJson('/hooks/fx-rate', ['X-Console-Key' => 'wrong'])->assertNotFound();
        $this->getJson('/hooks/fx-rate', ['X-Console-Key' => 'console-key'])->assertOk()
            ->assertJsonStructure(['fx_rate', 'commission_percent', 'client_rate'])
            ->assertJsonPath('commission_percent', 20);

        config(['services.console.key' => null]);
        $this->getJson('/hooks/fx-rate', ['X-Console-Key' => ''])->assertNotFound();
    }
}
