<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * creative_prompt: text -> json, dạng [{"label": "...", "value": "..."}].
 * Dữ liệu cũ đã là JSON đúng dạng thì giữ, chuỗi thường được bọc thành 1 nhóm "Prompt".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_creative_configs')->whereNotNull('creative_prompt')->orderBy('id')
            ->each(function ($row) {
                DB::table('ai_creative_configs')->where('id', $row->id)
                    ->update(['creative_prompt' => $this->toJson($row->creative_prompt)]);
            });

        Schema::table('ai_creative_configs', function (Blueprint $table) {
            $table->json('creative_prompt')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_creative_configs', function (Blueprint $table) {
            $table->text('creative_prompt')->nullable()->change();
        });
    }

    private function toJson(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if ($decoded === []) {
            return null;
        }
        if (is_array($decoded) && isset($decoded['label'], $decoded['value'])) {
            $decoded = [$decoded];
        }

        $groups = is_array($decoded) && array_is_list($decoded)
            ? array_values(array_filter($decoded, fn ($g) => is_array($g) && isset($g['label'], $g['value'])))
            : [];

        if (! $groups || count($groups) !== count($decoded)) {
            $groups = [['label' => 'Prompt', 'value' => $raw]];
        }

        return json_encode(
            array_map(fn ($g) => ['label' => (string) $g['label'], 'value' => (string) $g['value']], $groups),
            JSON_UNESCAPED_UNICODE
        );
    }
};
