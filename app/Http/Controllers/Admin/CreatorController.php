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

    public function index(Request $request, SettingService $settings): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'verified' => ['nullable', 'in:0,1'],
        ]);

        $creators = CreatorProfile::query()
            ->with(['user', 'user.creatorWallet'])
            ->withCount(['apiKeys as active_keys_count' => fn ($q) => $q->where('status', 'active')])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('username', 'like', "%{$term}%")
                ->orWhere('shop_name', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%"))))
            ->when(isset($filters['verified']), fn ($q) => $q->where('verified', (bool) $filters['verified']))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $defaultFee = (float) $settings->get('platform_fee_percent');

        return view('admin.creators.index', compact('creators', 'filters', 'defaultFee'));
    }

    /** Cấp/thu hồi tick verified và đặt mức phí riêng (để trống = dùng mức mặc định). */
    public function update(Request $request, CreatorProfile $creator): RedirectResponse
    {
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
            'platform_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $before = $creator->only(['verified', 'platform_fee_percent']);
        $creator->update($data);
        $this->audit->log('admin.creator.update', $creator, ['before' => $before, 'after' => $data]);

        return back()->with('success', "Đã cập nhật Creator @{$creator->username}.");
    }
}
