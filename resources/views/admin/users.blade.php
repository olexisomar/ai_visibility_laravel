<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>User Management</title>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            padding: 32px;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.3s;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #9ca3af;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .modal-close:hover {
            background: #f3f4f6;
            color: #111827;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 6px;
            color: #374151;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group .help-text {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        .account-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
        }
        
        .account-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f9fafb;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        
        .account-item:last-child {
            margin-bottom: 0;
        }
        
        .account-item-info {
            flex: 1;
        }
        
        .account-item-name {
            font-weight: 600;
            font-size: 14px;
            color: #111827;
        }
        
        .account-item-role {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-super {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-admin {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-viewer {
            background: #e5e7eb;
            color: #374151;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <!-- Header with Account Switcher & User Menu -->
  <div style="background: white; border-bottom: 2px solid #e5e7eb; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <!-- Left: Logo & Account Switcher -->
    <div style="display: flex; align-items: center; gap: 20px;">
      <h1 style="font-size: 22px; font-weight: 700; color: #374151; margin: 0;">
        <a href="/" style="text-decoration: none;">🔍 AI Visibility Tracker</a>
      </h1>
      
      <!-- Account Switcher -->
      <div style="position: relative;">
        <button id="accountSwitcher" style="
          padding: 8px 16px;
          background: #f3f4f6;
          border: 1px solid #d1d5db;
          border-radius: 6px;
          cursor: pointer;
          display: flex;
          align-items: center;
          gap: 8px;
          font-size: 14px;
          font-weight: 500;
          color: #374151;
          transition: all 0.2s;
        " onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
          <span>📊 {{ session('account_name') }}</span>
          <span style="font-size: 10px;">▼</span>
        </button>
        
        <!-- Account Dropdown -->
        <div id="accountDropdown" style="
          display: none;
          position: absolute;
          top: 100%;
          left: 0;
          margin-top: 8px;
          background: white;
          border: 1px solid #e5e7eb;
          border-radius: 8px;
          box-shadow: 0 10px 25px rgba(0,0,0,0.1);
          min-width: 220px;
          z-index: 1000;
        ">
          @php
              $user = auth()->user();
              $accounts = $user->is_super_admin 
                  ? \App\Models\Account::where('is_active', true)->get()
                  : $user->accounts()->where('is_active', true)->get();
          @endphp
          
          <div style="padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">
            Switch Brand
          </div>
          
          @foreach($accounts as $account)
              <a href="#" 
                 class="account-option" 
                 data-account-id="{{ $account->id }}"
                 style="
                     display: flex;
                     align-items: center;
                     gap: 10px;
                     padding: 12px 16px;
                     color: #374151;
                     text-decoration: none;
                     border-bottom: 1px solid #f3f4f6;
                     transition: background 0.2s;
                     {{ session('account_id') == $account->id ? 'background: #eff6ff; font-weight: 600;' : '' }}
                 "
                 onmouseover="this.style.background='#f9fafb'"
                 onmouseout="this.style.background='{{ session('account_id') == $account->id ? '#eff6ff' : 'white' }}'"
              >
                  <span style="font-size: 18px;">{{ session('account_id') == $account->id ? '✓' : '📊' }}</span>
                  <div>
                      <div style="font-size: 14px;">{{ $account->name }}</div>
                      <div style="font-size: 11px; color: #9ca3af;">{{ $account->domain }}</div>
                  </div>
              </a>
          @endforeach
        </div>
      </div>
      <!-- Role Badge -->
        <div style="margin-left: 16px; padding: 6px 12px; background: 
            @if(auth()->user()->is_super_admin)
                #fef3c7
            @elseif(auth()->user()->isAdminFor(session('account_id')))
                #dbeafe
            @else
                #f3f4f6
            @endif
            ; border-radius: 6px;">
            <span style="font-size: 12px; font-weight: 600; color: 
                @if(auth()->user()->is_super_admin)
                    #92400e
                @elseif(auth()->user()->isAdminFor(session('account_id')))
                    #1e40af
                @else
                    #374151
                @endif
                ;">
                @if(auth()->user()->is_super_admin)
                    ⭐ Super Admin
                @elseif(auth()->user()->isAdminFor(session('account_id')))
                    🔧 Admin
                @else
                    👁️ Viewer
                @endif
            </span>
        </div>
    </div>
    
    <!-- Right: User Menu -->
    <div style="display: flex; align-items: center; gap: 12px;margin-right: 35px;">
      <!-- User Menu Button -->
      <div style="position: relative;">
        <button id="userMenu" style="
          padding: 8px 16px;
          background: white;
          border: 1px solid #d1d5db;
          border-radius: 6px;
          cursor: pointer;
          display: flex;
          align-items: center;
          gap: 8px;
          font-size: 14px;
          font-weight: 500;
          color: #374151;
          transition: all 0.2s;
        " onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
          <span>👤</span>
          <span>{{ auth()->user()->name }}</span>
          <span style="font-size: 10px;">▼</span>
        </button>
        
        <!-- User Dropdown -->
        <div id="userDropdown" style="
          display: none;
          position: absolute;
          top: 100%;
          right: 0;
          margin-top: 8px;
          background: white;
          border: 1px solid #e5e7eb;
          border-radius: 8px;
          box-shadow: 0 10px 25px rgba(0,0,0,0.1);
          min-width: 240px;
          z-index: 1000;
        ">
          <!-- User Info -->
          <div style="padding: 16px; border-bottom: 1px solid #f3f4f6; background: #f9fafb;">
            <div style="font-size: 15px; font-weight: 600; color: #374151; margin-bottom: 4px;">
              {{ auth()->user()->name }}
            </div>
            <div style="font-size: 12px; color: #6b7280; margin-bottom: 6px;">
              {{ auth()->user()->email }}
            </div>
            <div style="display: inline-block; padding: 2px 8px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 11px; font-weight: 600;">
              {{ ucfirst(session('user_role')) }}
            </div>
          </div>
          
          @if(!auth()->user()->isViewerFor(session('account_id')))
              <a href="{{ route('dashboard') }}" style="
                  display: flex;
                  align-items: center;
                  gap: 10px;
                  padding: 12px 16px;
                  color: #374151;
                  text-decoration: none;
                  font-size: 14px;
                  border-bottom: 1px solid #f3f4f6;
                  transition: background 0.2s;
              " onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
                  <span>📊</span>
                  <span>Back to Dashboard</span>
              </a>
              <a href="{{ route('admin.accounts') }}" style="
                  display: flex;
                  align-items: center;
                  gap: 10px;
                  padding: 12px 16px;
                  color: #374151;
                  text-decoration: none;
                  font-size: 14px;
                  border-bottom: 1px solid #f3f4f6;
                  transition: background 0.2s;
              " onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
                  <span>⚙️</span>
                  <span>Manage Accounts</span>
              </a>
              <a href="{{ route('admin.users') }}" style="display: flex;
                      align-items: center;
                      gap: 10px;
                      padding: 12px 16px;
                      color: #374151;
                      text-decoration: none;
                      font-size: 14px;
                      border-bottom: 1px solid #f3f4f6;
                      transition: background 0.2s;
                  " onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
                  <span>👤</span>
                  <span>Manage Users</span>
              </a>
          @endif
          <!-- Logout -->
          <a href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" style="
              display: flex;
              align-items: center;
              gap: 10px;
              padding: 12px 16px;
              color: #ef4444;
              text-decoration: none;
              font-size: 14px;
              font-weight: 500;
              transition: background 0.2s;
          " onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='white'">
              <span>🚪</span>
              <span>Logout</span>
          </a>
        </div>
      </div>
    </div>
    <!-- Notification Bell -->
    <div style="position: fixed; right: 20px; z-index: 1000;">
      <button id="notificationBell" class="btn pill-info" style="position: relative; padding: 10px 16px;">
        🔔
        <span id="notificationBadge" class="hidden" style="
          position: absolute;
          top: -5px;
          right: -5px;
          background: #ef4444;
          color: white;
          border-radius: 50%;
          width: 20px;
          height: 20px;
          font-size: 11px;
          font-weight: bold;
          display: flex;
          align-items: center;
          justify-content: center;
        ">0</span>
      </button>
    </div>
  </div>
  <!-- Hidden logout form -->
  <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
      @csrf
  </form>
    <!-- Notification Dropdown -->
    <div id="notificationDropdown" class="hidden" style="
      position: fixed;
      top: 80px;
      right: 20px;
      width: 400px;
      max-height: 500px;
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.1);
      z-index: 999;
      overflow: hidden;
    ">
      <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: #f9fafb;">
        <h3 style="margin: 0; font-size: 16px;">Notifications</h3>
        
        <div style="display: flex; gap: 8px; align-items: center;">
          <button id="markAllRead" class="btn alt" style="font-size: 12px; padding: 4px 12px;">
            Mark all read
          </button>
          
          <!-- Clear dropdown button -->
          <div style="position: relative;">
            <button id="clearMenuBtn" class="btn alt" style="font-size: 12px; padding: 4px 12px; display: flex; align-items: center; gap: 4px;" onclick="toggleClearMenu(event)">
              Clear ▼
            </button>
            
            <!-- Clear dropdown menu -->
            <div id="clearMenu" style="
              display: none;
              position: absolute;
              right: 0;
              top: 100%;
              margin-top: 4px;
              background: white;
              border: 1px solid #e5e7eb;
              border-radius: 6px;
              box-shadow: 0 4px 6px rgba(0,0,0,0.1);
              min-width: 150px;
              z-index: 1000;
            ">
              <button onclick="clearAllNotifications(); toggleClearMenu(event);" style="
                width: 100%;
                padding: 8px 12px;
                border: none;
                background: none;
                text-align: left;
                font-size: 13px;
                cursor: pointer;
                color: #374151;
                transition: background 0.2s;
              " onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
                Clear All
              </button>
              <button onclick="clearReadNotifications(); toggleClearMenu(event);" style="
                width: 100%;
                padding: 8px 12px;
                border: none;
                background: none;
                text-align: left;
                font-size: 13px;
                cursor: pointer;
                color: #374151;
                transition: background 0.2s;
              " onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
                Clear Read Only
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <div id="notificationList" style="max-height: 400px; overflow-y: auto;">
        <div style="padding: 40px; text-align: center; color: #9ca3af;">
          <div style="font-size: 48px; margin-bottom: 12px;">🔔</div>
          <div>No notifications yet</div>
        </div>
      </div>
    </div>
    <div style="max-width: 1400px; margin: 0 auto;">
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h2 style="font-size: 28px; font-weight: 700; color: #111827; margin: 0;">User Management</h2>
                <p style="color: #6b7280; margin-top: 4px;">Manage users and account assignments</p>
            </div>
        </div>

        <!-- Add User Button -->
        <div style="margin-bottom: 24px;">
            <button onclick="openAddUserModal()" class="btn pill-ok" style="padding: 12px 24px;">
                + Add New User
            </button>
        </div>

        <!-- Users List -->
        <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
            <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
                <h2 style="font-size: 18px; font-weight: 600; margin: 0;">All Users</h2>
            </div>
            
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #f9fafb;">
                        <tr>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">ID</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Name</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Email</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Role</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Accounts</th>
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Created</th>
                            <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr>
                            <td colspan="7" style="padding: 40px; text-align: center; color: #9ca3af;">
                                Loading users...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="modal-close" onclick="closeAddUserModal()">×</button>
            </div>
            
            <form id="addUserForm">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="addUserName" required>
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="addUserEmail" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="addUserPassword" required minlength="8" autocomplete="current-password">
                    <div class="help-text">Minimum 8 characters</div>
                </div>
                @if(auth()->user()->is_super_admin)
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="addUserSuperAdmin">
                        <label for="addUserSuperAdmin" style="margin: 0;">Super Admin (full access to all accounts)</label>
                    </div>
                </div>
                @endif
                <div id="addUserError" style="color: #ef4444; font-size: 14px; margin-bottom: 12px;"></div>
                
                <div class="modal-actions">
                    <button type="button" class="btn alt" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" class="btn pill-ok">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="modal-close" onclick="closeEditUserModal()">×</button>
            </div>
            
            <form id="editUserForm">
                <input type="hidden" id="editUserId">
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="editUserName" required>
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="editUserEmail" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label>New Password (leave blank to keep current)</label>
                    <input type="password" id="editUserPassword" minlength="8" autocomplete="current-password">
                    <div class="help-text">Only fill this if you want to change the password</div>
                </div>
                @if(auth()->user()->is_super_admin)
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="editUserSuperAdmin">
                        <label for="editUserSuperAdmin" style="margin: 0;">Super Admin</label>
                    </div>
                </div>
                @endif
                <div id="editUserError" style="color: #ef4444; font-size: 14px; margin-bottom: 12px;"></div>
                
                <div class="modal-actions">
                    <button type="button" class="btn alt" onclick="closeEditUserModal()">Cancel</button>
                    <button type="submit" class="btn pill-ok">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manage Accounts Modal -->
    <div id="manageAccountsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Manage Account Access</h2>
                <button class="modal-close" onclick="closeManageAccountsModal()">×</button>
            </div>
            
            <input type="hidden" id="manageUserId">
            <input type="hidden" id="manageUserName">
            
            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;">Current Accounts</h3>
                <div id="currentAccountsList" class="account-list">
                    <p style="color: #9ca3af; text-align: center; padding: 20px;">No accounts assigned</p>
                </div>
            </div>
            
            <div style="border-top: 1px solid #e5e7eb; padding-top: 20px;">
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;">Assign New Account</h3>
                
                <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px;">
                    <div class="form-group" style="margin: 0;">
                        <label>Account</label>
                        <select id="assignAccountSelect">
                            <option value="">Select account...</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin: 0;">
                        <label>Role</label>
                        <select id="assignRoleSelect">
                            <option value="user">Viewer</option>
                            @if(auth()->user()->is_super_admin)
                            <option value="admin">Admin</option>
                            @endif
                        </select>
                    </div>
                </div>
                
                <button onclick="assignAccount()" class="btn pill-ok" style="margin-top: 12px; width: 100%;">
                    + Assign Account
                </button>
            </div>
            
            <div id="manageAccountsError" style="color: #ef4444; font-size: 14px; margin-top: 12px;"></div>
        </div>
    </div>

    <script src="{{ asset('assets/js/api-config.js') }}"></script>
    <script src="{{ asset('assets/js/siteHeader.js') }}"></script>
    <script src="{{ asset('assets/js/notifications.js') }}"></script> 
    <script>
        const USERS_API = '{{ url("/admin/api/users") }}';
        let currentUsers = [];
        
        document.addEventListener('DOMContentLoaded', () => {
            loadUsers();
        });

        // Load all users
        async function loadUsers() {
            try {
                const response = await fetch(USERS_API);
                const data = await response.json();
                currentUsers = data.users;
                
                const tbody = document.getElementById('usersTableBody');
                
                if (data.users.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" style="padding: 40px; text-align: center; color: #9ca3af;">No users found</td></tr>';
                    return;
                }
                
                tbody.innerHTML = data.users.map(user => {
                    const accountsText = user.accounts.length > 0 
                        ? user.accounts.map(a => a.name).join(', ')
                        : 'No accounts';
                    
                    const roleHtml = user.is_super_admin
                        ? '<span class="badge badge-super">Super Admin</span>'
                        : user.accounts.length > 0
                            ? `<span class="badge badge-admin">${user.accounts.length} account(s)</span>`
                            : '<span class="badge badge-viewer">No access</span>';
                    
                    return `
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 16px; font-family: monospace; font-size: 13px;">${user.id}</td>
                            <td style="padding: 16px; font-weight: 600;">${user.name}</td>
                            <td style="padding: 16px; color: #6b7280;">${user.email}</td>
                            <td style="padding: 16px;">${roleHtml}</td>
                            <td style="padding: 16px; font-size: 13px; color: #6b7280; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${accountsText}">
                                ${accountsText}
                            </td>
                            <td style="padding: 16px; font-size: 13px; color: #6b7280;">${new Date(user.created_at).toLocaleDateString()}</td>
                            <td style="padding: 16px; text-align: right;">
                                <button onclick='editUser(${JSON.stringify(user)})' class="btn alt" style="font-size: 13px; padding: 6px 12px;">Edit</button>
                                <button onclick='manageAccounts(${JSON.stringify(user)})' class="btn" style="font-size: 13px; padding: 6px 12px; margin-left: 8px;">Accounts</button>
                                <button onclick="deleteUser(${user.id}, '${user.name.replace(/'/g, "\\'")}', ${user.id === {{ auth()->id() }} ? 'true' : 'false'})" class="btn pill-miss" style="font-size: 13px; padding: 6px 12px; margin-left: 8px;">Delete</button>
                            </td>
                        </tr>
                    `;
                }).join('');
                
            } catch (error) {
                console.error('Error loading users:', error);
            }
        }

        // Add User Modal
        function openAddUserModal() {
            document.getElementById('addUserForm').reset();
            document.getElementById('addUserError').innerHTML = '';
            document.getElementById('addUserModal').classList.add('show');
        }

        function closeAddUserModal() {
            document.getElementById('addUserModal').classList.remove('show');
        }

        document.getElementById('addUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const name = document.getElementById('addUserName').value;
            const email = document.getElementById('addUserEmail').value;
            const password = document.getElementById('addUserPassword').value;
            const errorDiv = document.getElementById('addUserError');
            
            // Only check super admin checkbox if it exists (for super admins)
            const superAdminCheckbox = document.getElementById('addUserSuperAdmin');
            const isSuperAdmin = superAdminCheckbox ? superAdminCheckbox.checked : false;
            
            try {
                const response = await fetch(USERS_API, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        name: name,
                        email: email,
                        password: password,
                        is_super_admin: isSuperAdmin,
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    closeAddUserModal();
                    loadUsers();
                } else {
                    errorDiv.innerHTML = '✗ ' + (data.error || 'Error creating user');
                }
            } catch (error) {
                errorDiv.innerHTML = '✗ Error: ' + error.message;
            }
        });

        // Edit User Modal
        function editUser(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editUserName').value = user.name;
            document.getElementById('editUserEmail').value = user.email;
            document.getElementById('editUserPassword').value = '';
            
            // Only set super admin checkbox if it exists (super admins only)
            const superAdminCheckbox = document.getElementById('editUserSuperAdmin');
            if (superAdminCheckbox) {
                superAdminCheckbox.checked = user.is_super_admin;
            }
            
            document.getElementById('editUserError').innerHTML = '';
            document.getElementById('editUserModal').classList.add('show');
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').classList.remove('show');
        }

        document.getElementById('editUserForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const id = document.getElementById('editUserId').value;
        const name = document.getElementById('editUserName').value;
        const email = document.getElementById('editUserEmail').value;
        const password = document.getElementById('editUserPassword').value;
        const errorDiv = document.getElementById('editUserError');
        
        // Only check super admin checkbox if it exists (for super admins)
        const superAdminCheckbox = document.getElementById('editUserSuperAdmin');
        const isSuperAdmin = superAdminCheckbox ? superAdminCheckbox.checked : false;
        
        const payload = {
            name: name,
            email: email,
            is_super_admin: isSuperAdmin,
        };
        
        if (password) {
            payload.password = password;
        }
        
        try {
            const response = await fetch(`${USERS_API}/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(payload)
            });
            
            const data = await response.json();
            
            if (data.success) {
                closeEditUserModal();
                loadUsers();
            } else {
                errorDiv.innerHTML = '✗ ' + (data.error || 'Error updating user');
            }
        } catch (error) {
            errorDiv.innerHTML = '✗ Error: ' + error.message;
        }
    });

        // Delete User
        async function deleteUser(id, name, isSelf) {
            if (isSelf) {
                alert('You cannot delete yourself!');
                return;
            }
            
            if (!confirm(`Are you sure you want to delete user "${name}"?\n\nThis will remove them from all accounts.`)) {
                return;
            }
            
            try {
                const response = await fetch(`${USERS_API}/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    loadUsers();
                } else {
                    alert('Error: ' + (data.error || 'Failed to delete user'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        // Manage Accounts Modal
        function manageAccounts(user) {
            document.getElementById('manageUserId').value = user.id;
            document.getElementById('manageUserName').value = user.name;
            document.getElementById('manageAccountsError').innerHTML = '';
            
            const listDiv = document.getElementById('currentAccountsList');
            
            if (user.accounts.length === 0) {
                listDiv.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No accounts assigned</p>';
            } else {
                listDiv.innerHTML = user.accounts.map(account => `
                    <div class="account-item">
                        <div class="account-item-info">
                            <div class="account-item-name">${account.name}</div>
                            <div class="account-item-role">Role: ${account.role}</div>
                        </div>
                        <button onclick="removeAccount(${user.id}, ${account.id})" class="btn pill-miss" style="font-size: 12px; padding: 4px 12px;">
                            Remove
                        </button>
                    </div>
                `).join('');
            }
            updateAccountDropdown(user);
            
            document.getElementById('manageAccountsModal').classList.add('show');
        }

        // Refresh the accounts list in the modal
        function refreshAccountsList(user) {
            const listDiv = document.getElementById('currentAccountsList');
            
            if (user.accounts.length === 0) {
                listDiv.innerHTML = '<p style="color: #9ca3af; text-align: center; padding: 20px;">No accounts assigned</p>';
            } else {
                listDiv.innerHTML = user.accounts.map(account => `
                    <div class="account-item">
                        <div class="account-item-info">
                            <div class="account-item-name">${account.name}</div>
                            <div class="account-item-role">Role: ${account.role}</div>
                        </div>
                        <button onclick="removeAccount(${user.id}, ${account.id})" class="btn pill-miss" style="font-size: 12px; padding: 4px 12px;">
                            Remove
                        </button>
                    </div>
                `).join('');
            }
            
            // Update the dropdown to disable assigned accounts
            updateAccountDropdown(user);
        }

        // Update account dropdown to disable already assigned accounts
        function updateAccountDropdown(user) {
            const select = document.getElementById('assignAccountSelect');
            const assignedAccountIds = user.accounts.map(a => a.id);
            
            // Reset all options first
            Array.from(select.options).forEach(option => {
                if (option.value) {
                    const accountId = parseInt(option.value);
                    const isAssigned = assignedAccountIds.includes(accountId);
                    
                    option.disabled = isAssigned;
                    
                    // ADD VISUAL FEEDBACK
                    if (isAssigned) {
                        option.style.color = '#9ca3af';
                        option.style.fontStyle = 'italic';
                        // Add "(Assigned)" if not already there
                        if (!option.textContent.includes('(Assigned)')) {
                            option.textContent = option.textContent + ' (Assigned)';
                        }
                    } else {
                        option.style.color = '';
                        option.style.fontStyle = '';
                        // Remove "(Assigned)" if present
                        option.textContent = option.textContent.replace(' (Assigned)', '');
                    }
                }
            });
            
            // Reset the select to placeholder
            select.value = '';
        }

        function closeManageAccountsModal() {
            document.getElementById('manageAccountsModal').classList.remove('show');
        }

        async function assignAccount() {
            const userId = document.getElementById('manageUserId').value;
            const accountId = document.getElementById('assignAccountSelect').value;
            const role = document.getElementById('assignRoleSelect').value;
            const errorDiv = document.getElementById('manageAccountsError');
            
            if (!accountId) {
                errorDiv.innerHTML = '✗ Please select an account';
                return;
            }
            
            try {
                const response = await fetch('{{ url("/admin/api/users/assign") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        account_id: accountId,
                        role: role,
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    errorDiv.innerHTML = '';
                    document.getElementById('assignAccountSelect').value = '';
                    
                    // Refresh the main users list
                    await loadUsers();
                    
                    // Find the updated user data
                    const updatedUser = currentUsers.find(u => u.id == userId);
                    
                    if (updatedUser) {
                        // Refresh the modal with updated data
                        refreshAccountsList(updatedUser);
                    }
                } else {
                    errorDiv.innerHTML = '✗ ' + (data.error || 'Error assigning account');
                }
            } catch (error) {
                errorDiv.innerHTML = '✗ Error: ' + error.message;
            }
        }

        async function removeAccount(userId, accountId) {
            if (!confirm('Remove this account access?')) {
                return;
            }
            
            try {
                const response = await fetch('{{ url("/admin/api/users/remove") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        account_id: accountId,
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    loadUsers();
                    const user = currentUsers.find(u => u.id == userId);
                    if (user) {
                        // Refresh the user data
                        const refreshResponse = await fetch(USERS_API);
                        const refreshData = await refreshResponse.json();
                        currentUsers = refreshData.users;
                        const updatedUser = currentUsers.find(u => u.id == userId);
                        manageAccounts(updatedUser);
                    }
                } else {
                    document.getElementById('manageAccountsError').innerHTML = '✗ ' + (data.error || 'Error removing account');
                }
            } catch (error) {
                document.getElementById('manageAccountsError').innerHTML = '✗ Error: ' + error.message;
            }
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>