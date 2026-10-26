<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\BdtEntry;
use App\Models\Employee;
use App\Services\Postings;
use App\Support\Lookups;
use App\Support\Money;
use App\Support\Paging;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'employee_id' => ['nullable', 'integer'],
        ]);
        $months = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'));
        $payments = BdtEntry::where('category', 'salary')->with('employee')->ledgerOrder()->get();

        Employee::syncTeam();
        $employees = Employee::orderBy('status')->orderBy('name')->get()->map(function ($employee) use ($payments, $months) {
            $mine = $payments->where('employee_id', $employee->id);
            $paid = -$mine->whereIn('salary_kind', ['salary', 'advance'])->sum('amount');
            $owed = $this->monthsOwed($employee, $mine) * $employee->monthly_salary;

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'designation' => $employee->designation,
                'phone' => $employee->phone,
                'monthly_salary' => $employee->monthly_salary,
                'joined_on' => $employee->joined_on?->toDateString(),
                'left_on' => $employee->left_on?->toDateString(),
                'status' => $employee->status,
                'notes' => $employee->notes,
                'paid' => $paid,
                'bonus' => -$mine->where('salary_kind', 'bonus')->sum('amount'),
                'due' => $owed - $paid,
                'months' => $months->mapWithKeys(fn ($m) => [$m => -$mine->where('salary_month', $m)->sum('amount')]),
            ];
        });

        $shown = $payments->reverse()
            ->when($filters['month'] ?? null, fn ($rows, $month) => $rows->where('salary_month', $month))
            ->when($filters['employee_id'] ?? null, fn ($rows, $id) => $rows->where('employee_id', (int) $id))
            ->values();
        $page = LengthAwarePaginator::resolveCurrentPage('payments_page');
        $history = new LengthAwarePaginator(
            $shown->forPage($page, Paging::size($request))->values(), $shown->count(), Paging::size($request), $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'payments_page'],
        );

        return Inertia::render('payroll/Index', [
            'employees' => $employees,
            'months' => $months,
            'payments' => $history->through(fn ($p) => [
                'id' => $p->id,
                'date' => $p->entry_date?->toDateString(),
                'employee' => $p->employee?->name,
                'employee_id' => $p->employee_id,
                'month' => $p->salary_month,
                'kind' => $p->salary_kind,
                'kind_label' => __(BdtEntry::SALARY_KINDS[$p->salary_kind] ?? 'বেতন'), // i18n
                'amount' => -$p->amount,
                'description' => $p->description,
                'notes' => $p->notes,
            ]),
            'filters' => [
                'month' => $filters['month'] ?? null,
                'employee_id' => isset($filters['employee_id']) ? (int) $filters['employee_id'] : null,
            ],
            'paid_months' => $payments->pluck('salary_month')->filter()->unique()->sortDesc()->values(),
            'totals' => [
                'monthly' => $employees->where('status', 'active')->sum('monthly_salary'),
                'due' => $employees->sum(fn ($e) => max(0, $e['due'])),
                'paid_this_month' => -$payments->where('salary_month', now()->format('Y-m'))->sum('amount'),
                'unassigned' => $payments->whereNull('employee_id')->count(),
            ],
            'options' => [
                'employees' => Lookups::employees(),
                'kinds' => Lookups::options(array_map(fn ($label) => __($label), BdtEntry::SALARY_KINDS)),
                'statuses' => Lookups::options(Employee::STATUSES),
            ],
        ]);
    }

    /**
     * Whole months the person has been on salary, up to this month.
     *
     * @param  Collection<int, BdtEntry>  $payments
     */
    private function monthsOwed(Employee $employee, Collection $payments): int
    {
        $first = $employee->joined_on ?? ($payments->min('salary_month') ? CarbonImmutable::parse($payments->min('salary_month').'-01') : null);

        if (! $first || $employee->monthly_salary <= 0) {
            return 0;
        }

        $end = $employee->left_on ?? now();

        return max(0, (int) $first->startOfMonth()->diffInMonths($end->startOfMonth()) + 1);
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        Employee::create($this->validatedEmployee($request));
        $this->toast(__('নতুন কর্মী যোগ হয়েছে।'));

        return back();
    }

    public function updateEmployee(Request $request, Employee $employee): RedirectResponse
    {
        $employee->update($this->validatedEmployee($request));
        $this->toast(__('কর্মীর তথ্য আপডেট হয়েছে।'));

        return back();
    }

    public function destroyEmployee(Employee $employee): RedirectResponse
    {
        if ($employee->payments()->exists()) {
            throw ValidationException::withMessages(['employee' => __('এই কর্মীর বেতন দেওয়া আছে — মোছা যাবে না। "Left" করে রাখুন।')]);
        }

        $employee->delete();
        $this->toast(__('কর্মী Trash-এ গেছে।'));

        return back();
    }

    public function pay(Request $request, Postings $postings): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'entry_date' => ['required', 'date'],
            'salary_month' => ['required', 'date_format:Y-m'],
            'kind' => ['required', Rule::in(array_keys(BdtEntry::SALARY_KINDS))],
            'amount' => ['required', Money::RULE],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['amount' => __('পরিমাণ'), 'entry_date' => __('তারিখ'), 'salary_month' => __('মাস'), 'employee_id' => __('কর্মী')]);

        $data['amount'] = Money::toMinor($data['amount']);
        abort_if($data['amount'] <= 0, 422);

        $postings->salary($data);
        $this->toast(__('বেতন দেওয়া লেখা হয়েছে — BDT খাতায় খরচ হিসেবে উঠেছে।'));

        return back();
    }

    /** Puts an imported salary row (no person on it) against an employee. */
    public function assign(Request $request, BdtEntry $payment): RedirectResponse
    {
        abort_unless($payment->category === 'salary', 404);

        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'salary_month' => ['required', 'date_format:Y-m'],
            'kind' => ['required', Rule::in(array_keys(BdtEntry::SALARY_KINDS))],
        ]);

        $payment->update([
            'employee_id' => $data['employee_id'],
            'salary_month' => $data['salary_month'],
            'salary_kind' => $data['kind'],
        ]);

        $this->toast(__('বেতনের row কর্মীর নামে বসানো হয়েছে।'));

        return back();
    }

    public function destroyPayment(BdtEntry $payment): RedirectResponse
    {
        abort_unless($payment->category === 'salary', 404);

        $payment->delete();
        $this->toast(__('বেতনের row Trash-এ গেছে।'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validatedEmployee(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'monthly_salary' => ['required', Money::RULE],
            'joined_on' => ['nullable', 'date'],
            'left_on' => ['nullable', 'date', 'after_or_equal:joined_on'],
            'status' => ['required', Rule::in(array_keys(Employee::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => __('নাম'), 'monthly_salary' => __('মাসিক বেতন')]);

        $data['monthly_salary'] = Money::toMinor($data['monthly_salary']);

        return $data;
    }
}
