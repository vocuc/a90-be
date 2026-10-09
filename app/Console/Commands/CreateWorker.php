<?php

namespace App\Console\Commands;

use App\Models\Worker;
use Illuminate\Console\Command;

class CreateWorker extends Command
{
    protected $signature = 'workers:create {name : Tên worker, không trùng}';

    protected $description = 'Tạo worker tạo ảnh và in token (chỉ hiện một lần)';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        if (Worker::query()->where('name', $name)->exists()) {
            $this->error("Worker [{$name}] đã tồn tại.");

            return self::FAILURE;
        }

        [$worker, $token] = Worker::register($name);

        $this->info("Đã tạo worker #{$worker->id} [{$name}].");
        $this->line("Token (lưu lại, sẽ không hiện lại): {$token}");

        return self::SUCCESS;
    }
}
