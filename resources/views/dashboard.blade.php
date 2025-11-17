<!doctype html>
@auth
    <!-- User is logged in -->
@else
    <script>
        // Redirect to login if not authenticated
        window.location.href = '/login';
    </script>
@endauth
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>AI Visibility (PHP + JS) — Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script>
    window.userApiKey = @json(auth()->user()?->api_key);
  </script>
</head>

<body>
  <!-- Header with Account Switcher & User Menu -->
  <div style="background: white; border-bottom: 2px solid #e5e7eb; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <!-- Left: Logo & Account Switcher -->
    <div style="display: flex; align-items: center; gap: 20px;">
      <h1 style="font-size: 22px; font-weight: 700; color: #374151; margin: 0;">
        🔍 AI Visibility Tracker
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
            Switch Account
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
     @if(!auth()->user()->isViewerFor(session('account_id')))
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
    @endif
  </div>

  <!-- Hidden logout form -->
  <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
      @csrf
  </form>

  <!-- Navigation Tabs -->
  <div class="tabs">
    
    <button type="button" class="btn pill-info" id="tabDashboard">Dashboard</button>
    @if(!auth()->user()->isViewerFor(session('account_id')))
    <button type="button" class="btn pill-ok alt" id="tabPrompts">Prompts</button>
    <button type="button" class="btn pill-or alt" id="tabBrands">Brands</button>
    @endif
    <button type="button" class="btn pill-ok alt" id="tabPerformance">Performance</button>
    @if(!auth()->user()->isViewerFor(session('account_id')))
    <button type="button" class="btn pill-info alt" id="tabConfig">Config</button>
    <button type="button" class="btn pill-info alt" id="tabAutomation" >⚙️ Automation</button>    
    @endif
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
  </div>

  <!-- DASHBOARD -->
   
  <section id="viewDashboard" style="background-color: #f2f5f5;padding: 25px;">
   
    <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 12px;">
      @if(auth()->user()->canRunQueries(session('account_id')))
          <label>Model:</label>
          <select id="runModel" style="padding: 6px 12px; border-radius: 4px; border: 1px solid #d1d5db;">
            <option value="">Default</option>
            <option value="gpt-4o-mini">gpt-4o-mini</option>
            <option value="gpt-4o">gpt-4o</option>
            <option value="gpt-4-turbo">gpt-4-turbo</option>
          </select>
          
          <label style="margin-left: 16px;">Temperature:</label>
          
          <input id="runTemp" type="number" step="0.1" min="0" max="2" value="0.2" 
                style="width: 80px; padding: 6px 12px; border-radius: 4px; border: 1px solid #d1d5db;">
      <button id="runBtn" onclick="runSampler()" class="btn">Run GPT</button>
      <button type="button" class="btn" id="runGaiBtn" style="margin-left:8px">Run Google AIO</button>
      <span id="runStatus" style="margin-left: 8px;"></span>
      @endif
    </div>
    @if(auth()->user()->canRunQueries(session('account_id')))
      <!-- Optional -->
      <input id="aioHl" placeholder="hl (e.g., en)" >
      <input id="aioGl" placeholder="gl (e.g., us)" >
      <input id="aioLocation" placeholder="location (e.g., Miami, Florida)">
    @endif
    <span id="runStatus" class="mono"></span>
    <div class="mbop">
      <div id="kpis" class="row" style="margin-top:16px"></div>
      <div class="mbran">
        <h2 style="margin-top:24px">Mentions by Brand</h2>
        <ul id="brandList"></ul>
      </div>
      <div class="mopps mopps-scroll">
        <h2 style="margin-top:24px">Missed Opportunities</h2>
        <table>
          <thead>
            <tr>
              <th>Topics</th>
              <th>Prompt</th>
              <th>Volume</th>
              <th>Priority</th>
            </tr>
          </thead>
          <tbody id="missedTable"></tbody>
        </table>
        <div id="missedPager" style="display:flex;gap:8px;align-items:center;margin:8px 0">
      <button class="btn pill-info" type="button" id="missedPgPrev" disabled="">Prev</button>
      <span id="missedPgInfo"></span>
      <button class="btn pill-info" type="button" id="missedPgNext">Next</button>
    </div>
      </div>
    </div>
    <div id="intChipCont" class="intent-chips-container"></div>
    <h2 style="margin-top:24px">Mentions (prompt-level)</h2>
    <div style="display:flex;gap:8px;align-items:center;margin:8px 0">
      <label class="mono" style="font-size:14px;">Brand:</label>
      <select id="mentionsBrand">
        <option value="">All</option>
      </select>
      <label class="mono" style="font-size:14px; margin-left:8px">Source:
        <select id="mentionsSource">
          <option value="all">All</option>
          <option value="gpt">GPT</option>
          <option value="google-ai-overview">Google AIO</option>
        </select>
      </label>
      <label for="mentionsSentiment" class="mono" style="font-size:14px; margin-left:8px">Sentiment:
        <select id="mentionsSentiment">
          <option value="">All</option>
          <option value="positive">Positive</option>
          <option value="neutral">Neutral</option>
          <option value="negative">Negative</option>
        </select>
      </label>
      <label class="mono" style="font-size:14px; margin-left:8px">Intent:
        <select id="mentionsIntent" class="thin">
          <option value="">All</option>
          <option value="informational">Informational</option>
          <option value="navigational">Navigational</option>
          <option value="transactional">Transactional</option>
          <option value="other">Other</option>
        </select>
      </label>
      <label class="mono" style="font-size:14px; margin-left:8px">Search:</label>
      <input id="mentionsQuery" placeholder="filter by prompt/answer" style="flex:1;">
      <label class="mono" style="font-size:14px; margin-left:8px">
        <input type="checkbox" id="mentionsAll"> All runs
      </label>
      <button type="button" class="btn pill-ok" style="font-weight: 700;" id="mentionsReload">Reload</button>
      <!-- Mentions Section Header -->
    <div class="section-header" style="display: flex; justify-content: space-between; align-items: center;">      
      <!-- Export Button Group -->
      <div style="position: relative; display: inline-block;">
        <button 
          id="exportMenuBtn" 
          class="btn pill-ok" 
          style="padding: 8px 16px; display: flex; align-items: center; gap: 8px;"
        >
          <span>📥</span>
          <span>Export</span>
          <span style="margin-left: 4px;">▼</span>
        </button>
        
        <!-- Dropdown Menu -->
        <div 
          id="exportMenu" 
          class="hidden"
          style="
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 4px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 1000;
          "
        >
          <button 
            id="exportCSVBtn" 
            class="export-option"
            style="
              width: 100%;
              padding: 12px 16px;
              text-align: left;
              border: none;
              background: white;
              cursor: pointer;
              display: flex;
              align-items: center;
              gap: 12px;
              transition: background 0.2s;
            "
            onmouseover="this.style.background='#f3f4f6'"
            onmouseout="this.style.background='white'"
          >
            <span style="font-size: 20px;">💾</span>
            <div>
              <div style="font-weight: 600; font-size: 14px;">Download CSV</div>
              <div style="font-size: 12px; color: #6b7280;">For Excel, analysis</div>
            </div>
          </button>
          
          <div style="height: 1px; background: #e5e7eb; margin: 0 8px;"></div>
          
          <button 
            id="exportSheetsBtn" 
            class="export-option"
            style="
              width: 100%;
              padding: 12px 16px;
              text-align: left;
              border: none;
              background: white;
              cursor: pointer;
              display: flex;
              align-items: center;
              gap: 12px;
              transition: background 0.2s;
            "
            onmouseover="this.style.background='#f3f4f6'"
            onmouseout="this.style.background='white'"
          >
            <span style="font-size: 20px;">📊</span>
            <div>
              <div style="font-weight: 600; font-size: 14px;">Open in Looker Studio</div>
              <div style="font-size: 12px; color: #6b7280;">Create live dashboard</div>
            </div>
          </button>
        </div>
      </div>
    </div>
    </div>
    
    <div class="table-wrap compact">
    <table id="mentionsTable" class="table-mentions">
      <thead>
        <tr>
          <th>Date</th>
          <th>Topics</th>
          <th>Prompt</th>
          <th>Brand</th>
          <th>Alias</th>
          <th>Sentiment</th>
          <th>Intent</th>
          <th>Answer Snippet</th>
          <th>Anchor</th> <!-- NEW -->
          <th>Found URL</th>
          <th>Found In</th> <!-- NEW -->
          <th>View Response</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    </div>
    <div id="mentions-pager" style="display:flex;gap:8px;align-items:center;margin:8px 0">
      <button class="btn pill-info" type="button" id="mentPgPrev">Prev</button>
      <span id="mentPgInfo"></span>
      <button class="btn pill-info" type="button" id="mentPgNext">Next</button>
    </div>
  </section>

  <!-- PROMPTS -->
  <section id="viewPrompts" class="hidden" style="background-color: #f2f5f5;padding: 25px;">
    <h2>Manage Prompts</h2>
    <div style="display: flex;">
      <div style="width: 30%; margin-right: 20px;">
        <form id="promptForm" onsubmit="return false" style="display:grid;gap:8px;align-items:center">
          <input id="pCategory" placeholder="Topics">
          <input id="pText" placeholder="Prompt text">
          <input id="pVolume" placeholder="Prompt volume">
          <button type="button" class="btn pill-ok" id="addPromptBtn">Add</button>
        </form>
      </div>      
      <div style="display: flex; flex-direction: column; gap: 10px;">
        <label class="checkbox">
          <input type="file" id="promptsCsvFile" accept=".csv,text/csv">
          <input type="checkbox" id="promptsReplace"> Replace all (wipe then import)
        </label>
        <button type="button" id="promptsImportBtn" class="btn pill-info">Import CSV</button>
        <span id="promptsCsvStatus" class="mono"></span>
      </div>
    </div>
    <div class="csv-controls">
      <div>
        <label for="promptsScope"><b>Scope:</b></label>
        <select id="promptsScope" style="margin-left:8px;">
          <option value="latest">Latest run</option>
          <option value="all">All runs</option>
        </select>
      </div>
      <div>
        <label for="promptsActive"><b>Status Filter:</b></label>
        <select id="promptsActive" style="margin-left:8px;">
          <option value="">All</option>
          <option value="active">Active only</option>
          <option value="paused">Paused only</option>
        </select>
      </div>
      
      <div>
          <button type="button" id="promptsExportBtn" class="btn pill-or">Export CSV</button>
      </div>
      <div id="promptsBulkBar" class="hidden" style="display:flex;gap:8px;align-items:center;margin:8px 0;">
        <span><b>Bulk Actions:</b></span>
        <button class="btn pill-or"  id="pbPause">Pause</button>
        <button class="btn pill-ok"  id="pbResume">Resume</button>
        <button class="btn pill-miss" id="pbDelete">Delete</button>
        <span class="mono" id="pbCount" style="opacity:.7">0 selected</span>
      </div>
    </div>
    <table id="promptsTable" style="margin-top:12px">
      <thead>
        <tr>
          <th style="width:36px;text-align: center;">
            <input type="checkbox" id="promptsSelectAll" aria-label="Select all prompts on this page">
          </th>
          <th>Topics</th>
          <th>Prompt</th>
          <th>Source</th>
          <th>Volume</th>
          </th>
          <th>Status</th>
          <th>Mentions</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <!-- Pending Suggestions -->
    <h2 style="margin-top:28px">Pending Suggestions</h2>

    <div id="pending-filters" class="csv-controls">
      <div>
        <label for="f-source"><b>Source:</b></label>
      <select id="f-source" style="margin-left:8px;">
        <option value="">Any source</option>
        <option value="ai-gpt">ai-gpt</option>
        <option value="ai-gpt-branded">ai-gpt-branded</option>
        <option value="ai-gemini">ai-gemini</option>
        <option value="ai-gemini-branded">ai-gemini-branded</option>
        <option value="paa-serpapi">paa-serpapi</option>
        <option value="paa-serpapi-branded">paa-serpapi-branded</option>
      </select>
      </div>
      <div>
      <label for="f-lang"><b>Language:</b></label>
      <select id="f-lang" style="margin-left:8px;">
        <option value="">Any lang</option>
        <option value="en">en</option>
        <option value="es">es</option>
        <option value="pt">pt</option>
      </select>
      </div>
      <div>
      <label for="f-geo"><b>Country:</b></label>
      <select id="f-geo" style="margin-left:8px;">
        <option value="">Any Contry</option>
        <option value="us">us</option>
        <option value="gb">gb</option>
        <option value="de">de</option>
      </select>
      </div>
      <div>
      <label for="f-minscore"><b>Min score:</b></label>
      <select id="f-minscore" style="margin-left:8px;">
        <option value="">Any Score</option>
        <option value="Low">Low</option>
        <option value="Mid">Mid</option>
        <option value="High">High</option>
      </select>
      </div>
      <div id="sugBulkBar" class="hidden" style="display:flex;gap:8px;align-items:center;margin:8px 0;">
        <label for="bulkApproveSug"><b>Bulk Actions:</b></label>
        <button id="bulkApproveSug" class="btn pill-ok">Approve Selected</button>
        <button id="bulkRejectSug" class="btn pill-miss">Reject Selected</button>
        <span class="mono" id="sbCount" style="opacity:.7">0 selected</span>
      </div>
    </div>

    <div style="overflow:auto">
      <table id="pending-table" class="tbl"
        style="width:100%; border-collapse:separate; border-spacing:0; min-width:900px">
        <thead>
          <tr>
            <th style="width:36px; text-align:center;"><input type="checkbox" id="sugSelectAll"></th>
            <th>Text</th>
            <th style="width:90px">Score</th>
            <th style="width:110px">Confidence</th>
            <th style="width:110px">Source</th>
            <th style="width:70px">Lang</th>
            <th style="width:70px">Geo</th>
            <th style="width:160px">Collected</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div id="pending-pager" style="display:flex;gap:8px;align-items:center;margin:8px 0">
      <button class="btn pill-info" type="button" id="pg-prev">Prev</button>
      <span id="pg-info"></span>
      <button class="btn pill-info" type="button" id="pg-next">Next</button>
    </div>
  </section>

  <!-- BRANDS -->
  <section id="viewBrands" class="hidden" style="background-color: #f2f5f5;padding: 25px;">
    <h2>Manage Brands & Aliases</h2>
    <div style="display: flex;">
      <div style="width: 30%; margin-right: 20px;">
        <form id="brandForm" onsubmit="return false" style="display:grid;gap:8px;align-items:center">
          <label for="bId">Domain or section </label>
          <input id="bId" placeholder="Example: betus.com.pa, betus.com.pa/online-casino (no http/https, no trailing slash)." />
          <label for="bName">Brand Name: </label>
          <input id="bName" placeholder="Display name" />
          <label for="bAliases">Aliases (comma separated): </label>
          <input id="bAliases" placeholder="e.g., warby, warby parker, warbyparker" />
          <button type="button" class="btn pill-ok" id="saveBrandBtn">Save / Update</button>
        </form>
      </div>
      <div style="display: flex; flex-direction: column; gap: 10px;">

        <label class="checkbox"> Import File</label>
        <label class="checkbox">
          <input type="file" id="brandsCsvFile" accept=".csv,text/csv">
          <input type="checkbox" id="brandsReplace"> Replace all (wipe then import)
        </label>
        <!-- Optional: set primary via query param on import -->
        <input type="text" id="brandsPrimary" placeholder="primary brand_id (optional)" class="mono">
        <button type="button" id="brandsImportBtn" class="btn pill-info">Import CSV</button>
        <span id="brandsCsvStatus" class="mono"></span>
      </div>
    </div>
    <div class="csv-controls">
      <div>
      <label>Primary brand: </label>
      <select id="primarySelect"></select>
      <button type="button" class="btn pill-info" id="setPrimaryBtn" style="margin-left:8px">Set Primary</button>
      <span class="mono" id="primaryStatus"></span>
    </div>
      <div>
        <button type="button" id="brandsExportBtn" class="btn pill-or">Export CSV</button>
      </div>
    </div>

    <table id="brandsTable" style="margin-top:12px">
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Aliases</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </section>

  <!-- Performance -->
  <section id="performanceTab" style="background-color: #f2f5f5;padding: 25px;">
    <!-- This is the container the tab-switcher toggles -->
    <div id="viewPerformance" class="hidden" style="padding: 20px;">
      <h2>Performance</h2>
      <h3>Visibility</h3>

      <!-- Controls row shared by both cards -->
      <div class="row" style="display:flex; gap:12px; align-items:center; margin:10px 0;">
        <label>From <input type="date" id="perfFrom"></label>
        <label>To <input type="date" id="perfTo"></label>
        <label>Grouped by: 
          <select id="perfGroupBy">
            <option value="week" selected>Week</option>
            <option value="day">Day</option>
          </select>
        </label>
        <select id="perfModel">
          <option value="all">All sources</option>
          <option value="gpt">GPT</option>
          <option value="google-ai-overview">AIO</option>
        </select>
        <button type="button" class="btn pill-ok" id="perfReload">Reload</button>

      </div>

      <!-- Two cards laid out side by side -->
      <div class="row" style="display:flex; gap:20px;">
        <!-- Brand Mentions Over Time -->
        <div class="card" style="flex:1; min-width:600px;background-color: white;">
          <!-- Mentions legend -->
          <div style="margin-left:auto; display:flex; gap:14px; align-items:baseline; font-size:13px;">
            <h4>Brand Mentions Over Time</h4>
            <label><input type="checkbox" id="perfShowMentioned" checked>
              <span
                style="width:12px;height:12px;background:#6EE7B7;border:1px solid #10B981;display:inline-block;margin-left:6px"></span>
              Mentioned
            </label>
            <label><input type="checkbox" id="perfShowNot" checked>
              <span
                style="width:12px;height:12px;background:#C4B5FD;border:1px solid #8B5CF6;display:inline-block;margin-left:6px"></span>
              Not mentioned
            </label>
            <label><input type="checkbox" id="perfShowNone" checked>
              <span
                style="width:12px;height:12px;background:#FCA5A5;border:1px solid #EF4444;display:inline-block;margin-left:6px"></span>
              No brands found
            </label>
          </div>
          <div id="perfMentionsChart"
            style="width:100%; overflow:auto; border-top:1px dashed #eee; padding-top:8px; min-height:280px;"></div>
          <div class="mono" style="font-size:12px;color:#8a8a8a;margin-top:6px;">
            Weekly buckets. “No brands found” is hidden when a brand filter is applied.
          </div>
        </div>

        <!-- Website Citations Over Time -->
        <div class="card" id="perfCitationsCard" style="flex:1; min-width:600px;background-color: white;">
          <div style="margin-left:auto; display:flex; gap:14px; align-items:baseline; font-size:13px;">
            <h4>Website Citations Over Time</h4>
            <!-- Citations legend (checkboxes to match JS) -->
            <div class="legend" style="margin-top:8px; display:flex; gap:14px; font-size:12px;">
              <label><input type="checkbox" id="perfCited" checked>
                <span
                  style="width:12px;height:12px;background:#6EE7B7;border:1px solid #10B981;display:inline-block;margin-left:6px"></span>
                Cited
              </label>
              <label><input type="checkbox" id="perfNotCited" checked>
                <span
                  style="width:12px;height:12px;background:#C4B5FD;border:1px solid #8B5CF6;display:inline-block;margin-left:6px"></span>
                Not cited
              </label>
            </div>
          </div>
          <div id="citationsSpark"
            style="width:100%; overflow:auto; border-top:1px dashed #eee; padding-top:8px; min-height:280px;"></div>
          <span class="sub mono" id="perfCitationsMeta" style="font-size:12px;color:#8a8a8a;margin-top:6px;"></span>
        </div>
      </div>
      <div class="row" style="display:flex; gap:20px;">
        <!-- Intent Performance Over Time -->
        <div class="card" id="perfIntentCard" style="margin-top:20px;min-width:600px;background-color: white;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="font-weight:600">
              <h4>Intent Performance Over Time</h4>
            </div>
            <!-- uses the same From/To/Source/Reload controls you already have -->
          </div>

          <!-- Legend -->
          <div id="perfIntentLegend"
            style="display:flex;gap:14px;align-items:center;margin:10px 0 2px 4px;font-size:13px;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" id="perfIntentInfo" checked>
              <span
                style="width:12px;height:12px;background:#93C5FD;border:1px solid #3B82F6;display:inline-block"></span>
              Informational
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" id="perfIntentNav" checked>
              <span
                style="width:12px;height:12px;background:#A7F3D0;border:1px solid #10B981;display:inline-block"></span>
              Navigational
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" id="perfIntentTran" checked>
              <span
                style="width:12px;height:12px;background:#FDE68A;border:1px solid #F59E0B;display:inline-block"></span>
              Transactional
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" id="perfIntentOther" checked>
              <span
                style="width:12px;height:12px;background:#E5E7EB;border:1px solid #9CA3AF;display:inline-block"></span>
              Other
            </label>
          </div>

          <div id="perfIntentChart" style="width:100%;overflow:auto;border-top:1px dashed #eee;padding-top:8px;"></div>

          <div style="font-size:12px;color:#8a8a8a;margin-top:6px;">Weekly buckets; counts reflect responses with at
            least one brand mention.</div>
        </div>
        <!-- Persona Performance Over Time -->
        <div class="card" id="perfPersonaCard" style="margin-top:20px;min-width:600px;background-color: white;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="font-weight:600">
              <h4>Persona Performance Over Time</h4>
            </div>
          </div>
          <div id="perfPersonaLegend" class="legend"
            style="display:flex;gap:14px;align-items:center;margin:10px 0 2px 4px;font-size:13px;"></div>
          <div id="perfPersonaChart" style="width:100%;overflow:auto;border-top:1px dashed #eee;padding-top:8px;"></div>
          <div style="font-size:12px;color:#8a8a8a;margin-top:6px;" id="perfPersonaMeta"></div>
        </div>

      </div>
      <div class="row" style="display:flex; gap:20px;">
        <!-- Sentiment Over Time -->
        <div class="card" id="perfSentCard" style="margin-top:20px;min-width:600px;background-color: white;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="font-weight:600">
              <h4>Brand Sentiment Over Time</h4>
            </div>
          </div>
          <div class="legend" style="display:flex;gap:14px;align-items:center;margin:10px 0 2px 4px;font-size:13px;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" id="perfSentPos" checked>
              <span
                style="background:#10B981;width:12px;height:12px;border:1px solid #9CA3AF;display:inline-block"></span>
              Positive
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" id="perfSentNeu" checked>
              <span
                style="background:#9CA3AF;width:12px;height:12px;border:1px solid #9CA3AF;display:inline-block"></span>
              Neutral
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
              <input type="checkbox" id="perfSentNeg" checked>
              <span
                style="background:#EF4444;width:12px;height:12px;border:1px solid #9CA3AF;display:inline-block"></span>
              Negative
            </label>
          </div>

          <div style="width:100%;overflow:auto;border-top:1px dashed #eee;padding-top:8px;" id="perfSentChart"></div>
          <div style="font-size:12px;color:#8a8a8a;margin-top:6px;" id="perfSentMeta"></div>
        </div>
        <!-- Sentiment Sources -->
        <div class="card" id="sentSourcesCard" style="margin-top:20px;min-width:600px;background-color: white;">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <div><b>Sentiment Sources</b></div>
            <div style="display:flex;gap:8px;align-items:center;">
              <button class="btn" id="sentExploreBtn">Explore</button>
            </div>
          </div>
          <div class="card-body">
            <div style="display:flex;gap:12px;margin-bottom:8px;">
              <label><input type="radio" name="sentPol" value="positive" checked> Positive</label>
              <label><input type="radio" name="sentPol" value="negative"> Negative</label>
            </div>
            <!-- Polarity toggle -->
             <div style="display: flex;">
            <div style="margin-bottom:12px;">
              <label style="font-weight:600;margin-right:8px;">Show by:</label>
              <select id="sentMetric" class="form-control" style="display:inline-block;width:auto;">
                <option value="mentions">By Brand Mentions</option>
                <option value="citations">By Website Citations</option>
              </select>
            </div>
            <!-- NEW: Brand filter -->
            <div style="margin-bottom:12px;">
              <label style="font-weight:600;margin-right:8px;">Brand Filter:</label>
              <select id="sentBrandFilter" class="form-control" style="display:inline-block;width:auto;">
                <option value="">All Brands</option>
                <!-- Will be populated dynamically -->
              </select>
            </div></div>
            <div id="sentSourcesList"></div>
          </div>
        </div>
        <!-- Explore Modal -->
        <div id="sentModal" class="modal hidden">
          <div class="modal-content" style="max-width:1400px;width:96%;background-color: #ffffff;padding: 20px;">
            <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;">
              <b>Sentiment Analysis</b>
              <button class="btn alt" id="sentModalClose">Close</button>
            </div>

            <div style="display:flex;gap:10px;margin:8px 0;">
              <button class="btn" id="sentPolPos">Positive</button>
              <button class="btn alt" id="sentPolNeg">Negative</button>
            </div>

            <!-- keyword cloud -->
            <div id="sentCloud"
              style="min-height:140px;border:1px dashed #ddd;border-radius:8px;padding:10px;margin-bottom:8px;display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:10px;">
            </div>

            <!-- toolbar row -->
            <div style="display:flex;gap:12px;margin:6px 0;color:#666;font-size:12px;">
              <span>No Active Filters</span>
              <span>Sorted by 0 Columns</span>
            </div>

            <!-- table -->
            <div style="overflow:auto;max-height:55vh;">
              <table class="table" id="sentTable">
                <thead>
                  <tr>
                    <th>Statement</th>
                    <th>Source</th>
                    <th>Prompt</th>
                    <th>Topic</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
              <div id="sentPagerInfo" class="mono"></div>
              <div style="display:flex;gap:8px;align-items:center;">
                <button class="btn alt" id="sentPrev">Prev</button>
                <button class="btn" id="sentNext">Next</button>
                <select id="sentPageSize">
                  <option>20</option>
                  <option>50</option>
                  <option>100</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="row" style="display:flex; gap:20px;">
        <!-- Market Share row -->
        <div class="row" style="display:flex; gap:20px;">
          <!-- Market Share (Donut) -->
          <div class="card" id="marketShareCard" style="margin-top:20px; min-width:600px; background-color:#fff;">
            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
              <h4>Market Share</h4>
              <div style="display:flex; gap:8px; align-items:center;">
                <select id="msBy">
                  <option value="mentions" selected>By Brand Mentions</option>
                  <option value="citations">By Website Citations</option>
                </select>
                <button class="btn" id="marketExploreBtn">Explore</button>
              </div>
            </div>
            <div id="marketDonut"
              style="width:100%; overflow:auto; border-top:1px dashed #eee; padding-top:8px; min-height:260px;"></div>
            <div id="marketLegend" class="mono" style="font-size:12px; color:#8a8a8a; margin-top:6px;"></div>
          </div>

          <!-- Market Share Trends (Lines) -->
          <div class="card" id="marketTrendCard" style="margin-top:20px; min-width:600px; background-color:#fff;">
            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
              <h4>Market Share Trends</h4>
              <span class="mono" id="marketTrendMeta" style="font-size:12px; color:#8a8a8a;"></span>
            </div>
            <div id="marketTrend"
              style="width:100%; overflow:auto; border-top:1px dashed #eee; padding-top:8px; min-height:260px;"></div>
          </div>
        </div>
        <div id="marketModal" class="modal hidden">
          <!--Market Share Modal Window-->
          <div class="modal-content" style="max-width:1400px; width:96%; background:#fff; padding:20px;">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
              <b>Market Share</b>
              <button class="btn alt" id="marketModalClose">Close</button>
            </div>
            <div style="display:flex; gap:8px; align-items:center; margin:6px 0;">
              <select id="msByModal">
                <option value="mentions" selected>By Brand Mentions</option>
                <option value="citations">By Website Citations</option>
              </select>
            </div>
            <div id="marketModalDonut"
              style="min-height:240px; border:1px dashed #ddd; border-radius:8px; padding:10px; margin-bottom:12px;">
            </div>
            <div style="overflow:auto; max-height:55vh;">
              <table class="table" id="marketTable">
                <thead>
                  <tr></tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
              <div id="marketPagerInfo" class="mono"></div>
              <div style="display:flex; gap:8px; align-items:center;">
                <button class="btn alt" id="marketPrev">Prev</button>
                <button class="btn" id="marketNext">Next</button>
                <select id="marketPageSize">
                  <option>10</option>
                  <option>20</option>
                  <option>30</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
  <!-- CONFIG -->
  <section id="viewConfig" class="hidden" style="background-color: #f2f5f5;padding: 25px;">
    <h2>Config</h2>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
      <!-- Topics panel -->
      <div class="card">
        <h3>Topics</h3>
        <p class="mono" style="font-size:12px;margin-top:-8px">One topic per line. Saving will auto-generate suggestions
          into <i>raw_suggestions</i>.</p>
        <textarea id="cfgTopics" class="fixed-textarea" placeholder="NFL Betting Lines&#10;BetUS Bonus Codes"></textarea>
        <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
          <button class="btn pill-ok" id="cfgSaveTopics" type="button">Save & Generate</button>
          <span id="cfgTopicsMsg" class="mono"></span>
        </div>

        <h4 style="margin-top:16px">Existing Topics</h4>
        <table id="cfgTopicsTable" class="tbl" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Personas</th>
              <th>Active</th>
              <th>Last Gen</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- Personas panel -->
      <div class="card">
        <h3>Personas</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <input id="cfgPersonaName" placeholder="Persona name">
          <select id="cfgPersonaBrand"></select>
          <textarea id="cfgPersonaDesc" rows="6" placeholder="Persona description (2–3 paragraphs)"></textarea>
          <textarea id="cfgPersonaAttrs" rows="6" placeholder='Attributes JSON (optional)'></textarea>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
          <button class="btn pill-ok" id="cfgSavePersona" type="button">Save Persona</button>
          <span id="cfgPersonaMsg" class="mono"></span>
        </div>

        <h4 style="margin-top:16px">Personas</h4>
        <table id="cfgPersonasTable" class="tbl" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Domain</th>
              <th>Active</th>
              <th>Last Gen</th>
              <th>Actions</th>
              <th>Description</td>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </section>
  <!--Automation tab-->
  <div id="viewAutomation" class="tab-content hidden">
            <div class="container">
                <div class="page-header">
                    <h1>🤖 Automation Settings</h1>
                    <p class="subtitle">Configure automated runs for AI visibility tracking</p>
                </div>
                <!-- Budget Tracking Section -->
            <div class="section">
              <div class="section-header">
                <h2>💰 Budget Tracking</h2>
              </div>
              
              <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div class="card">
                  <div style="color: #666; font-size: 12px; margin-bottom: 4px;">MONTHLY BUDGET</div>
                  <div id="budget-total" style="font-size: 24px; font-weight: 600;">$200.00</div>
                  <button id="editBudgetBtn" class="btn" style="margin-top: 8px; padding: 4px 12px; font-size: 12px;">Edit</button>
                </div>
                
                <div class="card">
                  <div style="color: #666; font-size: 12px; margin-bottom: 4px;">SPENT THIS MONTH</div>
                  <div id="budget-spent" style="font-size: 24px; font-weight: 600; color: #f59e0b;">$0.00</div>
                  <div id="budget-breakdown" style="font-size: 11px; color: #999; margin-top: 4px;">
                    Prompts: $0.00 • Sentiment: $0.00
                  </div>
                </div>
                
                <div class="card">
                  <div style="color: #666; font-size: 12px; margin-bottom: 4px;">REMAINING</div>
                  <div id="budget-remaining" style="font-size: 24px; font-weight: 600; color: #10b981;">$200.00</div>
                  <div id="budget-percent" style="font-size: 11px; color: #999; margin-top: 4px;">
                    0% used
                  </div>
                </div>
                
                <div class="card">
                  <div style="color: #666; font-size: 12px; margin-bottom: 4px;">PROJECTED END OF MONTH</div>
                  <div id="budget-projected" style="font-size: 24px; font-weight: 600;">$0.00</div>
                  <div id="budget-status" style="font-size: 11px; margin-top: 4px;">
                    <span class="pill pill-ok">On Track</span>
                  </div>
                </div>
              </div>
              
              <!-- Budget Progress Bar -->
              <div style="background: #f3f4f6; border-radius: 8px; height: 24px; overflow: hidden; position: relative; margin-bottom: 10px;">
                <div id="budget-progress-bar" style="background: linear-gradient(90deg, #10b981, #f59e0b); height: 100%; width: 0%; transition: width 0.3s;"></div>
                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #666;">
                  <span id="budget-progress-text">0% of budget used</span>
                </div>
              </div>
            </div>
            <!-- Edit Budget Modal -->
            <div id="editBudgetModal" class="budget-modal hidden">
              <div class="modal-overlay" id="budgetModalOverlay"></div>
              <div class="modal-dialog" style="max-width: 450px;">
                <div class="modal-content">
                  <div class="modal-header">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 600;">💰 Monthly Budget</h3>
                    <button class="modal-close" id="closeBudgetModal">&times;</button>
                  </div>
                  
                  <div class="modal-body" style="padding: 24px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: #374151;">
                      Set Your Monthly Budget
                    </label>
                    
                    <div style="position: relative;">
                      <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6b7280; font-size: 18px; font-weight: 600;">$</span>
                      <input 
                        type="number" 
                        id="budgetInput" 
                        step="0.01" 
                        min="0" 
                        max="10000" 
                        placeholder="200.00"
                        style="
                          width: 89%; 
                          padding: 12px 12px 12px 28px; 
                          border: 2px solid #e5e7eb; 
                          border-radius: 8px; 
                          font-size: 24px;
                          font-weight: 600;
                          text-align: right;
                          transition: border-color 0.2s;
                        "
                        onfocus="this.style.borderColor='#3b82f6'"
                        onblur="this.style.borderColor='#e5e7eb'"
                      />
                    </div>
                    
                    <div style="margin-top: 16px; padding: 12px; background: #f3f4f6; border-radius: 8px; font-size: 13px; color: #6b7280;">
                      <div style="display: flex; gap: 8px; align-items: start;">
                        <span style="font-size: 16px;">💡</span>
                        <div>
                          <strong style="color: #374151;">Recommended:</strong> $100-200/month<br>
                          <span style="font-size: 12px;">Covers ~50,000 mentions + 1,000 monitoring runs</span>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="modal-footer" style="display: flex; gap: 12px; justify-content: flex-end; padding: 16px 24px; background: #f9fafb; border-top: 1px solid #e5e7eb;">
                    <button 
                      id="cancelBudgetBtn" 
                      class="btn" 
                      style="padding: 10px 20px; border: 1px solid #d1d5db; background: white; color: #374151;"
                    >
                      Cancel
                    </button>
                    <button 
                      id="saveBudgetBtn" 
                      class="btn pill-ok" 
                      style="padding: 10px 24px; background: #10b981; color: white; font-weight: 600;"
                    >
                      Save Budget
                    </button>
                  </div>
                </div>
              </div>
            </div>
                <!-- Settings Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>⚙️ Configuration</h2>
                        <div class="badge" id="automationStatus">Loading...</div>
                    </div>
                    <div class="card-body">
                        <form id="automationSettingsForm">
                            <div class="form-grid">
                                <!-- Schedule -->
                                <div class="form-group">
                                    <label for="autoSchedule">Schedule</label>
                                    <select id="autoSchedule" name="schedule" class="form-control">
                                        <option value="weekly">Weekly</option>
                                        <option value="paused">Paused</option>
                                    </select>
                                    <small>When should automation run?</small>
                                </div>

                                <!-- Day of Week -->
                                <div class="form-group">
                                    <label for="autoScheduleDay">Day</label>
                                    <select id="autoScheduleDay" name="schedule_day" class="form-control">
                                        <option value="monday">Monday</option>
                                        <option value="tuesday">Tuesday</option>
                                        <option value="wednesday">Wednesday</option>
                                        <option value="thursday">Thursday</option>
                                        <option value="friday">Friday</option>
                                        <option value="saturday">Saturday</option>
                                        <option value="sunday">Sunday</option>
                                    </select>
                                    <small>Which day of the week?</small>
                                </div>

                                <!-- Time -->
                                <div class="form-group">
                                    <label for="autoScheduleTime">Time</label>
                                    <input type="time" id="autoScheduleTime" name="schedule_time" class="form-control" value="09:00">
                                    <small>What time? (24h format)</small>
                                </div>

                                <!-- Default Source -->
                                <div class="form-group">
                                    <label for="autoDefaultSource">Default Source</label>
                                    <select id="autoDefaultSource" name="default_source" class="form-control">
                                        <option value="all">All Sources</option>
                                        <option value="gpt">GPT Only</option>
                                        <option value="google_aio">Google AIO Only</option>
                                    </select>
                                    <small>Which sources to run?</small>
                                </div>

                                <!-- Max Runs Per Day -->
                                <div class="form-group">
                                    <label for="autoMaxRuns">Max Runs Per Day</label>
                                    <input type="number" id="autoMaxRuns" name="max_runs_per_day" class="form-control" value="10" min="1" max="50">
                                    <small>Safety limit (1-50)</small>
                                </div>

                                <!-- Notifications -->
                                <div class="form-group">
                                  <label class="checkbox-label">
                                      <input type="checkbox" id="autoNotifications" name="notifications_enabled">
                                      <span>Enable Notifications</span>
                                  </label>
                                  <small>Show alerts when runs complete</small>
                              </div>

                              <!-- NEW: Notification Email -->
                              <div class="form-group" id="emailField">
                                  <label for="autoNotificationEmail">Notification Email</label>
                                  <input 
                                      type="email" 
                                      id="autoNotificationEmail" 
                                      name="notification_email" 
                                      class="form-control" 
                                      placeholder="your@email.com"
                                  >
                                  <small>Where to send run completion emails</small>
                              </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">💾 Save Settings</button>
                                <div id="autoSettingsFeedback" class="feedback-message"></div>
                            </div>
                        </form>

                        <!-- Usage Stats -->
                        <div class="stats-row" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e5e7eb;">
                            <div class="stat-box">
                                <div class="stat-label">Runs Today</div>
                                <div class="stat-value" id="autoRunsToday">-</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Daily Limit</div>
                                <div class="stat-value" id="autoMaxRunsDisplay">-</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-label">Next Scheduled</div>
                                <div class="stat-value" id="autoNextRun">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manual Run Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>▶️ Manual Run</h2>
                    </div>
                    <div class="card-body">
                        <p>Trigger an immediate run to process prompts and update the dashboard.</p>
                        
                        <div class="form-group" style="max-width: 300px;">
                            <label for="runOnceSource">Select Source</label>
                            <select id="runOnceSource" class="form-control">
                                <option value="all">All Sources</option>
                                <option value="gpt">GPT Only</option>
                                <option value="google_aio">Google AIO Only</option>
                            </select>
                        </div>

                        <button id="btnRunOnce" class="btn btn-primary" style="margin-top: 12px;">
                            ▶️ Run Once
                        </button>
                        <div id="runOnceFeedback" class="feedback-message"></div>
                    </div>
                </div>

                <!-- Recent Runs Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>📊 Recent Runs</h2>
                        <button id="btnRefreshRuns" class="btn btn-secondary btn-sm">🔄 Refresh</button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="recentRunsTable" class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Type</th>
                                        <th>Source</th>
                                        <th>Status</th>
                                        <th>Prompts</th>
                                        <th>Mentions</th>
                                        <th>Duration</th>
                                        <th>Started</th>
                                        <th>By</th>
                                    </tr>
                                </thead>
                                <tbody id="recentRunsBody">
                                    <tr>
                                        <td colspan="9" class="text-center">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
  <!-- Response Viewer Modal -->
  <div id="respModal" class="modal hidden">
    <div class="modal__dialog">
      <div class="modal__head">
        <div id="modalTitle" class="modal__title"></div>
        <button id="respModalClose" class="btn alt" type="button">&times;</button>
      </div>
      <div id="respModalBody" class="modal__body"></div>
    </div>
  </div>
  <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
 
  <script src="{{ asset('assets/js/api-config.js') }}"></script>
  <script src="{{ asset('assets/js/app.js') }}"></script>
  <script src="{{ asset('assets/js/performance.js') }}"></script>
  <script src="{{ asset('assets/js/automation.js') }}"></script>
  <script src="{{ asset('assets/js/mentions.js') }}"></script>
  <script src="{{ asset('assets/js/notifications.js') }}"></script> 
  <script src="{{ asset('assets/js/siteHeader.js') }}"></script>

</body>
</html>
</body>

</html>