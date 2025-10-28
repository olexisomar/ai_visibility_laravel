// Automation State
const autoState = {
    settings: null,
    runs: [],
    polling: null,
};

// Load automation settings
async function loadAutomationSettings() {
    try {
        const res = await fetch(`${API_BASE}/automation/settings`);
        const data = await res.json();
        
        autoState.settings = data.settings;
        
        // Populate form
        document.getElementById('autoSchedule').value = data.settings.schedule;
        document.getElementById('autoScheduleDay').value = data.settings.schedule_day;
        document.getElementById('autoScheduleTime').value = data.settings.schedule_time;
        document.getElementById('autoDefaultSource').value = data.settings.default_source;
        document.getElementById('autoMaxRuns').value = data.settings.max_runs_per_day;
        document.getElementById('autoNotifications').checked = data.settings.notifications_enabled;
        
        // Update status badge
        const statusBadge = document.getElementById('automationStatus');
        if (data.settings.schedule === 'paused') {
            statusBadge.textContent = 'Paused';
            statusBadge.className = 'badge paused';
        } else {
            statusBadge.textContent = 'Active';
            statusBadge.className = 'badge active';
        }
        
        // Update stats
        document.getElementById('autoRunsToday').textContent = data.runs_today;
        document.getElementById('autoMaxRunsDisplay').textContent = data.max_runs_per_day;
        
        // Calculate next run
        updateNextRunDisplay(data.settings);
        
    } catch (error) {
        console.error('Failed to load automation settings:', error);
        showFeedback('autoSettingsFeedback', 'Failed to load settings', 'error');
    }
}

// Update automation settings
async function saveAutomationSettings(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const settings = {
        schedule: formData.get('schedule'),
        schedule_day: formData.get('schedule_day'),
        schedule_time: formData.get('schedule_time'),
        default_source: formData.get('default_source'),
        max_runs_per_day: parseInt(formData.get('max_runs_per_day')),
        notifications_enabled: formData.get('notifications_enabled') === 'on',
    };
    
    try {
        const res = await fetch(`${API_BASE}/automation/settings`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(settings),
        });
        
        const data = await res.json();
        
        if (res.ok) {
            showFeedback('autoSettingsFeedback', '✅ Settings saved successfully', 'success');
            loadAutomationSettings(); // Refresh
        } else {
            showFeedback('autoSettingsFeedback', '❌ ' + (data.message || 'Failed to save'), 'error');
        }
    } catch (error) {
        console.error('Failed to save settings:', error);
        showFeedback('autoSettingsFeedback', '❌ Network error', 'error');
    }
}

// Trigger manual run
async function runOnce() {
    const source = document.getElementById('runOnceSource').value;
    const btn = document.getElementById('btnRunOnce');
    
    btn.disabled = true;
    btn.textContent = '⏳ Starting...';
    
    try {
        const res = await fetch(`${API_BASE}/automation/run-once`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source }),
        });
        
        const data = await res.json();
        
        if (res.ok) {
            showFeedback('runOnceFeedback', `✅ Run started! ID: ${data.run.id}`, 'success');
            loadRecentRuns(); // Refresh table
            startPolling(data.run.id);
        } else {
            showFeedback('runOnceFeedback', '❌ ' + (data.error || data.message || 'Failed to start'), 'error');
        }
    } catch (error) {
        console.error('Failed to trigger run:', error);
        showFeedback('runOnceFeedback', '❌ Network error', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '▶️ Run Once';
    }
}

// Load recent runs
async function loadRecentRuns() {
    try {
        const res = await fetch(`${API_BASE}/automation/runs?limit=20`);
        const data = await res.json();
        
        autoState.runs = data.runs;
        renderRecentRuns(data.runs);
    } catch (error) {
        console.error('Failed to load recent runs:', error);
        document.getElementById('recentRunsBody').innerHTML = '<tr><td colspan="9" class="text-center" style="color: #ef4444;">Failed to load runs</td></tr>';
    }
}

// Render recent runs table
function renderRecentRuns(runs) {
    const tbody = document.getElementById('recentRunsBody');
    
    if (!runs || runs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center" style="opacity: 0.6;">No runs yet</td></tr>';
        return;
    }
    
    tbody.innerHTML = runs.map(run => {
        const statusClass = run.status;
        const duration = run.duration_seconds 
            ? (run.duration_seconds > 60 
                ? `${Math.floor(run.duration_seconds / 60)}m ${run.duration_seconds % 60}s`
                : `${run.duration_seconds}s`)
            : '—';
        
        const started = run.started_at 
            ? new Date(run.started_at).toLocaleString()
            : '—';
        
        return `<tr>
            <td>${run.id}</td>
            <td><span class="badge">${run.trigger_type}</span></td>
            <td>${run.source}</td>
            <td><span class="badge ${statusClass}">${run.status}</span></td>
            <td>${run.prompts_processed}</td>
            <td>${run.new_mentions}</td>
            <td>${duration}</td>
            <td style="font-size: 12px;">${started}</td>
            <td style="font-size: 12px;">${run.triggered_by || '—'}</td>
        </tr>`;
    }).join('');
}

// Poll for run status updates
function startPolling(runId) {
    if (autoState.polling) clearInterval(autoState.polling);
    
    autoState.polling = setInterval(async () => {
        try {
            const res = await fetch(`${API_BASE}/automation/runs/${runId}`);
            const data = await res.json();
            
            if (data.run.status === 'completed' || data.run.status === 'failed') {
                clearInterval(autoState.polling);
                autoState.polling = null;
                loadRecentRuns();
                loadAutomationSettings(); // Refresh stats
                
                if (data.run.status === 'completed') {
                    showFeedback('runOnceFeedback', `✅ Run completed! ${data.run.prompts_processed} prompts, ${data.run.new_mentions} mentions`, 'success');
                } else {
                    showFeedback('runOnceFeedback', `❌ Run failed: ${data.run.error_message}`, 'error');
                }
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }, 3000); // Poll every 3 seconds
}

// Calculate next scheduled run
function updateNextRunDisplay(settings) {
    const nextRunEl = document.getElementById('autoNextRun');
    
    if (settings.schedule === 'paused') {
        nextRunEl.textContent = 'Paused';
        nextRunEl.style.color = '#6b7280';
        return;
    }
    
    // Calculate next occurrence
    const now = new Date();
    const dayMap = { monday: 1, tuesday: 2, wednesday: 3, thursday: 4, friday: 5, saturday: 6, sunday: 0 };
    const targetDay = dayMap[settings.schedule_day];
    const [hour, minute] = settings.schedule_time.split(':');
    
    let nextRun = new Date();
    nextRun.setHours(parseInt(hour), parseInt(minute), 0, 0);
    
    // Find next occurrence of target day
    const daysUntil = (targetDay - now.getDay() + 7) % 7;
    if (daysUntil === 0 && now > nextRun) {
        // Same day but time passed, go to next week
        nextRun.setDate(nextRun.getDate() + 7);
    } else {
        nextRun.setDate(nextRun.getDate() + daysUntil);
    }
    
    nextRunEl.textContent = nextRun.toLocaleDateString() + ' ' + settings.schedule_time;
}

// Helper: Show feedback message
function showFeedback(elementId, message, type) {
    const el = document.getElementById(elementId);
    el.textContent = message;
    el.className = `feedback-message ${type}`;
    
    setTimeout(() => {
        el.className = 'feedback-message';
    }, 5000);
}

// Load budget stats
async function loadBudgetStats() {
    try {
        const res = await fetch(`${API_BASE}/automation/budget`, {
            headers: { 'X-API-Key': API_KEY }
        });
        
        const data = await res.json();
        const b = data.budget;
        const p = data.projections;
        
        // Update display
        document.getElementById('budget-total').textContent = `$${b.monthly_budget.toFixed(2)}`;
        document.getElementById('budget-spent').textContent = `$${b.total_spent.toFixed(4)}`;
        document.getElementById('budget-remaining').textContent = `$${b.remaining.toFixed(2)}`;
        document.getElementById('budget-projected').textContent = `$${p.projected_monthly.toFixed(2)}`;
        
        document.getElementById('budget-breakdown').textContent = 
            `Prompts: $${b.prompt_cost.toFixed(4)} • Sentiment: $${b.sentiment_cost.toFixed(4)}`;
        
        document.getElementById('budget-percent').textContent = 
            `${b.percent_used.toFixed(1)}% used`;
        
        // Progress bar
        const progressBar = document.getElementById('budget-progress-bar');
        const progressText = document.getElementById('budget-progress-text');
        progressBar.style.width = `${Math.min(b.percent_used, 100)}%`;
        progressText.textContent = `${b.percent_used.toFixed(1)}% of budget used`;
        
        // Status indicator
        const statusEl = document.getElementById('budget-status');
        if (p.projected_monthly > b.monthly_budget) {
            statusEl.innerHTML = '<span class="pill pill-miss">Over Budget!</span>';
            progressBar.style.background = '#ef4444';
        } else if (p.projected_monthly > b.monthly_budget * 0.8) {
            statusEl.innerHTML = '<span class="pill pill-or">Near Limit</span>';
            progressBar.style.background = 'linear-gradient(90deg, #f59e0b, #ef4444)';
        } else {
            statusEl.innerHTML = '<span class="pill pill-ok">On Track</span>';
            progressBar.style.background = 'linear-gradient(90deg, #10b981, #f59e0b)';
        }
        
        // Color coding
        if (b.percent_used > 90) {
            document.getElementById('budget-spent').style.color = '#ef4444';
        } else if (b.percent_used > 70) {
            document.getElementById('budget-spent').style.color = '#f59e0b';
        } else {
            document.getElementById('budget-spent').style.color = '#10b981';
        }
        
    } catch (err) {
        console.error('Failed to load budget stats:', err);
    }
}

// Edit budget modal
document.getElementById('editBudgetBtn')?.addEventListener('click', () => {
    const current = document.getElementById('budget-total').textContent.replace('$', '');
    document.getElementById('budgetInput').value = current;
    document.getElementById('editBudgetModal').classList.remove('hidden');
});

document.getElementById('closeBudgetModal')?.addEventListener('click', () => {
    document.getElementById('editBudgetModal').classList.add('hidden');
});

document.getElementById('cancelBudgetBtn')?.addEventListener('click', () => {
    document.getElementById('editBudgetModal').classList.add('hidden');
});

document.getElementById('saveBudgetBtn')?.addEventListener('click', async () => {
    const budget = parseFloat(document.getElementById('budgetInput').value);
    
    if (isNaN(budget) || budget < 0) {
        alert('Please enter a valid budget amount');
        return;
    }
    
    try {
        const res = await fetch(`${API_BASE}/automation/budget`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': API_KEY
            },
            body: JSON.stringify({ monthly_budget: budget })
        });
        
        if (res.ok) {
            document.getElementById('editBudgetModal').classList.add('hidden');
            loadBudgetStats();
        } else {
            alert('Failed to update budget');
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
});

// Load budget stats when Automation tab is opened
// Add this to your existing tab switching code
const originalShowAutomation = window.show; // Save original if exists
window.show = function(view) {
    if (originalShowAutomation) originalShowAutomation(view);
    if (view === 'Automation' && typeof loadBudgetStats === 'function') {
        loadBudgetStats();
    }
};

// Refresh budget every 5 minutes
setInterval(() => {
    const automationVisible = !document.getElementById('viewAutomation')?.classList.contains('hidden');
    if (automationVisible) {
        loadBudgetStats();
    }
}, 300000); // 5 minutes

// Event listeners
document.addEventListener('DOMContentLoaded', () => {
    
    const settingsForm = document.getElementById('automationSettingsForm');
    if (settingsForm) {
        settingsForm.addEventListener('submit', saveAutomationSettings);
    }
    
    const runOnceBtn = document.getElementById('btnRunOnce');
    if (runOnceBtn) {
        runOnceBtn.addEventListener('click', runOnce);
    }
    
    const refreshBtn = document.getElementById('btnRefreshRuns');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadRecentRuns);
    }
});