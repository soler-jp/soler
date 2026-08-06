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
        $previousFiscalYear = $unit->fiscalYears()
            ->where('year', $fiscalYear->year - 1)
            ->first();

        $shouldPromptNextFiscalYear = now()->year !== $fiscalYear->year
            && ! $unit->fiscalYears()->where('year', $fiscalYear->year + 1)->exists();
        $shouldPromptPreviousFiscalYearRollover = $previousFiscalYear !== null
            && $previousFiscalYear->rollover_at === null;

        return view('dashboard', [
            'selectedBusinessUnit' => $unit,
            'pendingTodos' => $todoService->listPending($unit, $user, $fiscalYear),
            'shouldPromptNextFiscalYear' => $shouldPromptNextFiscalYear,
            'shouldPromptPreviousFiscalYearRollover' => $shouldPromptPreviousFiscalYearRollover,
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

    public function fixedAssets(Request $request)
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

        return view('fixed-assets.index');
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
