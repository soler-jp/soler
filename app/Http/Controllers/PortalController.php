<?php

// app/Http/Controllers/PortalController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if (! $user->selectedBusinessUnit?->currentFiscalYear) {
            return redirect()->route('initialize');
        }


        $unit = auth()->user()->selectedBusinessUnit;
        $fiscalYear = $unit->currentFiscalYear;

        return view('dashboard', [
            'summary' => $fiscalYear->calculateSummary(),
        ]);
    }
}
