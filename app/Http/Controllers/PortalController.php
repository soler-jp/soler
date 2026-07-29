<?php

// app/Http/Controllers/PortalController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        $unit = $user->selectedBusinessUnit;

        if ($unit === null) {
            return redirect()->route('initialize');
        }

        abort_unless($unit->canAccess($user), 403);

        if (! $unit->currentFiscalYear) {
            return redirect()->route('initialize');
        }

        $fiscalYear = $unit->currentFiscalYear;

        return view('dashboard', [
            'summary' => $fiscalYear->calculateSummary(),
        ]);
    }

    public function fixedExpenses(Request $request)
    {
        $user = $request->user();

        $unit = $user->selectedBusinessUnit;

        if ($unit === null) {
            return redirect()->route('initialize');
        }

        abort_unless($unit->canAccess($user), 403);

        if (! $unit->currentFiscalYear) {
            return redirect()->route('initialize');
        }

        $fiscalYear = $unit->currentFiscalYear;

        return view('fixed-expenses.index', [
            'unit' => $unit,
            'fiscalYear' => $fiscalYear,
        ]);
    }
}
