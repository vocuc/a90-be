<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    /** Các cấu hình admin được sửa: key trong bảng settings => [tên trường form, nhãn, gợi ý, rule]. */
    private const FIELDS = [
        'creator_share_percent' => ['creator_share_percent', 'Creator nhận mặc định (% giá mỗi ảnh)', 'Áp dụng cho Creator chưa có mức riêng. Phần còn lại là phí nền tảng.', ['required', 'numeric', 'min:0', 'max:100']],
        'revenue_hold_days' => ['revenue_hold_days', 'Số ngày giữ doanh thu', 'Doanh thu chuyển từ pending sang available sau số ngày này.', ['required', 'integer', 'min:0', 'max:90']],
        'generation.default_max_outputs' => ['default_max_outputs', 'Số ảnh tối đa mặc định / lần tạo', 'Giá trị mặc định khi Creator tạo Creative mới.', ['required', 'integer', 'min:1', 'max:10']],
        'generation.max_outputs_limit' => ['max_outputs_limit', 'Giới hạn số ảnh / lần tạo', 'Creator không được cấu hình vượt quá số này (tối đa 10).', ['required', 'integer', 'min:1', 'max:10']],
        'generation.input_max_size_kb' => ['input_max_size_kb', 'Dung lượng ảnh đầu vào tối đa (KB)', '10240 KB = 10 MB.', ['required', 'integer', 'min:512', 'max:20480']],
    ];

    public function __construct(private SettingService $settings) {}

    public function edit(): View
    {
        $fields = collect(self::FIELDS)->map(fn ($field, $key) => [
            'name' => $field[0],
            'label' => $field[1],
            'help' => $field[2],
            'value' => $this->settings->get($key),
        ]);

        return view('admin.settings.edit', compact('fields'));
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $rules = collect(self::FIELDS)->mapWithKeys(fn ($field) => [$field[0] => $field[3]])->all();
        $data = $request->validate($rules, [], collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f[0] => $f[1]])->all());

        if ($data['default_max_outputs'] > $data['max_outputs_limit']) {
            return back()->withErrors(['default_max_outputs' => 'Số ảnh mặc định không được lớn hơn giới hạn.'])->withInput();
        }

        $changes = [];
        foreach (self::FIELDS as $key => $field) {
            $old = (string) $this->settings->get($key);
            $new = (string) $data[$field[0]];
            if ($old !== $new) {
                $this->settings->set($key, $new);
                $changes[$key] = ['from' => $old, 'to' => $new];
            }
        }

        if ($changes) {
            $audit->log('admin.settings.update', null, $changes);
        }

        return back()->with('success', $changes ? 'Đã lưu cấu hình.' : 'Không có thay đổi.');
    }
}
