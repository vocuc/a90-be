<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CreativeStatus;
use App\Http\Controllers\Controller;
use App\Models\AiCreative;
use App\Models\Transaction;
use App\Services\CreativeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CreativeController extends Controller
{
    public function __construct(private CreativeService $creatives) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(CreativeStatus::class)],
            'hidden' => ['nullable', 'in:0,1'],
        ]);

        $creatives = AiCreative::query()
            ->with(['creator.creatorProfile', 'category', 'model', 'images'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%")
                ->orWhere('id', $term)))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(isset($filters['hidden']), fn ($q) => $filters['hidden'] ? $q->whereNotNull('hidden_at') : $q->whereNull('hidden_at'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.creatives.index', compact('creatives', 'filters'));
    }

    public function show(AiCreative $creative): View
    {
        $creative->load(['creator.creatorProfile', 'category', 'model.provider', 'images', 'config', 'hashtags']);

        $stats = Transaction::query()
            ->where('creative_id', $creative->id)
            ->where('status', 'completed')
            ->selectRaw('count(*) as images, coalesce(sum(amount),0) as gmv, coalesce(sum(platform_fee_amount),0) as fee, coalesce(sum(creator_amount),0) as creator_amount')
            ->toBase()
            ->first();

        $recentGenerations = $creative->generations()->with('user')->latest('id')->limit(10)->get();

        return view('admin.creatives.show', compact('creative', 'stats', 'recentGenerations'));
    }

    public function hide(Request $request, AiCreative $creative): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->creatives->hide($creative, $data['reason'] ?? null);

        return back()->with('success', 'Đã ẩn AI Creative khỏi Marketplace.');
    }

    public function unhide(AiCreative $creative): RedirectResponse
    {
        $this->creatives->unhide($creative);

        return back()->with('success', 'Đã hiện lại AI Creative trên Marketplace.');
    }
}
