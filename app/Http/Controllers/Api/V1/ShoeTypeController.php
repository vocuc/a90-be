<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ProductTags;
use Illuminate\Http\JsonResponse;

/**
 * Danh sách hashtag LOẠI GIÀY dùng chung A51 + OOTD (ProductTags::SHOE_TYPES, người dùng chốt 2026-11-24). Công khai: skill
 * /tao-outfit đọc để gắn hashtag giày cho outfit OOTD, skill /gan-tag-san-pham + frontend dùng cho tag kieu của sản phẩm.
 * GET /api/v1/shoe-types -> { data: [{ tag, ten, nhom, cung_nghia }] }
 */
class ShoeTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ProductTags::shoeTypes()]);
    }
}
