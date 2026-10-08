<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CategoryController extends Controller
{
    /** Các trường ghi vào nhật ký khi thay đổi. */
    private const FIELDS = ['name', 'slug', 'icon', 'sort_order', 'is_active'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $categories = Category::query()
            ->withCount('creatives')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.categories.index', compact('categories'));
    }

    public function create(): View
    {
        $category = new Category([
            'is_active' => true,
            'sort_order' => (int) Category::query()->max('sort_order') + 1,
        ]);

        return view('admin.categories.form', compact('category'));
    }

    public function store(Request $request): RedirectResponse
    {
        $category = Category::query()->create($this->validated($request));
        $this->audit->log('admin.category.create', $category, ['after' => $category->only(self::FIELDS)]);

        return redirect()->route('admin.categories.index')->with('success', "Đã thêm danh mục \"{$category->name}\".");
    }

    public function edit(Category $category): View
    {
        $category->loadCount('creatives');

        return view('admin.categories.form', compact('category'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $before = $category->only(self::FIELDS);
        $category->update($this->validated($request, $category));
        $this->audit->log('admin.category.update', $category, ['before' => $before, 'after' => $category->only(self::FIELDS)]);

        return redirect()->route('admin.categories.index')->with('success', "Đã cập nhật danh mục \"{$category->name}\".");
    }

    /** Bật/tắt nhanh: danh mục tắt bị ẩn khỏi API và Creator không chọn được khi tạo Creative. */
    public function toggle(Category $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);
        $this->audit->log('admin.category.toggle', $category, ['is_active' => $category->is_active]);

        $state = $category->is_active ? 'bật' : 'tắt';

        return back()->with('success', "Đã {$state} danh mục \"{$category->name}\".");
    }

    /** Chỉ xóa được danh mục chưa có Creative nào (khóa ngoại ai_creatives.category_id). */
    public function destroy(Category $category): RedirectResponse
    {
        if ($category->creatives()->exists()) {
            throw ValidationException::withMessages([
                'category' => "Danh mục \"{$category->name}\" đang có Creative, không thể xóa. Hãy tắt danh mục thay vì xóa.",
            ]);
        }

        $this->audit->log('admin.category.delete', $category, ['before' => $category->only(self::FIELDS)]);
        $category->delete();

        return redirect()->route('admin.categories.index')->with('success', "Đã xóa danh mục \"{$category->name}\".");
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        // Slug để trống thì tạo từ tên
        $request->merge([
            'slug' => Str::slug($request->input('slug') ?: $request->input('name', '')),
            'icon' => $request->filled('icon') ? Str::lower(trim($request->input('icon'))) : null,
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash:ascii', Rule::unique('categories', 'slug')->ignore($category)],
            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ], [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.max' => 'Tên danh mục tối đa 100 ký tự.',
            'slug.required' => 'Không tạo được slug từ tên, vui lòng nhập slug.',
            'slug.unique' => 'Slug này đã được dùng cho danh mục khác.',
            'slug.alpha_dash' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.max' => 'Slug tối đa 120 ký tự.',
            'icon.regex' => 'Tên icon chỉ gồm chữ thường, số và dấu gạch dưới (ví dụ: shopping_bag).',
            'icon.max' => 'Tên icon tối đa 50 ký tự.',
            'sort_order.*' => 'Thứ tự hiển thị phải là số nguyên từ 0 đến 65535.',
        ]);
    }
}
