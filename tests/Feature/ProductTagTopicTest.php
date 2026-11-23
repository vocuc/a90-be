<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelTopic;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Worker;
use App\Services\Channel\ChannelPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Tag sản phẩm AI + sản phẩm lọc theo tag + chủ đề kênh (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt). */
class ProductTagTopicTest extends TestCase
{
    private string $planToken;

    private User $shop;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai90.post_samples_enabled' => ['*']]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->planToken = $admin->createToken('plan:test', ['channel-plan'])->plainTextToken;
        $this->shop = $this->creator();
        [$worker] = Worker::register('dang-'.Str::random(4), Worker::TYPE_PUBLISH);
        $worker->users()->attach($this->shop->id);
        [$content] = Worker::register('dung-'.Str::random(4), Worker::TYPE_CONTENT);
        $content->users()->attach($this->shop->id);
        $this->channel = $this->channelFor($this->shop, $worker, [
            'persona' => ['age_range' => '18-25'], 'plan_days' => 2, 'posts_per_day' => 2, 'auto_approve_plan' => true,
            'time_slots' => [['start' => '11:00', 'end' => '13:00'], ['start' => '19:00', 'end' => '22:00']],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00'));
    }

    private function api(string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->planToken)->json($method, "/api/v1/channel-plan/{$uri}", $body);
    }

    private function product(string $name = 'Giày'): Product
    {
        $product = Product::query()->create(['user_id' => $this->shop->id, 'name' => $name]);
        // Ảnh bìa (vị trí 1) khác trường anh_san_pham: skill chỉ xem ảnh anh_san_pham
        ProductImage::query()->create(['product_id' => $product->id, 'path' => "products/{$product->id}-cover.png", 'field_key' => 'model_image', 'position' => 1]);
        ProductImage::query()->create(['product_id' => $product->id, 'path' => "products/{$product->id}.png", 'field_key' => 'anh_san_pham', 'position' => 2]);

        return $product;
    }

    /** $count ảnh tạo xong cho sản phẩm: refs. */
    private function images(?Product $product, int $count, string $kind = Generation::KIND_CREATIVE): array
    {
        $response = $this->generate($this->shop, $this->publishedCreative($this->creator()), $count,
            $product ? ['product_id' => $product->id] : [])->assertStatus(202);
        $this->runWorker(array_fill(0, $count, null));
        $generation = Generation::query()->with('outputs')->findOrFail($response->json('data.id'));
        $generation->update(['kind' => $kind]);

        return $generation->outputs->map(fn (GenerationOutput $o) => ChannelPlanService::ref($generation->id, $o->id))->all();
    }

    private function tags(array $extra = []): array
    {
        return ['kieu' => 'cao_got', 'got' => 'vua', 'mau' => ['Đỏ'], 'phong_cach' => ['sang_chanh'], 'dip' => ['tet', 'di_tiec'], ...$extra];
    }

    private function tagRow(Product $p, array $tags = [], string $label = 'Cao gót mũi nhọn đỏ'): array
    {
        return ['id' => $p->id, 'image_id' => $p->images()->where('field_key', 'anh_san_pham')->value('id'), 'ai_label' => $label, 'tags' => $this->tags($tags)];
    }

    private function approveLineup(array $keys): void
    {
        $this->channel->lineups()->create(['status' => 'approved', 'approved_at' => now(),
            'items' => array_map(fn ($k) => ['sample_key' => $k, 'percent' => intdiv(100, count($keys)), 'reason' => null], $keys)]);
        $this->channel->textTemplates()->create(['name' => 'Ô trắng', 'status' => 'approved', 'sample_keys' => $keys, 'style' => [
            'position' => 'top', 'font' => 'Be Vietnam Pro', 'weight' => 800, 'size' => 6, 'color' => '#111111', 'stroke' => null,
            'background' => 'box', 'box_color' => '#ffffff', 'box_radius' => 8, 'align' => 'center', 'max_lines' => 3]]);
    }

    private function unboxing(int $slot, array $refs, array $topic): array
    {
        return ['slot_index' => $slot, 'sample_key' => 'closeup', ...$topic, 'hook' => "Đập hộp đi chúc Tết {$slot}",
            'images' => [['ref' => $refs[0], 'text' => 'Đập hộp'], ['ref' => $refs[1]]], 'title' => 'Đập hộp', 'hashtags' => []];
    }

    // ---- Tag sản phẩm ----

    public function test_tag_skill_saves_ai_tags_and_owner_edits_win(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');
        Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Không ảnh']); // không có ảnh -> không cần gắn
        $noField = Product::query()->create(['user_id' => $this->shop->id, 'name' => 'Không có anh_san_pham']);
        ProductImage::query()->create(['product_id' => $noField->id, 'path' => 'products/x.png', 'field_key' => 'product_image', 'position' => 1]);

        $list = $this->api('GET', "shops/{$this->shop->id}/products/untagged")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($list, 'id'));
        $this->assertSame($a->images()->where('field_key', 'anh_san_pham')->value('id'), collect($list)->firstWhere('id', $a->id)['cover']['image_id']);

        $result = $this->api('PUT', "shops/{$this->shop->id}/products/tags", ['items' => [
            $this->tagRow($a),
            $this->tagRow($b, ['kieu' => 'giay_tay', 'dip' => ['tet', 'di_lam', 'cafe', 'hen_ho', 'di_choi']]),
            ['id' => 999999, 'image_id' => 1, 'ai_label' => 'x', 'tags' => $this->tags()],
        ]])->assertOk()->json('data');
        $this->assertSame([$a->id], $result['updated']);
        $this->assertCount(2, $result['errors']);
        $this->assertEquals(['kieu' => 'cao_got', 'got' => 'vua', 'mau' => ['do'], 'phong_cach' => ['sang_chanh'], 'dip' => ['tet', 'di_tiec']], $a->fresh()->tags);
        $this->assertSame([$b->id], array_column($this->api('GET', "shops/{$this->shop->id}/products/untagged")->json('data'), 'id'));

        // Chủ shop sửa kiểu + nhãn -> AI gắn lại không ghi đè 2 trường đó
        $this->actingAs($this->shop)->putJson("/api/v1/creator/products/{$a->id}/tags", ['ai_label' => 'Slingback đỏ', 'tags' => ['kieu' => 'slingback']])
            ->assertOk()->assertJsonPath('data.tags.kieu', 'slingback')->assertJsonPath('data.tag_sources.kieu', 'owner');
        $this->actingAs($this->shop)->putJson("/api/v1/creator/products/{$a->id}/tags", ['tags' => ['kieu' => 'xyz']])->assertStatus(422);
        $again = $this->api('PUT', "shops/{$this->shop->id}/products/tags", ['items' => [$this->tagRow($a, ['got' => 'cao'], 'Cao gót')]])->json('data');
        $this->assertEqualsCanonicalizing(['ai_label', 'kieu'], $again['skipped'][$a->id]);
        $fresh = $a->fresh();
        $this->assertSame('Slingback đỏ', $fresh->ai_label);
        $this->assertSame('slingback', $fresh->tags['kieu']);
        $this->assertSame('cao', $fresh->tags['got']);

        // Đổi ảnh bìa khác trường -> không cần gắn lại; thay ảnh anh_san_pham -> cần gắn lại
        ProductImage::query()->create(['product_id' => $a->id, 'path' => 'products/new-cover.png', 'field_key' => 'cover_2', 'position' => 0]);
        $this->assertNotContains($a->id, array_column($this->api('GET', "shops/{$this->shop->id}/products/untagged")->json('data'), 'id'));
        ProductImage::query()->where('product_id', $a->id)->where('field_key', 'anh_san_pham')->delete();
        ProductImage::query()->create(['product_id' => $a->id, 'path' => 'products/new.png', 'field_key' => 'anh_san_pham', 'position' => 2]);
        $this->assertContains($a->id, array_column($this->api('GET', "shops/{$this->shop->id}/products/untagged")->json('data'), 'id'));
    }

    public function test_products_filtered_by_tags_with_one_recent_ref(): void
    {
        $red = $this->product('Đỏ');
        $flat = $this->product('Bệt');
        $noImage = $this->product('Chưa tạo ảnh');
        $untagged = $this->product('Chưa gắn tag');
        $this->api('PUT', "shops/{$this->shop->id}/products/tags", ['items' => [
            $this->tagRow($red),
            $this->tagRow($flat, ['kieu' => 'bup_be', 'got' => 'bet', 'dip' => ['di_lam']], 'Búp bê nơ'),
            $this->tagRow($noImage),
        ]])->assertOk();
        $redRefs = $this->images($red, 3);
        $this->images($red, 2, Generation::KIND_OUTFIT); // ảnh phối đồ không tính
        $this->images($flat, 1);
        $this->images($untagged, 1);

        $all = $this->api('GET', "channels/{$this->channel->id}/products")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$red->id, $flat->id], array_column($all['products'], 'id'));
        $this->assertSame(1, $all['untagged']);
        $row = collect($all['products'])->firstWhere('id', $red->id);
        $this->assertContains($row['ref'], $redRefs);
        $this->assertSame('Cao gót mũi nhọn đỏ', $row['label']);
        $this->assertArrayNotHasKey('thumb_url', $row);

        $tet = $this->api('GET', "channels/{$this->channel->id}/products?dip=tet,cafe")->json('data.products');
        $this->assertSame([$red->id], array_column($tet, 'id'));
        $work = $this->api('GET', "channels/{$this->channel->id}/products?dip=di_lam&got=bet,thap")->json('data.products');
        $this->assertSame([$flat->id], array_column($work, 'id'));
        $this->assertSame([], $this->api('GET', "channels/{$this->channel->id}/products?dip=tet&got=bet")->json('data.products'));
        $this->api('GET', "channels/{$this->channel->id}/products?dip=khong_co")->assertStatus(422);
    }

    // ---- Chủ đề ----

    public function test_every_item_needs_topic_and_topic_repeat_is_blocked_for_14_days(): void
    {
        $this->approveLineup(['closeup', 'this_or_that']);
        $refs = $this->images(null, 4);
        $context = $this->api('GET', "channels/{$this->channel->id}/context")->assertOk()->json('data');
        $this->assertArrayNotHasKey('images', $context);
        $this->assertArrayNotHasKey('products', $context);
        $this->assertSame([], $context['topics']);
        $planId = $context['plan']['id'];

        // Chủ shop xây dựng chủ đề thủ công (lệnh chỉ dùng chủ đề có sẵn)
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/topics", ['name' => '  Đi chúc Tết ', 'note' => ' Sang mà đi cả ngày được ', 'tags' => ['dip' => ['tet']]])
            ->assertCreated()->assertJsonPath('data.name', 'Đi chúc Tết')->assertJsonPath('data.source', 'owner')
            ->assertJsonPath('data.note', 'Sang mà đi cả ngày được')->assertJsonMissingPath('data.tags');
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/topics", ['name' => 'đi chúc tết'])->assertStatus(422);
        $this->actingAs($this->shop)->postJson("/api/v1/creator/channels/{$this->channel->id}/topics", ['name' => 'Ghi chú dài', 'note' => str_repeat('a', 1001)])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $topic = ChannelTopic::query()->sole();
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channels/{$this->channel->id}/topics/{$topic->id}", ['note' => '  '])
            ->assertOk()->assertJsonPath('data.note', null);

        $byName = ['topic' => ['name' => 'Đi chúc Tết']];
        $res = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->unboxing(0, $refs, []),                           // thiếu chủ đề
            $this->unboxing(1, $refs, ['topic_id' => $topic->id]),
            $this->unboxing(2, [$refs[2], $refs[3]], $byName),        // cùng chủ đề + cùng loại bài -> lặp
            [...$this->unboxing(3, [$refs[2], $refs[3]], $byName), 'sample_key' => 'this_or_that', 'hook' => 'Đi chúc Tết chọn A hay B?'],
            $this->unboxing(0, [$refs[1], $refs[0]], ['topic' => ['name' => 'Chủ đề AI tự nghĩ', 'tags' => ['dip' => ['tet']]]]),
        ]])->assertOk()->json('data');
        $this->assertSame([1, 3], $res['accepted']);
        $errors = collect($res['rejected'])->pluck('errors')->map(fn ($e) => implode(' ', $e))->all();
        $this->assertStringContainsString('Bài cần chủ đề', $errors[0]);
        $this->assertStringContainsString('TOPIC_REPEATED', $errors[1]);
        $this->assertStringContainsString('TOPIC_NOT_FOUND', $errors[2]);
        $this->assertSame(1, ChannelTopic::query()->count(), 'Lệnh không tự tạo chủ đề');

        // Kỳ được tự duyệt -> bài đăng có topic_id; context đếm bài theo loại bài
        $this->assertSame(2, SocialPost::query()->where('topic_id', $topic->id)->count());
        $topics = $this->api('GET', "channels/{$this->channel->id}/context")->json('data.topics');
        $this->assertSame(['closeup' => 1, 'this_or_that' => 1], $topics[0]['posts']);
        // Tab Chủ đề: hook đã viết theo chủ đề (bài đăng), mới trước; context lệnh không có hooks (tối ưu token)
        $this->assertArrayNotHasKey('hooks', $topics[0]);
        $tab = $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/topics")->assertOk()->json('data.topics');
        $withHooks = collect($tab)->firstWhere('id', $topics[0]['id']);
        $this->assertCount(2, $withHooks['hooks']);
        $this->assertSame('post', $withHooks['hooks'][0]['kind']);
        $this->assertNotEmpty($withHooks['hooks'][0]['hook']);

        // Chủ shop xem / sửa / tạm tắt chủ đề
        $this->actingAs($this->shop)->getJson("/api/v1/creator/channels/{$this->channel->id}/topics")->assertOk()
            ->assertJsonPath('data.topics.0.name', 'Đi chúc Tết')->assertJsonPath('data.topics.0.note', null)
            ->assertJsonMissingPath('data.tag_options');
        $this->actingAs($this->shop)->putJson("/api/v1/creator/channels/{$this->channel->id}/topics/{$topic->id}", ['status' => 'paused', 'note' => 'Ưu tiên cao gót vừa, màu đỏ / kem'])
            ->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.source', 'owner');
        $this->assertSame('Ưu tiên cao gót vừa, màu đỏ / kem', $topic->fresh()->note);
        $this->assertSame([], $this->api('GET', "channels/{$this->channel->id}/context")->json('data.topics'));
    }

    public function test_delete_topic_with_posts_leaves_posts_without_topic(): void
    {
        $this->channel->update(['auto_approve_plan' => false]);
        $this->approveLineup(['closeup']);
        $refs = $this->images(null, 4);
        $topic = $this->channel->topics()->create(['name' => 'Đi làm', 'source' => 'ai', 'status' => 'active']);
        $other = $this->channel->topics()->create(['name' => 'Đi tiệc', 'source' => 'ai', 'status' => 'active']);
        $old = SocialPost::query()->create([
            'user_id' => $this->shop->id, 'channel_id' => $this->channel->id, 'sample_key' => 'closeup', 'topic_id' => $topic->id,
            'hook' => 'cũ', 'type' => 'photo', 'status' => 'published', 'source' => SocialPost::SOURCE_PLAN, 'scheduled_at' => now()->subDays(20),
            'title' => 'cũ', 'content' => '', 'hashtags' => [], 'platforms' => ['tiktok'], 'idempotency_key' => 'old-del',
        ]);
        $planId = $this->api('GET', "channels/{$this->channel->id}/context")->json('data.plan.id');
        $this->assertSame([0], $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->unboxing(0, $refs, ['topic_id' => $topic->id]),
        ]])->json('data.accepted'));
        $base = "/api/v1/creator/channels/{$this->channel->id}";
        $item = $this->actingAs($this->shop)->getJson("{$base}/plans/{$planId}")->assertOk()->json('data.items.0');
        $this->assertSame('Đi làm', $item['topic']);

        // Kênh của shop khác -> 404
        $this->actingAs($this->creator())->deleteJson("{$base}/topics/{$topic->id}")->assertNotFound();

        $this->actingAs($this->shop)->deleteJson("{$base}/topics/{$topic->id}")->assertNoContent();
        $this->assertNull(ChannelTopic::query()->find($topic->id));
        $this->assertNotNull($other->fresh());
        // Bài đăng / bài kế hoạch giữ nguyên, chủ đề không xác định
        $this->assertNull($old->fresh()->topic_id);
        $this->actingAs($this->shop)->getJson("{$base}/plans/{$planId}")->assertOk()->assertJsonPath('data.items.0.topic', null);

        // Chủ shop sửa bài kế hoạch không chọn chủ đề: vẫn lưu được, chủ đề vẫn không xác định
        $this->actingAs($this->shop)->putJson("{$base}/plans/{$planId}/items/{$item['id']}", [
            'sample_key' => 'closeup', 'hook' => 'Hook mới', 'title' => 'Tiêu đề mới', 'hashtags' => [],
            'images' => [['ref' => $refs[0], 'text' => 'Đập hộp'], ['ref' => $refs[1]]],
        ])->assertOk()->assertJsonPath('data.topic', null)->assertJsonPath('data.hook', 'Hook mới');

        // Duyệt kỳ: bài đăng tạo ra không có chủ đề
        $this->actingAs($this->shop)->postJson("{$base}/plans/{$planId}/approve")->assertOk();
        $this->assertNull(SocialPost::query()->where('plan_item_id', $item['id'])->sole()->topic_id);
    }

    public function test_topic_can_repeat_after_14_days(): void
    {
        $this->approveLineup(['closeup']);
        $refs = $this->images(null, 4);
        $topic = $this->channel->topics()->create(['name' => 'Đi làm', 'source' => 'ai', 'status' => 'active']);
        $old = SocialPost::query()->create([
            'user_id' => $this->shop->id, 'channel_id' => $this->channel->id, 'sample_key' => 'closeup', 'topic_id' => $topic->id,
            'hook' => 'cũ', 'type' => 'photo', 'status' => 'published', 'source' => SocialPost::SOURCE_PLAN, 'scheduled_at' => now()->subDays(20),
            'title' => 'cũ', 'content' => '', 'hashtags' => [], 'platforms' => ['tiktok'], 'idempotency_key' => 'old-1',
        ]);
        $planId = $this->api('GET', "channels/{$this->channel->id}/context")->json('data.plan.id');

        $res = $this->api('POST', "channels/{$this->channel->id}/plans/{$planId}/items", ['items' => [
            $this->unboxing(0, $refs, ['topic_id' => $topic->id]),
        ]])->json('data');
        $this->assertSame([0], $res['accepted']);

        $old->update(['scheduled_at' => now()->subDays(5)]);
        $this->assertTrue(app(\App\Services\Channel\ChannelTopicService::class)
            ->repeated($this->channel, $topic->id, 'closeup', CarbonImmutable::now()->addDays(3)));
    }
}
