<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Account Management</title>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <style>
        /* Modal Styles */
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
        max-width: 500px;
        width: 90%;
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
    
    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 24px;
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
<div style="max-width: 1200px; margin: 0 auto;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h2 style="font-size: 28px; font-weight: 700; color: #111827; margin: 0;">Account Management</h2>
            <p style="color: #6b7280; margin-top: 4px;">Manage accounts and user assignments</p>
        </div>
    </div>

    <!-- Add Account Form -->
    <div class="card" style="margin-bottom: 24px; background: white; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Add New Account</h2>
        
        <form id="addAccountForm" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 40px; align-items: end;">
            <div>
                <label style="display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px;">Account Name</label>
                <input type="text" id="accountName" placeholder="e.g., Casino Paradise" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div>
                <label style="display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px;">Primary Domain</label>
                <input type="text" id="accountDomain" placeholder="e.g., casinoparadise.com" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <button type="submit" class="btn pill-ok" style="padding: 10px 24px;">Create Account</button>
        </form>
        
        <div id="addAccountStatus" style="margin-top: 12px; font-size: 14px;"></div>
    </div>

    <!-- Accounts List -->
    <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
            <h2 style="font-size: 18px; font-weight: 600; margin: 0;">Existing Accounts</h2>
        </div>
        
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background: #f9fafb;">
                    <tr>
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">ID</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Name</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Domain</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Users</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Status</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Created</th>
                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody id="accountsTableBody">
                    <tr>
                        <td colspan="7" style="padding: 40px; text-align: center; color: #9ca3af;">
                            Loading accounts...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Account</h2>
            <button class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        
        <form id="editAccountForm">
            <input type="hidden" id="editAccountId">
            
            <div class="form-group">
                <label>Account Name</label>
                <input type="text" id="editAccountName" required>
            </div>
            
            <div class="form-group">
                <label>Primary Domain</label>
                <input type="text" id="editAccountDomain" required>
            </div>
            
            <div class="form-group">
                <label>Status</label>
                <select id="editAccountStatus">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            
            <div id="editAccountError" style="color: #ef4444; font-size: 14px; margin-bottom: 12px;"></div>
            
            <div class="modal-actions">
                <button type="button" class="btn alt" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn pill-ok">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script src="{{ asset('assets/js/api-config.js') }}"></script>
<script src="{{ asset('assets/js/notifications.js') }}"></script>
<script src="{{ asset('assets/js/siteHeader.js') }}"></script>
 
<script>
    // Base URL from Laravel
    const ACCOUNTS_API = '{{ url("/admin/api/accounts") }}';
    
    // Load accounts on page load
    document.addEventListener('DOMContentLoaded', () => {
        loadAccounts();
    });

    // Add account form submission
    document.getElementById('addAccountForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const name = document.getElementById('accountName').value;
        const domain = document.getElementById('accountDomain').value;
        const status = document.getElementById('addAccountStatus');
        
        try {
            const response = await fetch(ACCOUNTS_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    name: name,
                    domain: domain,
                    is_active: true,
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                status.innerHTML = '<span style="color: #10b981;">✓ Account created successfully!</span>';
                document.getElementById('addAccountForm').reset();
                loadAccounts();
                
                setTimeout(() => {
                    status.innerHTML = '';
                }, 3000);
            } else {
                status.innerHTML = '<span style="color: #ef4444;">✗ ' + (data.error || 'Error creating account') + '</span>';
            }
        } catch (error) {
            status.innerHTML = '<span style="color: #ef4444;">✗ Error: ' + error.message + '</span>';
        }
    });

    // Load accounts list
    async function loadAccounts() {
        try {
            const response = await fetch(ACCOUNTS_API);
            const data = await response.json();
            
            const tbody = document.getElementById('accountsTableBody');
            
            if (data.accounts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="padding: 40px; text-align: center; color: #9ca3af;">No accounts found</td></tr>';
                return;
            }
            
            tbody.innerHTML = data.accounts.map(account => `
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 16px; font-family: monospace; font-size: 13px;">${account.id}</td>
                    <td style="padding: 16px; font-weight: 600;">${account.name}</td>
                    <td style="padding: 16px; font-family: monospace; font-size: 13px; color: #6b7280;">${account.domain}</td>
                    <td style="padding: 16px;">
                        <span style="background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                            ${account.users_count} users
                        </span>
                    </td>
                    <td style="padding: 16px;">
                        ${account.is_active 
                            ? '<span style="color: #10b981; font-weight: 600;">● Active</span>' 
                            : '<span style="color: #ef4444; font-weight: 600;">● Inactive</span>'}
                    </td>
                    <td style="padding: 16px; font-size: 13px; color: #6b7280;">${new Date(account.created_at).toLocaleDateString()}</td>
                    <td style="padding: 16px; text-align: right;">
                        <button onclick='editAccount(${JSON.stringify(account)})' class="btn alt" style="font-size: 13px; padding: 6px 12px;">Edit</button>
                        <button onclick="deleteAccount(${account.id}, '${account.name.replace(/'/g, "\\'")}', ${account.users_count})" class="btn pill-miss" style="font-size: 13px; padding: 6px 12px; margin-left: 8px;">Delete</button>
                    </td>
                </tr>
            `).join('');
            
        } catch (error) {
            console.error('Error loading accounts:', error);
        }
    }

    // Edit account
    function editAccount(account) {
        document.getElementById('editAccountId').value = account.id;
        document.getElementById('editAccountName').value = account.name;
        document.getElementById('editAccountDomain').value = account.domain;
        document.getElementById('editAccountStatus').value = account.is_active ? '1' : '0';
        document.getElementById('editAccountError').innerHTML = '';
        
        document.getElementById('editModal').classList.add('show');
    }

    // Close edit modal
    function closeEditModal() {
        document.getElementById('editModal').classList.remove('show');
    }

    // Submit edit form
    document.getElementById('editAccountForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const id = document.getElementById('editAccountId').value;
        const name = document.getElementById('editAccountName').value;
        const domain = document.getElementById('editAccountDomain').value;
        const isActive = document.getElementById('editAccountStatus').value === '1';
        const errorDiv = document.getElementById('editAccountError');
        
        try {
            const response = await fetch(`${ACCOUNTS_API}/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    name: name,
                    domain: domain,
                    is_active: isActive,
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                closeEditModal();
                loadAccounts();
            } else {
                errorDiv.innerHTML = '✗ ' + (data.error || 'Error updating account');
            }
        } catch (error) {
            errorDiv.innerHTML = '✗ Error: ' + error.message;
        }
    });

    // Delete account
    async function deleteAccount(id, name, usersCount) {
        if (usersCount > 0) {
            alert(`Cannot delete "${name}" - it has ${usersCount} assigned users. Remove users first.`);
            return;
        }
        
        if (!confirm(`Are you sure you want to delete account "${name}"?`)) {
            return;
        }
        
        try {
            const response = await fetch(`${ACCOUNTS_API}/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('Account deleted successfully!');
                loadAccounts();
            } else {
                alert('Error: ' + (data.error || 'Failed to delete account'));
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }

    // Close modal when clicking outside
    document.getElementById('editModal').addEventListener('click', (e) => {
        if (e.target.id === 'editModal') {
            closeEditModal();
        }
    });
</script>
</body>
</html>
