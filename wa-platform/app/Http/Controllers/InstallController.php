<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Browser-based installer so the platform can be set up on shared hosting
 * without SSH: it runs database migrations and creates the first owner
 * account. It locks itself once an owner exists.
 */
class InstallController extends Controller
{
    protected function alreadyInstalled(): bool
    {
        try {
            return User::query()->exists();
        } catch (\Throwable $e) {
            // Tables not migrated yet -> not installed.
            return false;
        }
    }

    public function show()
    {
        if ($this->alreadyInstalled()) {
            return redirect('/login')->with('status', 'Already installed. Log in below.');
        }

        return view('install');
    }

    public function run(Request $request)
    {
        if ($this->alreadyInstalled()) {
            abort(403, 'Already installed.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Verify the database connection before trying to migrate.
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors([
                'database' => 'Cannot connect to the database. Check your .env DB settings. ('.$e->getMessage().')',
            ]);
        }

        Artisan::call('migrate', ['--force' => true]);

        // Ensure the public storage symlink exists (media + PWA assets).
        try {
            Artisan::call('storage:link');
        } catch (\Throwable $e) {
            // Non-fatal on hosts that disallow symlinks; docs cover the manual copy.
        }

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'owner',
            'is_active' => true,
        ]);

        Storage::disk('local')->put('installed_at', now()->toIso8601String());

        return redirect('/login')->with('status', 'Setup complete! Log in with your owner account.');
    }
}
