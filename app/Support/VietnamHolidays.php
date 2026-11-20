<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Dịp lễ / mua sắm ở Việt Nam để lệnh /lap-ke-hoach chọn hook (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 9).
 * Ngày dương cố định + Tết âm lịch theo bảng (CẬP NHẬT MỖI NĂM).
 */
final class VietnamHolidays
{
    /** MM-DD => tên */
    private const SOLAR = [
        '01-01' => 'Tết Dương lịch',
        '02-14' => 'Valentine',
        '03-08' => 'Quốc tế Phụ nữ 8/3',
        '04-30' => 'Giải phóng miền Nam 30/4',
        '05-01' => 'Quốc tế Lao động 1/5',
        '06-01' => 'Quốc tế Thiếu nhi 1/6',
        '09-02' => 'Quốc khánh 2/9',
        '10-20' => 'Phụ nữ Việt Nam 20/10',
        '11-11' => 'Sale 11/11',
        '11-20' => 'Nhà giáo Việt Nam 20/11',
        '12-12' => 'Sale 12/12',
        '12-24' => 'Giáng sinh',
    ];

    /** Năm => ngày mùng 1 Tết âm lịch. */
    private const LUNAR_NEW_YEAR = [
        2026 => '2026-02-17',
        2027 => '2027-02-06',
        2028 => '2028-01-26',
    ];

    /**
     * Dịp lễ trong khoảng [$from, $to], kèm vài ngày trước để lên nội dung đón dịp.
     *
     * @return array<int, array{date: string, name: string}>
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = [];
        for ($year = $from->year; $year <= $to->year; $year++) {
            foreach (self::SOLAR as $md => $name) {
                $result[] = ['date' => "{$year}-{$md}", 'name' => $name];
            }
            if (isset(self::LUNAR_NEW_YEAR[$year])) {
                $tet = CarbonImmutable::parse(self::LUNAR_NEW_YEAR[$year]);
                $result[] = ['date' => $tet->subDays(7)->toDateString(), 'name' => 'Ông Công Ông Táo (23 tháng Chạp)'];
                $result[] = ['date' => $tet->subDay()->toDateString(), 'name' => 'Giao thừa'];
                $result[] = ['date' => $tet->toDateString(), 'name' => 'Mùng 1 Tết Nguyên Đán'];
            }
            // Black Friday: thứ 6 cuối cùng của tháng 11
            $result[] = ['date' => CarbonImmutable::create($year, 11, 30)->modify('last friday of this month')->toDateString(), 'name' => 'Black Friday'];
        }

        $start = $from->toDateString();
        $end = $to->toDateString();

        return collect($result)
            ->filter(fn ($h) => $h['date'] >= $start && $h['date'] <= $end)
            ->sortBy('date')
            ->values()
            ->all();
    }
}
