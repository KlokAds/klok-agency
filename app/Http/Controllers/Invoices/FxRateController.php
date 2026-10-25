<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Services\Invoices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agency's dollar rate for the owner's console (MH-Bot), which bills his own clients' ads at
 * the same rate: BDT per USD of the FX batch now being spent, the commission, and the two together.
 * Read-only, behind a shared key (CONSOLE_KEY); anyone else sees nothing here.
 */
class FxRateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) config('services.console.key');
        abort_unless($key !== '' && hash_equals($key, (string) $request->header('X-Console-Key')), 404);

        return response()->json([
            'fx_rate' => Invoices::fxRate(),
            'commission_percent' => Invoices::commissionPercent(),
            'client_rate' => Invoices::clientRate(),
        ]);
    }
}
