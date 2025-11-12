<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountController extends Controller
{
    /**
     * Show accounts management page
     */
    public function index()
    {
        $user = auth()->user();
        
        if (!$user->canManageAccounts()) {
            abort(403, 'Unauthorized - Only admins can manage accounts');
        }
        
        if ($user->is_super_admin) {
            // Super admins see all accounts
            $accounts = Account::with('users')->orderBy('name')->get();
        } else {
            // Admins see: accounts they created OR accounts they're assigned to
            $accounts = Account::with('users')
                ->where(function($query) use ($user) {
                    $query->where('created_by', $user->id) // Accounts they created
                        ->orWhereHas('users', function($q) use ($user) {
                            $q->where('users.id', $user->id); // Accounts they're assigned to
                        });
                })
                ->orderBy('name')
                ->get();
        }
        
        return view('admin.accounts', compact('accounts'));
    }

    /**
     * Get accounts as JSON
     */
    public function list()
    {
        $user = auth()->user();
        
        if ($user->is_super_admin) {
            $accounts = Account::with('users')->orderBy('name')->get();
        } else {
            $accounts = Account::with('users')
                ->where(function($query) use ($user) {
                    $query->where('created_by', $user->id)
                        ->orWhereHas('users', function($q) use ($user) {
                            $q->where('users.id', $user->id);
                        });
                })
                ->orderBy('name')
                ->get();
        }
        
        return response()->json([
            'accounts' => $accounts->map(function($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'domain' => $account->domain,
                    'is_active' => $account->is_active,
                    'created_at' => $account->created_at,
                    'users_count' => $account->users->count(),
                    'users' => $account->users->pluck('name'),
                ];
            })
        ]);
    }

    /**
     * Store new account
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->canManageAccounts()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'required|string|max:255|unique:accounts,domain',
            'is_active' => 'boolean',
        ]);
        
        try {
            $slug = \Illuminate\Support\Str::slug($validated['name']);
            
            $originalSlug = $slug;
            $counter = 1;
            while (Account::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
            
            $account = Account::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'domain' => $validated['domain'],
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => $user->id,
            ]);
            
            // Auto-assign the creator to the account with 'admin' role (if not super admin)
            if (!$user->is_super_admin) {
                DB::table('account_user')->insert([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                Log::info('Admin auto-assigned to created account', [
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                ]);
            }
            
            Log::info('Account created', [
                'account_id' => $account->id,
                'name' => $account->name,
                'slug' => $account->slug,
                'created_by' => $user->id,
            ]);
            
            return response()->json([
                'success' => true,
                'account' => $account,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Account creation error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update account
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        
        if (!$user->canManageAccounts()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $account = Account::findOrFail($id);
        
        // Admins can only edit accounts they created or are assigned to
        if (!$user->is_super_admin) {
            $canEdit = $account->created_by === $user->id || 
                    $account->users()->where('users.id', $user->id)->exists();
            
            if (!$canEdit) {
                return response()->json(['error' => 'You can only edit accounts you created or are assigned to'], 403);
            }
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'required|string|max:255|unique:accounts,domain,' . $id,
            'is_active' => 'boolean',
        ]);
        
        try {
            $slug = $account->slug;
            if ($validated['name'] !== $account->name) {
                $slug = \Illuminate\Support\Str::slug($validated['name']);
                
                $originalSlug = $slug;
                $counter = 1;
                while (Account::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                    $slug = $originalSlug . '-' . $counter;
                    $counter++;
                }
            }
            
            $account->update([
                'name' => $validated['name'],
                'slug' => $slug,
                'domain' => $validated['domain'],
                'is_active' => $validated['is_active'] ?? $account->is_active,
            ]);
            
            Log::info('Account updated', [
                'account_id' => $account->id,
                'updated_by' => $user->id,
            ]);
            
            return response()->json([
                'success' => true,
                'account' => $account,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Account update error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete account
     */
    public function destroy($id)
    {
        $user = auth()->user();
        
        if (!$user->canManageAccounts()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $account = Account::findOrFail($id);
        
        // Admins can only delete accounts they created or are assigned to
        if (!$user->is_super_admin) {
            $canDelete = $account->created_by === $user->id || 
                        $account->users()->where('users.id', $user->id)->exists();
            
            if (!$canDelete) {
                return response()->json(['error' => 'You can only delete accounts you created or are assigned to'], 403);
            }
        }
        
        try {
            if ($account->users()->count() > 0) {
                return response()->json([
                    'error' => 'Cannot delete account with assigned users. Remove users first.'
                ], 400);
            }
            
            $account->delete();
            
            Log::info('Account deleted', [
                'account_id' => $id,
                'deleted_by' => $user->id,
            ]);
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('Account deletion error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Assign user to account
     */
    public function assignUser(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->is_super_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:admin,viewer',
        ]);
        
        try {
            DB::table('account_user')->updateOrInsert(
                [
                    'account_id' => $validated['account_id'],
                    'user_id' => $validated['user_id'],
                ],
                [
                    'role' => $validated['role'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('User assignment error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove user from account
     */
    public function removeUser(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->is_super_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'user_id' => 'required|exists:users,id',
        ]);
        
        try {
            DB::table('account_user')
                ->where('account_id', $validated['account_id'])
                ->where('user_id', $validated['user_id'])
                ->delete();
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('User removal error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}