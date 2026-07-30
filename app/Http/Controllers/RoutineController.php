<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoutineController extends Controller
{
    public function index()
    {
        $upcomingDailyRoutines = Auth::user()->routines()
            ->where('frequency', 'daily')
            ->latest()
            ->get();

        $upcomingWeeklyRoutines = Auth::user()->routines()
            ->where('frequency', 'weekly')
            ->latest()
            ->get();

        $upcomingMonthlyRoutines = Auth::user()->routines()
            ->where('frequency', 'monthly')
            ->latest()
            ->get();

        return view('routines.index', compact('upcomingDailyRoutines', 'upcomingWeeklyRoutines', 'upcomingMonthlyRoutines'));
    }

    public function create()
    {
        return view('routines.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'frequency' => 'required|in:daily,weekly,monthly',
            'days' => 'nullable|array',
            'weeks' => 'nullable|array',
            'months' => 'nullable|array',
            'start_time' => 'required',
            'end_time' => 'required',
        ]);

        $routineData = $request->all();
        if ($request->has('days')) {
            $routineData['days'] = json_encode($request->days);
        }
        if ($request->has('weeks')) {
            $routineData['weeks'] = json_encode($request->weeks);
        }
        if ($request->has('months')) {
            $routineData['months'] = json_encode($request->months);
        }

        Auth::user()->routines()->create($routineData);

        return redirect()->route('routines.index')->with('success', 'Routine created successfully.');
    }

    public function edit(Routine $routine)
    {
        $this->authorizeOwner($routine);

        return view('routines.edit', compact('routine'));
    }

    public function update(Request $request, Routine $routine)
    {
        $this->authorizeOwner($routine);
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'frequency' => 'required|in:daily,weekly,monthly',
            'days' => 'nullable|array',
            'weeks' => 'nullable|array',
            'months' => 'nullable|array',
            'start_time' => 'required',
            'end_time' => 'required',
        ]);

        $routineData = $request->all();
        if ($request->has('days')) {
            $routineData['days'] = json_encode($request->days);
        }
        if ($request->has('weeks')) {
            $routineData['weeks'] = json_encode($request->weeks);
        }
        if ($request->has('months')) {
            $routineData['months'] = json_encode($request->months);
        }

        $routine->update($routineData);

        return redirect()->route('routines.index')->with('success', 'Routine updated successfully.');
    }

    public function destroy(Routine $routine)
    {
        $this->authorizeOwner($routine);
        $routine->delete();

        return redirect()->route('routines.index')->with('success', 'Routine deleted successfully.');
    }

    /**
     * Ensure the authenticated user owns the given routine.
     */
    private function authorizeOwner(Routine $routine): void
    {
        abort_unless($routine->user_id === Auth::id(), 403);
    }

    public function showAll()
    {
        $dailyRoutines = Auth::user()->routines()->where('frequency', 'daily')->get();
        $weeklyRoutines = Auth::user()->routines()->where('frequency', 'weekly')->get();
        $monthlyRoutines = Auth::user()->routines()->where('frequency', 'monthly')->get();

        return view('routines.all', compact('dailyRoutines', 'weeklyRoutines', 'monthlyRoutines'));
    }

    public function showDaily()
    {
        $dailyRoutines = Auth::user()->routines()->where('frequency', 'daily')->get();

        return view('routines.daily', compact('dailyRoutines'));
    }

    public function showWeekly()
    {
        $weeklyRoutines = Auth::user()->routines()->where('frequency', 'weekly')->get();

        return view('routines.weekly', compact('weeklyRoutines'));
    }

    public function showMonthly()
    {
        $monthlyRoutines = Auth::user()->routines()->where('frequency', 'monthly')->get();

        return view('routines.monthly', compact('monthlyRoutines'));
    }
}
