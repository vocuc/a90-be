<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Console\Command;

class CreateWorker extends Command
{
    protected $signature = 'workers:create {name : Tên worker, không trùng}
        {--type=image : Loại worker: image (tạo ảnh) | publish (đăng bài)}
        {--email= : Email tài khoản A51 gắn cho worker (gắn thêm ở trang admin Worker)}';

    protected $description = 'Tạo worker (tạo ảnh / đăng bài) và in token (chỉ hiện một lần)';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        if (Worker::query()->where('name', $name)->exists()) {
            $this->error("Worker [{$name}] đã tồn tại.");

            return self::FAILURE;
        }

        $type = (string) $this->option('type');
        if (! in_array($type, Worker::TYPES, true)) {
            $this->error('Loại worker phải là: '.implode(', ', Worker::TYPES).'.');

            return self::FAILURE;
        }
        $user = null;
        if ($email = $this->option('email')) {
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                $this->error("Không có tài khoản nào dùng email [{$email}].");

                return self::FAILURE;
            }
        }

        [$worker, $token] = Worker::register($name, $type);
        if ($user) {
            $worker->users()->attach($user->id, ['created_at' => now()]);
        }

        $this->info("Đã tạo worker #{$worker->id} [{$name}] loại ".Worker::typeLabel($type).($user ? ", gắn {$user->email}." : '.'));
        $this->line("Token (lưu lại, sẽ không hiện lại): {$token}");

        return self::SUCCESS;
    }
}
