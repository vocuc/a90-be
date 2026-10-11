<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\AuditLogger;
use App\Services\MediaStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BannerController extends Controller
{
    /** Các trường ghi vào nhật ký khi thay đổi. */
    private const FIELDS = ['title', 'image_path', 'link_url', 'sort_order', 'is_active'];

    /** Cạnh dài tối đa (px) khi lưu ảnh banner. */
    private const IMAGE_MAX_SIDE = 1920;

    public function __construct(private AuditLogger $audit, private MediaStorage $media) {}

    public function index(): View
    {
        $banners = Banner::query()->orderBy('sort_order')->orderByDesc('id')->get();

        return view('admin.banners.index', compact('banners'));
    }

    public function create(): View
    {
        $banner = new Banner([
            'is_active' => true,
            'sort_order' => (int) Banner::query()->max('sort_order') + 1,
        ]);

        return view('admin.banners.form', compact('banner'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, required: true);
        $data['image_path'] = $this->storeImage($request);
        unset($data['image']);

        $banner = Banner::query()->create($data);
        $this->audit->log('admin.banner.create', $banner, ['after' => $banner->only(self::FIELDS)]);

        return redirect()->route('admin.banners.index')->with('success', 'Đã thêm banner.');
    }

    public function edit(Banner $banner): View
    {
        return view('admin.banners.form', compact('banner'));
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $before = $banner->only(self::FIELDS);
        $data = $this->validated($request, required: false);
        unset($data['image']);

        // Có ảnh mới thì thay, xoá ảnh cũ sau khi lưu xong
        $oldImage = null;
        if ($request->hasFile('image')) {
            $oldImage = $banner->image_path;
            $data['image_path'] = $this->storeImage($request);
        }

        $banner->update($data);
        $this->media->delete($oldImage);
        $this->audit->log('admin.banner.update', $banner, ['before' => $before, 'after' => $banner->only(self::FIELDS)]);

        return redirect()->route('admin.banners.index')->with('success', 'Đã cập nhật banner.');
    }

    /** Bật/tắt nhanh: banner tắt bị ẩn khỏi trang chủ app. */
    public function toggle(Banner $banner): RedirectResponse
    {
        $banner->update(['is_active' => ! $banner->is_active]);
        $this->audit->log('admin.banner.toggle', $banner, ['is_active' => $banner->is_active]);

        return back()->with('success', $banner->is_active ? 'Đã bật banner.' : 'Đã tắt banner.');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $this->audit->log('admin.banner.delete', $banner, ['before' => $banner->only(self::FIELDS)]);
        $banner->delete();
        $this->media->delete($banner->image_path);

        return redirect()->route('admin.banners.index')->with('success', 'Đã xóa banner.');
    }

    /** Lưu ảnh đã tối ưu; ảnh hỏng/quá lớn hiện thành lỗi của trường ảnh trên form. */
    private function storeImage(Request $request): string
    {
        try {
            return $this->media->storeOptimizedImage($request->file('image'), 'banners', [self::IMAGE_MAX_SIDE])[0];
        } catch (BusinessException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }
    }

    private function validated(Request $request, bool $required): array
    {
        $request->merge(['link_url' => trim((string) $request->input('link_url'))]);

        return $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'image' => [$required ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // Link ngoài (http/https) hoặc đường dẫn trong app (bắt đầu bằng /)
            'link_url' => ['required', 'string', 'max:500', 'regex:#^(https?://\S+|/\S*)$#i'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ], [
            'image.required' => 'Vui lòng chọn ảnh banner.',
            'image.image' => 'Tệp tải lên phải là ảnh.',
            'image.mimes' => 'Chỉ nhận ảnh JPG, PNG hoặc WEBP.',
            'image.max' => 'Ảnh tối đa 5MB.',
            'image.uploaded' => 'Tải ảnh lên thất bại (có thể ảnh vượt giới hạn upload của server).',
            'link_url.required' => 'Vui lòng nhập link.',
            'link_url.max' => 'Link tối đa 500 ký tự.',
            'link_url.regex' => 'Link phải bắt đầu bằng http://, https:// hoặc / (đường dẫn trong app, vd: /explore).',
            'title.max' => 'Tên banner tối đa 100 ký tự.',
            'sort_order.*' => 'Thứ tự hiển thị phải là số nguyên từ 0 đến 65535.',
        ]);
    }
}
