<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Services\AuditLogger;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreatorController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'verified' => ['nullable', 'in:0,1'],
        ]);

        $creators = CreatorProfile::query()
            ->with(['user'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('username', 'like', "%{$term}%")
                ->orWhere('shop_name', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%"))))
            ->when(isset($filters['verified']), fn ($q) => $q->where('verified', (bool) $filters['verified']))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.creators.index', compact('creators', 'filters'));
    }

    /** Cấp/thu hồi tick verified. */
    public function update(Request $request, CreatorProfile $creator): RedirectResponse
    {
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        $before = $creator->only(['verified']);
        $creator->update($data);
        $this->audit->log('admin.creator.update', $creator, ['before' => $before, 'after' => $data]);

        return back()->with('success', "Đã cập nhật Creator @{$creator->username}.");
    }
}
