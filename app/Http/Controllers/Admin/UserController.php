<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Show users management page
     */
    public function index()
    {
        $accounts = Account::orderBy('name')->get();
        return view('admin.users', compact('accounts'));
    }

    /**
     * Get all users with their accounts
     */
    public function list()
    {
        $currentUser = auth()->user();
        
        if ($currentUser->is_super_admin) {
            // Super admins see everyone
            $users = User::with('accounts')->orderBy('name')->get();
        } else {
            // Admins see: themselves + viewer users THEY created
            $users = User::with('accounts')
                ->where(function($query) use ($currentUser) {
                    $query->where('id', $currentUser->id) // Themselves
                        ->orWhere('created_by', $currentUser->id); // Users they created
                })
                ->orderBy('name')
                ->get()
                ->filter(function($user) use ($currentUser) {
                    // Include current user
                    if ($user->id === $currentUser->id) {
                        return true;
                    }
                    
                    // Include only viewer users they created
                    if ($user->created_by === $currentUser->id && 
                        !$user->is_super_admin && 
                        !$user->isAdminForAnyAccount()) {
                        return true;
                    }
                    
                    return false;
                })
                ->values();
        }
        
        return response()->json([
            'users' => $users->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->is_super_admin,
                    'created_at' => $user->created_at,
                    'accounts' => $user->accounts->map(function($account) {
                        return [
                            'id' => $account->id,
                            'name' => $account->name,
                            'role' => $account->pivot->role ?? 'user',
                        ];
                    }),
                ];
            })
        ]);
    }

    /**
     * Create new user
     */
    public function store(Request $request)
    {
        $currentUser = auth()->user();
        
        if (!$currentUser->canManageUsers()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'is_super_admin' => 'boolean',
        ]);
        
        // Admins cannot create super admins or other admins
        if (!$currentUser->is_super_admin && ($validated['is_super_admin'] ?? false)) {
            return response()->json(['error' => 'Admins cannot create super admin users'], 403);
        }
        
        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_super_admin' => $currentUser->is_super_admin ? ($validated['is_super_admin'] ?? false) : false,
                'created_by' => $currentUser->id, // Track who created this user
            ]);
            
            Log::info('User created', [
                'user_id' => $user->id,
                'created_by' => $currentUser->id,
            ]);
            
            return response()->json([
                'success' => true,
                'user' => $user,
            ]);
            
        } catch (\Exception $e) {
            Log::error('User creation error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update user
     */
    public function update(Request $request, $id)
    {
        $currentUser = auth()->user();
        
        $user = User::findOrFail($id);
        
        // Check permissions
        if (!$currentUser->is_super_admin) {
            // Admins can edit themselves
            if ($currentUser->id == $id) {
                // Allow self-edit
            } else {
                // Admins can only edit viewer users THEY created
                if ($user->created_by !== $currentUser->id) {
                    return response()->json(['error' => 'You can only edit users you created'], 403);
                }
                
                if ($user->is_super_admin || $user->isAdminForAnyAccount()) {
                    return response()->json(['error' => 'Admins can only edit viewer users'], 403);
                }
            }
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => 'nullable|string|min:8',
            'is_super_admin' => 'boolean',
        ]);
        
        try {
            // Admins cannot edit super admin status
            if (!$currentUser->is_super_admin) {
                unset($validated['is_super_admin']);
            }
            
            $updateData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
            ];
            
            if ($currentUser->is_super_admin && isset($validated['is_super_admin'])) {
                $updateData['is_super_admin'] = $validated['is_super_admin'];
            }
            
            if (!empty($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
            }
            
            $user->update($updateData);
            
            Log::info('User updated', [
                'user_id' => $user->id,
                'updated_by' => $currentUser->id,
            ]);
            
            return response()->json([
                'success' => true,
                'user' => $user,
            ]);
            
        } catch (\Exception $e) {
            Log::error('User update error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete user
     */
    public function destroy($id)
    {
        $currentUser = auth()->user();
        
        if (!$currentUser->canManageUsers()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        if ($currentUser->id == $id) {
            return response()->json(['error' => 'Cannot delete yourself'], 400);
        }
        
        try {
            $user = User::findOrFail($id);
            
            // Admins can only delete users THEY created
            if (!$currentUser->is_super_admin) {
                if ($user->created_by !== $currentUser->id) {
                    return response()->json(['error' => 'You can only delete users you created'], 403);
                }
                
                if ($user->is_super_admin || $user->isAdminForAnyAccount()) {
                    return response()->json(['error' => 'Admins can only delete viewer users'], 403);
                }
            }
            
            // Remove all account assignments first
            DB::table('account_user')->where('user_id', $id)->delete();
            
            $user->delete();
            
            Log::info('User deleted', [
                'user_id' => $id,
                'deleted_by' => $currentUser->id,
            ]);
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('User deletion error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Assign user to account
     */
    public function assignToAccount(Request $request)
    {
        $currentUser = auth()->user();
        
        if (!$currentUser->canManageUsers()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'account_id' => 'required|exists:accounts,id',
            'role' => 'required|in:admin,user',
        ]);

        // Admins can only assign 'user' role, not 'admin'
        if (!$currentUser->is_super_admin && $validated['role'] === 'admin') {
            return response()->json(['error' => 'Only super admins can assign admin role'], 403);
        }

        try {
            DB::table('account_user')->updateOrInsert(
                [
                    'user_id' => $validated['user_id'],
                    'account_id' => $validated['account_id'],
                ],
                [
                    'role' => $validated['role'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            
            Log::info('User assigned to account', [
                'user_id' => $validated['user_id'],
                'account_id' => $validated['account_id'],
                'role' => $validated['role'],
                'assigned_by' => $currentUser->id,
            ]);
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('User assignment error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove user from account
     */
    public function removeFromAccount(Request $request)
    {
        $currentUser = auth()->user();
        
        if (!$currentUser->canManageUsers()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'account_id' => 'required|exists:accounts,id',
        ]);
        
        try {
            DB::table('account_user')
                ->where('user_id', $validated['user_id'])
                ->where('account_id', $validated['account_id'])
                ->delete();
            
            Log::info('User removed from account', [
                'user_id' => $validated['user_id'],
                'account_id' => $validated['account_id'],
                'removed_by' => $currentUser->id,
            ]);
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('User removal error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}