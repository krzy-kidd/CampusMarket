<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapSuperAdmin extends Command
{
    protected $signature = 'campustrade:bootstrap-super-admin';

    protected $description = 'Create the first active CampusTrade Super Admin from the command line';

    public function handle(): int
    {
        if (Admin::query()->where('role', 'super_admin')->where('account_status', 'active')->exists()) {
            $this->components->error('An active Super Admin already exists.');

            return self::FAILURE;
        }

        $email = Str::lower(trim($this->ask('Super Admin email')));
        $password = $this->secret('Password (at least 8 letters and numbers only)');
        $passwordConfirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'regex:/\A(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{8,}\z/'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $password): void {
            $user = User::query()->create([
                'student_id_number' => 'ADMIN-'.Str::upper(Str::random(12)),
                'first_name' => 'CampusTrade',
                'last_name' => 'Administrator',
                'email' => $email,
                'password' => Hash::make($password),
                'account_status' => 'active',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            Admin::query()->create([
                'user_id' => $user->id,
                'role' => 'super_admin',
                'account_status' => 'active',
            ]);
        });

        $this->components->info('The initial Super Admin account was created.');

        return self::SUCCESS;
    }
}
