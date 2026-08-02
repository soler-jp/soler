<?php

// app/Http/Controllers/PortalController.php

namespace App\Http\Controllers;

use App\Services\TodoService;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function __invoke(Request $request, TodoService $todoService)
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
            'managementSummaryCards' => $fiscalYear->managementSummaryCards(),
            'pendingTodos' => $todoService->listPending($unit, $user, $fiscalYear),
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

    public function transactionIndex(Request $request, string $kind)
    {
        $user = $request->user();

        if (! $user->selectedBusinessUnit?->currentFiscalYear) {
            return redirect()->route('initialize');
        }

        return view('transactions.index', [
            'kind' => $kind,
        ]);
    }

    public function transactionJournal(Request $request)
    {
        $user = $request->user();

        if (! $user->selectedBusinessUnit?->currentFiscalYear) {
            return redirect()->route('initialize');
        }

        return view('transactions.journal');
    }

    public function accountSummary(Request $request)
    {
        $user = $request->user();

        if (! $user->selectedBusinessUnit?->currentFiscalYear) {
            return redirect()->route('initialize');
        }

        return view('accounts.summary');
    }

    public function fiscalYears(Request $request)
    {
        $user = $request->user();

        if (! $user->selectedBusinessUnit) {
            return redirect()->route('initialize');
        }

        return view('fiscal-years.index');
    }
}
