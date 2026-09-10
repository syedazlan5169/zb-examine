<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Examination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AgentExaminationHistoryController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(auth()->user()->role === UserRole::Agent, 403);

        $examinations = Examination::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->appends($request->query());

        return view('examinations.agent.index', [
            'examinations' => $examinations,
        ]);
    }

    public function show(Request $request, string $id): View
    {
        abort_unless(auth()->user()->role === UserRole::Agent, 403);

        $examination = Examination::query()
            ->where('user_id', auth()->id())
            ->findOrFail($id);

        Gate::authorize('viewOwn', $examination);

        $examination->loadMissing(['customsFormNumbers', 'photos']);

        return view('examinations.agent.show', [
            'examination' => $examination,
        ]);
    }
}
