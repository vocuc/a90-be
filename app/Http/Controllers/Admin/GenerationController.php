<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GenerationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(GenerationStatus::class)],
            'type' => ['nullable', Rule::enum(GenerationType::class)],
        ]);

        $generations = Generation::query()
            ->with(['user', 'creative'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('id', $term)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%"))
                ->orWhereHas('creative', fn ($c) => $c->where('title', 'like', "%{$term}%"))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.generations.index', compact('generations', 'filters'));
    }

    public function show(Generation $generation): View
    {
        $generation->load(['user', 'creator.creatorProfile', 'creative', 'model', 'outputs.transaction', 'outputs.worker']);
        $generation->makeVisible('prompt_snapshot');

        return view('admin.generations.show', compact('generation'));
    }
}
