<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Str;

class GenerateApiKeys extends Command
{
    protected $signature = 'users:generate-api-keys';
    protected $description = 'Generate API keys for all users who dont have one';

    public function handle()
    {
        $users = User::whereNull('api_key')->get();
        
        $this->info("Found {$users->count()} users without API keys");
        
        foreach ($users as $user) {
            $user->api_key = Str::random(60);
            $user->save();
            
            $this->info("✅ Generated API key for: {$user->email}");
        }
        
        $this->info("\n🎉 Done! All users now have API keys.");
        
        return 0;
    }
}