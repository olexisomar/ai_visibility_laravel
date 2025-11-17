// Detect account changes and reload data
(function() {
    const currentAccount = '{{ session("account_id") }}';
    
    // Store in sessionStorage
    const storedAccount = sessionStorage.getItem('current_account_id');
    
    if (storedAccount && storedAccount !== currentAccount) {      
        // Force reload all data
        sessionStorage.clear();
    }
    
    // Update stored account
    sessionStorage.setItem('current_account_id', currentAccount);
})();
// Primary brand context (filled by loadBrands)
let PRIMARY_BRAND_ID = '';
let OWNED_HOST = '';

/* ---------- helpers & tab switching ---------- */
// Accept domain, optional /path(/path)...
const BRAND_ID_PATTERN = new RegExp(
  '^(?!.*\\.{2})(?!.*\\.$)' +                                 // no ".." and no trailing dot
  '(?:[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?)' +                    // first host label
  '(?:\\.(?:[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?))*' +            // .more labels
  '(?:\\/[A-Za-z0-9._~\\-]+(?:\\/[A-Za-z0-9._~\\-]+)*)?$'     // optional /path segments
);

function normalizeBrandId(raw) {
  let s = String(raw || '').trim();
  if (!s) return '';

  // drop protocol, www, query, fragment
  s = s.replace(/^https?:\/\//i, '')
       .replace(/^www\./i, '')
       .replace(/[?#].*$/, '')
       .replace(/\/+$/,''); // trailing slash (except root)

  // lowercase the host only
  const slash = s.indexOf('/');
  if (slash === -1) return s.toLowerCase();
  return s.slice(0, slash).toLowerCase() + s.slice(slash);
}

function getOwnedHost(){ return (OWNED_HOST || '').trim().toLowerCase(); }

function asBrandIdOrEmpty(v){
  const s = normalizeBrandId(v);
  return BRAND_ID_PATTERN.test(s) ? s : '';
}


const $ = sel => document.querySelector(sel);
// Grouping control helper (defaults to 'week' if control is absent)
function getGroupBy(){
  const v = document.getElementById('perfGroupBy')?.value;
  return (v === 'day') ? 'day' : 'week';
}

function show(view) {
  ['Dashboard', 'Prompts', 'Brands', 'Performance', 'Config', 'Automation', 'Mentions'].forEach(v => {
    const tab = $('#tab' + v);
    const viewEl = $('#view' + v);
    
    // Only toggle if the element exists
    if (tab) {
      tab.classList.toggle('alt', v !== view);
    }
    if (viewEl) {
      viewEl.classList.toggle('hidden', v !== view);
    }
  });
  
  // Load automation data when tab is opened
  if (view === 'Automation') {
    if (typeof loadAutomationSettings === 'function') loadAutomationSettings();
    if (typeof loadRecentRuns === 'function') loadRecentRuns();
  }
}
// Safely attach tab handlers
function attachTabHandler(tabId, tabName) {
    const tab = $(`#tab${tabName}`);
    if (tab) {
        tab.onclick = () => show(tabName);
    }
}

// Attach handlers for all possible tabs
attachTabHandler('tabDashboard', 'Dashboard');
attachTabHandler('tabPerformance', 'Performance');
attachTabHandler('tabMentions', 'Mentions');
attachTabHandler('tabPrompts', 'Prompts');
attachTabHandler('tabBrands', 'Brands');
attachTabHandler('tabConfig', 'Config');
attachTabHandler('tabAutomation', 'Automation');

// ---- Prompts Checkbox selection state ----
const selectedPrompts = new Set();

function updateBulkBar(){
  const bar = document.getElementById('promptsBulkBar');
  const cnt = document.getElementById('pbCount');
  const n = selectedPrompts.size;
  if (bar) bar.classList.toggle('hidden', n === 0);
  if (cnt) cnt.textContent = `${n} selected`;
}

// ---- Pending Suggestions selection state ----
const selectedSuggestions = new Set();

function updateSugBulkBar() {
  const bar = document.getElementById('sugBulkBar');
  const cnt = document.getElementById('sbCount');
  const n = selectedSuggestions.size;
  if (bar) bar.classList.toggle('hidden', n === 0);
  if (cnt) cnt.textContent = `${n} selected`;
}


/*-----Intent Chip Helper Function---*/
function intentChip(val){
  const v = (val||'').toLowerCase();
  const label = v ? v[0].toUpperCase()+v.slice(1) : '';
  const map = {informational:'info', navigational:'nav', transactional:'tran', other:'other'};
  const cls = map[v] || 'other';
  return label ? `<span class="chip-intent ${cls}">${label}</span>` : '';
}

/* ---------- KPIs & summary (scope-aware) ---------- */
async function fetchMetrics() {
  try {
    // Use the same "All runs" checkbox as the mentions table
    const allRuns = document.getElementById('mentionsAll')?.checked;
    const scope = allRuns ? 'all' : 'latest_per_source';

    const r = await fetch('../api/metrics.php?' + new URLSearchParams({ scope }));
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    const j = await r.json();

    const k = j && j.kpis ? j.kpis : null;
    if (!k || typeof k !== 'object' || Object.keys(k).length === 0) {
      document.getElementById('kpis').innerHTML = `
        <div class="card pill-info"><b>Visibility</b><div style="font-size:28px">—</div></div>
        <div class="card pill-ok"><b>Coverage</b><div style="font-size:28px">—</div></div>
        <div class="card pill-or"><b>Share of Mentions</b><div style="font-size:28px">—</div></div>
        <div class="card pill-miss"><b>Missed Opps</b><div style="font-size:28px">—</div></div>`;
      document.getElementById('brandList').innerHTML = '<li>Run a sampler to see data.</li>';
      document.getElementById('missedTable').innerHTML = '';
      return;
    }

    document.getElementById('kpis').innerHTML = `
      <div class="card pill-info"><b>Visibility</b><div style="font-size:28px">${k.visibility ?? '—'}</div></div>
      <div class="card pill-ok"><b>Coverage</b><div style="font-size:28px">${k.coverage_rate != null ? (k.coverage_rate*100).toFixed(0)+'%' : '—'}</div></div>
      <div class="card pill-or"><b>Share of Mentions</b><div style="font-size:28px">${k.share_of_mentions != null ? (k.share_of_mentions*100).toFixed(0)+'%' : '—'}</div></div>
      <div class="card pill-miss"><b>Missed Opps</b><div style="font-size:28px">${k.missed_opportunities ?? '—'}</div></div>`;
    // --- Sentiment mini-strip ---
    (() => {
      // remove any prior strip so re-renders don’t stack
      document.querySelector('#kpis .sent-strip')?.remove();

      const s = j.sentiment || {};
      const pos = Number(s.positive || 0);
      const neu = Number(s.neutral  || 0);
      const neg = Number(s.negative || 0);
      const tot = Number.isFinite(s.total) ? Number(s.total) : Math.max(0, pos + neu + neg);

      const wPos = tot ? (100 * pos / tot) : 0;
      const wNeu = tot ? (100 * neu / tot) : 0;
      const wNeg = tot ? (100 * neg / tot) : 0;

      const wrap = document.createElement('div');
      wrap.className = 'sent-strip';
      wrap.innerHTML = `
        <div class="sent-bar">
          <span class="pos" style="width:${wPos}%;"></span>
          <span class="neu" style="width:${wNeu}%;"></span>
          <span class="neg" style="width:${wNeg}%;"></span>
        </div>
        <div class="sent-legend">
          <span><span class="sent-dot pos"></span>Positive: ${pos}${tot?` (${(100*pos/tot).toFixed(0)}%)`:''}</span>
          <span><span class="sent-dot neu"></span>Neutral: ${neu}${tot?` (${(100*neu/tot).toFixed(0)}%)`:''}</span>
          <span><span class="sent-dot neg"></span>Negative: ${neg}${tot?` (${(100*neg/tot).toFixed(0)}%)`:''}</span>
        </div>
      `;
      document.getElementById('kpis').appendChild(wrap);
    })();

    // --- Intent-by-brand strips ---
    (() => {
      const data = (j.intent_by_brand || {}); // { brandId: {informational, navigational, transactional, other}, ... }
      const wrap = document.createElement('div');
      wrap.className = 'intent-strip';

      // Clear any previous instance by removing old node with same id
      const old = document.getElementById('intentByBrandBlock');
      if (old) old.remove();

      // Container
      const block = document.createElement('div');
      block.id = 'intentByBrandBlock';
      block.className = 'intent-brand-block';

      // Build one mini-strip per brand
      Object.entries(data).forEach(([brand, mix]) => {
        const inf = Number(mix.informational || 0);
        const nav = Number(mix.navigational  || 0);
        const tra = Number(mix.transactional  || 0);
        const oth = Number(mix.other         || 0);
        const tot = Math.max(0, inf + nav + tra + oth);
        if (!tot) return;

        const wInf = (100 * inf / tot);
        const wNav = (100 * nav / tot);
        const wTra = (100 * tra / tot);
        const wOth = (100 * oth / tot);

        const row = document.createElement('div');      
        row.classList.add('intent-brand-chip');
        row.innerHTML = `
          <div style="font-weight:600; margin-bottom:4px;">${brand}</div>
          <div class="intent-bar">
            <span class="inf" style="width:${wInf}%;"></span>
            <span class="nav" style="width:${wNav}%;"></span>
            <span class="tra" style="width:${wTra}%;"></span>
            <span class="oth" style="width:${wOth}%;"></span>
          </div>
          <div class="intent-legend">
            <span><span class="intent-dot inf"></span>Informational: ${inf} ${tot?`(${(100*inf/tot).toFixed(0)}%)`:''}</span>
            <span><span class="intent-dot nav"></span>Navigational: ${nav} ${tot?`(${(100*nav/tot).toFixed(0)}%)`:''}</span>
            <span><span class="intent-dot tra"></span>Transactional: ${tra} ${tot?`(${(100*tra/tot).toFixed(0)}%)`:''}</span>
            <span><span class="intent-dot oth"></span>Other: ${oth} ${tot?`(${(100*oth/tot).toFixed(0)}%)`:''}</span>
          </div>
        `;
        block.appendChild(row);
      });

      // If nothing to show, bail quietly
      if (!block.children.length) return;

      wrap.appendChild(block);
      document.getElementById('intChipCont').appendChild(wrap);
    })();

    // ---- k-means (k=3) banding for priorities ----
    function makeKMeans3BandAssigner(priorities) {
      const vals = priorities.map(Number).filter(Number.isFinite);

      // Fallback if too few values to cluster well
      if (vals.length < 3) return (p) => (p >= 12 ? 'High' : p >= 6 ? 'Mid' : 'Low');

      // If all (or almost all) values are the same, use absolute cutoffs
      const min = Math.min(...vals), max = Math.max(...vals);
      if (max - min < 1e-6) return (p) => (p >= 12 ? 'High' : p >= 6 ? 'Mid' : 'Low');

      // Sort + quantile helpers
      const sorted = vals.slice().sort((a,b)=>a-b);
      const q = (r) => sorted[Math.max(0, Math.min(sorted.length-1, Math.floor(r*(sorted.length-1))))];

      // Init centers at ~16%, 50%, 84% quantiles
      let centers = [q(0.16), q(0.5), q(0.84)];

      // Lloyd's algorithm (1-D)
      let changed = true, iter = 0;
      while (changed && iter < 30) {
        iter++;
        const clusters = [[],[],[]];
        for (const v of vals) {
          const d0 = Math.abs(v - centers[0]);
          const d1 = Math.abs(v - centers[1]);
          const d2 = Math.abs(v - centers[2]);
          const idx = d0 <= d1 && d0 <= d2 ? 0 : (d1 <= d2 ? 1 : 2);
          clusters[idx].push(v);
        }
        changed = false;
        for (let k=0;k<3;k++) {
          if (!clusters[k].length) continue;
          const mean = clusters[k].reduce((s,v)=>s+v,0)/clusters[k].length;
          if (Math.abs(mean - centers[k]) > 1e-6) { centers[k] = mean; changed = true; }
        }
      }

      // Map cluster index → Low/Mid/High by center value
      const order = centers.map((c,i)=>({c,i})).sort((a,b)=>a.c-b.c).map(o=>o.i);
      const labelByIdx = {}; labelByIdx[order[0]]='Low'; labelByIdx[order[1]]='Mid'; labelByIdx[order[2]]='High';

      return (p) => {
        const d0 = Math.abs(p - centers[0]);
        const d1 = Math.abs(p - centers[1]);
        const d2 = Math.abs(p - centers[2]);
        const idx = d0 <= d1 && d0 <= d2 ? 0 : (d1 <= d2 ? 1 : 2);
        return labelByIdx[idx];
      };
    }

    // ---- chip renderer ----
    function renderPriorityChip(label, numeric) {
      const styles = {
        High: { bg:'#fee2e2', bd:'#fca5a5', fg:'#b91c1c' },
        Mid:  { bg:'#fef3c7', bd:'#fcd34d', fg:'#92400e' },
        Low:  { bg:'#e5e7eb', bd:'#cbd5e1', fg:'#374151' },
      }[label];

      const el = document.createElement('span');
      el.textContent = label;
      el.title = `Priority: ${numeric}`;
      el.style.cssText = `
        display:inline-block;padding:2px 8px;border:1px solid ${styles.bd};
        background:${styles.bg};color:${styles.fg};border-radius:9999px;
        font-size:12px;line-height:18px;font-weight:600;
      `;
      return el;
    }

    // Mentions by brand + filter dropdown (now percent-aware)
    const comp = j.comp_share || { total: 0, by_brand: {} };
    const total = comp.total || 0;
    const byBrandPct = comp.by_brand || {}; // { brand_id: {count, pct} }

    const ul = document.getElementById('brandList'); ul.innerHTML = '';
    const brandSel = document.getElementById('mentionsBrand');
    brandSel.innerHTML = '<option value="">All</option>';

    // sort by count desc if you like
    const entries = Object.entries(byBrandPct).sort((a,b)=> (b[1].count||0) - (a[1].count||0));

    entries.forEach(([bid, obj])=>{
      const cnt = obj.count || 0;
      const pct = obj.pct != null ? (obj.pct*100) : 0;

      // list row
      const li = document.createElement('li');
      li.className = 'brand-share-row';
      li.innerHTML = `
        <div class="brand-share-line">
          <a href="#" class="brand-link" data-bid="${bid}">${bid}</a>
          <span class="brand-share-num">${cnt}${total?` • ${(pct).toFixed(0)}%`:''}</span>
        </div>
        <div class="brand-share-bar">
          <span style="width:${pct}%;"></span>
        </div>
      `;
      li.querySelector('.brand-link').onclick = (ev)=>{
        ev.preventDefault();
        brandSel.value = bid;
        loadMentions();
      };
      ul.appendChild(li);

      // dropdown option
      const opt = document.createElement('option');
      opt.value = bid; opt.textContent = bid;
      brandSel.appendChild(opt);
    });

    // (Optional) empty state if no mentions
    if (entries.length === 0) {
      ul.innerHTML = '<li>No mentions in this scope yet.</li>';
    }

    // Missed prompts
    const missed = Array.isArray(j.missed_prompts) ? j.missed_prompts : [];
    lastMissed = missed.slice();                             // cache full set
    missedState.total = lastMissed.length;                   // total for pager

    const tb = document.getElementById('missedTable'); 
    tb.innerHTML = '';

    const start = (missedState.page - 1) * missedState.pageSize;
    const end   = start + missedState.pageSize;
    const pageSlice = lastMissed.slice(start, end);

    const priorities = pageSlice.map(m => Number(m.priority || 0));
    const assignBand = makeKMeans3BandAssigner(priorities);

    pageSlice.forEach(row => {
      const tr = document.createElement('tr');

      const tdCat = document.createElement('td');
      tdCat.textContent = row.category;
      tr.appendChild(tdCat);

      const tdPrompt = document.createElement('td');
      tdPrompt.textContent = row.prompt;
      tr.appendChild(tdPrompt);

      const tdVol = document.createElement('td');
      tdVol.textContent = row.search_volume ?? '';
      tr.appendChild(tdVol);

      const tdPrio = document.createElement('td');
      const p = Number(row.priority || 0);
      const label = assignBand(p);
      tdPrio.appendChild(renderPriorityChip(label, p));
      tr.appendChild(tdPrio);

      tb.appendChild(tr);
    });

    // refresh the pager after rendering
    updateMissedPagination();


    // Optional: show which runs contributed to these KPIs
    if (document.getElementById('runStatus')) {
      const used = (j.run_set || []).map(r => `#${r.id} ${r.model}`).join(' , ');
      document.getElementById('runStatus').textContent = (scope === 'all') ? 'scope: All runs' : `runs: ${used}`;
    }

  } catch (err) {
    document.getElementById('kpis').innerHTML = `
      <div class="card" style="grid-column: span 4">
        <b>Error</b><div class="mono" style="margin-top:6px">${String(err)}</div>
      </div>`;
    document.getElementById('brandList').innerHTML = '';
    document.getElementById('missedTable').innerHTML = '';
  }
  updateMissedPagination()
}

/* ---- Missed Table Pagination Functions ---- */
function updateMissedPagination() {
  const pages = Math.max(1, Math.ceil(missedState.total / missedState.pageSize));
  const info   = document.getElementById('missedPgInfo');
  const prevBtn= document.getElementById('missedPgPrev');
  const nextBtn= document.getElementById('missedPgNext');
  const pager  = document.getElementById('missedPager');

  if (info)   info.textContent = `Page ${missedState.page} / ${pages} — ${missedState.total} item${missedState.total !== 1 ? 's' : ''}`;
  if (prevBtn) prevBtn.disabled = missedState.page <= 1;
  if (nextBtn) nextBtn.disabled = missedState.page >= pages;
  if (pager)   pager.style.display = pages > 1 ? 'flex' : 'none';

}

// Missed pagination controls
document.getElementById('missedPgPrev')?.addEventListener('click', () => {
  if (missedState.page > 1) {
    missedState.page--;
    fetchMetrics();            // re-render using the slice
  }
});

document.getElementById('missedPgNext')?.addEventListener('click', () => {
  const pages = Math.max(1, Math.ceil(missedState.total / missedState.pageSize));
  if (missedState.page < pages) {
    missedState.page++;
    fetchMetrics();
  }
});

// When "All runs" toggles, refresh both KPIs and the mentions table
document.getElementById('mentionsAll')?.addEventListener('change', () => {
  missedState.page = 1;
  fetchMetrics();
  loadMentions();
});

/* ---------- run buttons ---------- */
function toggleRunButtons(disabled){
  const b1 = $('#runBtn'); const b2 = $('#runGaiBtn');
  if (b1) { b1.disabled = disabled; b1.style.opacity = disabled ? 0.6 : 1; }
  if (b2) { b2.disabled = disabled; b2.style.opacity = disabled ? 0.6 : 1; }
}

async function runSampler() {
  const s = $('#runStatus');
  s.textContent = ' running GPT...';
  s.style.color = '#f59e0b'; // orange
  toggleRunButtons(true);
  
  try {
    // Get model from UI or use null (server will use .env default)
    const model = $('#runModel')?.value || null; // optional: add a model selector in UI
    const temp = parseFloat($('#runTemp')?.value || '0.2'); // optional: add temp input
    
    // Use POST to /runs/start
    const res = await fetch(`${API_BASE}/runs/start`, {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'X-API-Key': API_KEY 
      },
      body: JSON.stringify({
        model: model,  // null = server uses .env default
        temp: temp,
        offset: 0
      })
    });
    
    const j = await res.json();
    
    if (!res.ok || j.error) {
      throw new Error(j.error || 'Run failed');
    }
    
    // Success
    s.textContent = ` ✓ Run #${j.run_id}: processed ${j.processed} prompts (${j.model}), ${j.errors?.length || 0} errors`;
    s.style.color = '#10b981'; // green
    
    // Auto-clear after 5 seconds
    setTimeout(() => {
      if (s.textContent.includes('✓')) {
        s.textContent = '';
        s.style.color = '';
      }
    }, 5000);
    
  } catch (e) {
    s.textContent = ' ✗ Error: ' + e.message;
    s.style.color = '#ef4444'; // red
  }
  
  toggleRunButtons(false);
  
  // Refresh metrics and mentions
  if (typeof fetchMetrics === 'function') fetchMetrics();
  if (typeof loadMentions === 'function') loadMentions();
}

async function runGoogleAIO() {
  const s = $('#runStatus');
  s.textContent = ' running Google AIO...';
  s.style.color = '#f59e0b'; // orange
  toggleRunButtons(true);

  try {
    // Optional locale overrides (if you have these inputs in your UI)
    const hl = $('#aioHl')?.value?.trim() || null;
    const gl = $('#aioGl')?.value?.trim() || null;
    const location = $('#aioLocation')?.value?.trim() || null;

    const res = await fetch(`${API_BASE}/runs/aio/start`, {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'X-API-Key': API_KEY 
      },
      body: JSON.stringify({
        hl: hl,
        gl: gl,
        location: location,
        offset: 0
      })
    });
    
    const j = await res.json();
    
    if (!res.ok || j.error) {
      throw new Error(j.error || 'AIO run failed');
    }
    
    // Success
    s.textContent = ` ✓ AIO Run #${j.run_id}: processed ${j.processed} prompts, ${j.errors?.length || 0} errors`;
    s.style.color = '#10b981'; // green
    
    // Show preview if available
    if (j.preview) {
      console.log('AIO Preview:', j.preview);
    }
    
    // Auto-clear after 5 seconds
    setTimeout(() => {
      if (s.textContent.includes('✓')) {
        s.textContent = '';
        s.style.color = '';
      }
    }, 5000);
    
  } catch (e) {
    s.textContent = ' ✗ Error: ' + e.message;
    s.style.color = '#ef4444'; // red
  }
  
  toggleRunButtons(false);
  
  // Refresh metrics and mentions
  if (typeof fetchMetrics === 'function') fetchMetrics();
  if (typeof loadMentions === 'function') loadMentions();
}

// Run buttons (only exist for admins)
const runBtn = $('#runBtn');
const runGaiBtn = $('#runGaiBtn');

if (runBtn) runBtn.onclick = runSampler;
if (runGaiBtn) runGaiBtn.onclick = runGoogleAIO;

/* ---------- PROMPTS CRUD ---------- */
async function loadPrompts(){
  const scopeSel = document.getElementById('promptsScope'); // optional <select>
  const scope = scopeSel ? scopeSel.value : 'latest';

  // 1) Get editable prompts (ID/category/prompt)
  const r = await fetch('../api/admin.php?action=list_prompts', { cache: 'no-store' });
  const list = await r.json();
  
  // 2) Get Missed/Mentioned status for each prompt
  const rStat = await fetch('../api/admin.php?action=prompts_status&scope=' + encodeURIComponent(scope) + '&t=' + Date.now(), { cache: 'no-store' });
  const stat = await rStat.json();
  const byId = Object.create(null);
  (stat.rows || []).forEach(s => { byId[s.id] = s; });

  // 3) filter before rendering rows
  const activeFilter = document.getElementById('promptsActive')?.value || '';
  const filtered = list.filter(row => {
    if (activeFilter === 'active')  return !row.is_paused;
    if (activeFilter === 'paused')  return !!row.is_paused;
    return true; // All
  });
  // 4) Render rows
  const tb = $('#promptsTable tbody'); tb.innerHTML='';
  filtered.forEach(row=>{
    const s = byId[row.id] || { mentioned:0, mentions_count:0, is_paused: row.is_paused || 0 };
    const pill = s.mentioned
      ? '<span class="pill pill-ok">Mentioned</span>'
      : '<span class="pill pill-miss">Missed</span>';

    const tr=document.createElement('tr');
    tr.innerHTML = `
      <td style="text-align:center;"><input type="checkbox" class="pchk" data-id="${row.id}"></td>
      <td><input value="${row.category||''}" data-id="${row.id}" data-field="category"></td>
      <td><input value="${row.prompt||''}" data-id="${row.id}" data-field="prompt" class="ptd"></td>
      <td><input value="${row.source||''}" data-id="${row.id}" data-field="source" class="ptd"></td>
      <td><input value="${row.search_volume||0}" data-id="${row.id}" data-field="search_volume" inputmode="numeric" pattern="[0-9]*" class="ptd"></td>
      <td>${pill} | ${row.is_paused ? '<span class="pill" style="background:#e5e7eb;border:1px solid #cbd5e1;color:#374151">Paused</span>' : '<span class="pill pill-info">Active</span>'}</td>
      <td>${s.mentions_count}</td>
      <td>
        <button class="btn pill-ok" data-act="save" data-id="${row.id}">Save</button>
        <button class="btn pill-miss" data-act="del" data-id="${row.id}">Delete</button>
        <button class="btn pill-or" data-act="pause" data-id="${row.id}" data-paused="${row.is_paused?1:0}">${row.is_paused ? 'Resume' : 'Pause'}</button>
      </td>`;
    tb.appendChild(tr);
  });

  if (list.length === 0) {
    const tr=document.createElement('tr');
    tr.innerHTML = `<td colspan="8" class="mono" style="opacity:.7">No prompts found.</td>`;
    tb.appendChild(tr);
  }

  // Clear any previous selections after re-render:
  selectedPrompts.clear();
  updateBulkBar();
  const hdr = document.getElementById('promptsSelectAll');
  if (hdr) hdr.checked = false;
}
// Refresh when user flips Latest/All
document.getElementById('promptsScope')?.addEventListener('change', loadPrompts);
//refresh when filter changes
document.getElementById('promptsActive')?.addEventListener('change', loadPrompts);

$('#addPromptBtn').onclick = async (e) => {
  e.preventDefault(); // Prevent form submission/redirect
  
  const cat = $('#pCategory').value.trim();
  const txt = $('#pText').value.trim();
  const volRaw = $('#pVolume').value.trim();
  const vol = volRaw === '' ? null : Number(volRaw);
  
  if (!txt) { 
    alert('Prompt required'); 
    return; 
  }
  
  try {
    const res = await fetch(`${API_BASE}/prompts`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        category: cat,
        prompt: txt,
        search_volume: vol
      })
    });
    
    const json = await res.json().catch(() => ({}));
    
    if (!res.ok || json.error) {
      alert('Failed to add prompt: ' + (json.error || 'Unknown error'));
      return;
    }
    
    // Clear form
    $('#pCategory').value = '';
    $('#pText').value = '';
    $('#pVolume').value = '';
    
    // Reload prompts table
    loadPrompts();
  } catch (e) {
    alert('Failed to add prompt: ' + e.message);
  }
};

$('#promptsTable').addEventListener('click', async (e)=>{
  const t=e.target; 
  const id=t.dataset.id;
  
  if (!id) return;
  
  // DELETE
  if (t.dataset.act==='del'){
    if(!confirm('Delete prompt?')) return;
    try {
      const res = await fetch(`${API_BASE}/prompts/${id}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' }
      });
      const json = await res.json().catch(()=>({}));
      if (!res.ok || json.error) {
        alert('Delete failed: ' + (json.error || 'Unknown error'));
        return;
      }
      loadPrompts();
    } catch(e) {
      alert('Delete failed: ' + e.message);
    }
    return;
  }
  
  // SAVE
  if (t.dataset.act==='save'){
    const row = { id: parseInt(id,10) };
    document.querySelectorAll(`[data-id="${id}"]`).forEach(inp=>{
      const field = inp.dataset.field;
      let value = inp.value;
      
      // Convert search_volume to number or null
      if (field === 'search_volume') {
        value = value === '' ? null : Number(value);
      }
      
      row[field] = value;
    });
    
    try {
      const res = await fetch(`${API_BASE}/prompts`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(row)
      });
      const json = await res.json().catch(()=>({}));
      if (!res.ok || json.error) {
        alert('Save failed: ' + (json.error || 'Unknown error'));
        return;
      }
      loadPrompts();
    } catch(e) {
      alert('Save failed: ' + e.message);
    }
    return;
  }
  
  // PAUSE/RESUME
  if (t.dataset.act === 'pause') {
    const cur = Number(t.dataset.paused) === 1;
    const next = cur ? 0 : 1;

    try {
      const res = await fetch(`${API_BASE}/prompts/${id}/toggle-pause`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: Number(id), is_paused: next })
      });
      const json = await res.json().catch(()=>({}));
      if (!res.ok || json.error) {
        alert('Toggle pause failed: ' + (json.error || 'Unknown error'));
        return;
      }
      loadPrompts();
    } catch(e) {
      alert('Toggle pause failed: ' + e.message);
    }
    return;
  }
});

// Header "select all"
document.getElementById('promptsSelectAll')?.addEventListener('change', (e)=>{
  const on = !!e.target.checked;
  document.querySelectorAll('#promptsTable tbody .pchk').forEach(chk=>{
    chk.checked = on;
    const id = Number(chk.dataset.id);
    if (on) selectedPrompts.add(id); else selectedPrompts.delete(id);
  });
  updateBulkBar();
});

// Row checkbox (event delegation)
document.getElementById('promptsTable')?.addEventListener('change', (e)=>{
  if (!e.target.classList?.contains('pchk')) return;
  const id = Number(e.target.dataset.id);
  if (e.target.checked) selectedPrompts.add(id); else selectedPrompts.delete(id);

  // If one row was unchecked, uncheck header
  const hdr = document.getElementById('promptsSelectAll');
  if (hdr && hdr.checked && !e.target.checked) hdr.checked = false;

  updateBulkBar();
});

async function bulkCall(action, ids){
  const r = await fetch('../api/admin.php?action=' + action, {
    method:'POST',
    headers: { 'Content-Type':'application/json' },
    body: JSON.stringify({ ids })
  });
  const j = await r.json().catch(()=>({}));
  if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
  return j;
}

document.getElementById('pbPause') ?.addEventListener('click', async ()=>{
  if (selectedPrompts.size === 0) return;
  try { await bulkCall('bulk_pause_prompts', Array.from(selectedPrompts)); }
  catch(e){ alert('Pause failed: ' + e.message); return; }
  await loadPrompts();
});

document.getElementById('pbResume')?.addEventListener('click', async ()=>{
  if (selectedPrompts.size === 0) return;
  try { await bulkCall('bulk_resume_prompts', Array.from(selectedPrompts)); }
  catch(e){ alert('Resume failed: ' + e.message); return; }
  await loadPrompts();
});

document.getElementById('pbDelete')?.addEventListener('click', async ()=>{
  if (selectedPrompts.size === 0) return;
  if (!confirm(`Delete ${selectedPrompts.size} prompts?`)) return;
  try { await bulkCall('bulk_delete_prompts', Array.from(selectedPrompts)); }
  catch(e){ alert('Delete failed: ' + e.message); return; }
  await loadPrompts();
});

/* ---------- BRANDS CRUD ---------- */
async function loadBrands(){
  const r = await fetch('../api/admin.php?action=list_brands'); 
  const j = await r.json();
  const tb = $('#brandsTable tbody'); tb.innerHTML='';
  const sel = $('#primarySelect'); sel.innerHTML='';

  (j.brands||[]).forEach(b=>{
    const opt=document.createElement('option'); opt.value=b.id; opt.textContent=`${b.name} (${b.id})`;
    sel.appendChild(opt);
    const tr=document.createElement('tr');
    tr.innerHTML = `
      <td>${b.id}</td>
      <td>${b.name}</td>
      <td>${(b.aliases||[]).map(a=>`<span class="alias-chip">${a}</span>`).join('')}</td>
      <td><button class="btn pill-miss" data-del="${b.id}">Delete</button></td>`;
    tb.appendChild(tr);
  });
  if (j.primary_brand_id) sel.value = j.primary_brand_id;
  // --- expose primary brand + owned host for the Performance tab ---
  PRIMARY_BRAND_ID = j.primary_brand_id || '';

  const brandsArr = Array.isArray(j.brands) ? j.brands : [];
  const primary = brandsArr.find(b => b.id === PRIMARY_BRAND_ID);

  // Try common property names; accept bare domains or full URLs.
  let site = primary?.site || primary?.domain || primary?.homepage || primary?.url || '';
  if (site) {
    try {
      // normalize to hostname and strip www.
      const u = site.startsWith('http') ? new URL(site) : new URL('https://' + site);
      OWNED_HOST = (u.hostname || '').replace(/^www\./, '').toLowerCase();
    } catch { OWNED_HOST = String(site).replace(/^www\./, '').toLowerCase(); }
  } else {
    OWNED_HOST = '';
  }
}
$('#saveBrandBtn').onclick = async () => {
  const raw = $('#bId').value;
  const id  = normalizeBrandId(raw);
  const name = $('#bName').value.trim();

  if (!BRAND_ID_PATTERN.test(id)) {
    alert('Must be a domain or a section, e.g., betus.com.pa or betus.com.pa/sportsbook (no http/https, no trailing slash).');
    return;
  }
  if (!name) { alert('Name required'); return; }

  const aliases = $('#bAliases').value.split(',').map(s=>s.trim()).filter(Boolean);

  await fetch(`${API_BASE}/brands`, {  // ← Use API_BASE directly
    method:'POST',
    headers: {                           // ← ADD HEADERS
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({ id, name, aliases })
  });

  $('#bId').value=''; 
  $('#bName').value=''; 
  $('#bAliases').value='';
  await loadBrands();
  await loadBrandsIntoPersonaSelect();
};

$('#brandsTable').addEventListener('click', async (e)=>{
  const id = e.target?.dataset?.del; 
  if(!id) return;
  if(!confirm('Delete brand '+id+'?')) return;
  
  try {
    const res = await fetch(`${API_BASE}/brands/${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' }
    });
    
    const json = await res.json().catch(() => ({}));
    
    if (!res.ok || json.error) {
      alert('Delete failed: ' + (json.error || 'Unknown error'));
      return;
    }
    
    loadBrands();
  } catch (e) {
    alert('Delete failed: ' + e.message);
  }
});

$('#setPrimaryBtn').onclick = async () => {
  const id = $('#primarySelect').value;
  const statusEl = $('#primaryStatus');
  
  if (!id) {
    statusEl.textContent = ' ✗ Select a brand first';
    statusEl.style.color = '#ef4444';
    return;
  }
  
  try {
    const res = await fetch(`${API_BASE}/brands/primary`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    });
    
    const json = await res.json();
    
    if (!res.ok || json.error) {
      statusEl.textContent = ' ✗ error: ' + (json.error || 'Unknown error');
      statusEl.style.color = '#ef4444';
      return;
    }
    
    statusEl.textContent = ' ✓ saved';
    statusEl.style.color = '#10b981';
    
    // Clear message after 3 seconds
    setTimeout(() => {
      statusEl.textContent = '';
      statusEl.style.color = '';
    }, 3000);
  } catch (e) {
    statusEl.textContent = ' ✗ error: ' + e.message;
    statusEl.style.color = '#ef4444';
  }
};

/* ---- Mentions Pagination State ---- */
let mentionsState = { page: 1, pageSize: 20, total: 0 };
/* ---- Missed Pagination State ---- */
let lastMissed = [];
let missedState = { page: 1, pageSize: 5, total: 0 };

/* ---------- Mentions (prompt-level) ---------- */
async function loadMentions() {
  const brand = document.getElementById('mentionsBrand').value;
  const q = document.getElementById('mentionsQuery').value.trim();
  const all = document.getElementById('mentionsAll')?.checked;
  const src = document.getElementById('mentionsSource')?.value || 'all';
  const sent = document.getElementById('mentionsSentiment')?.value || '';
  const intent = document.getElementById('mentionsIntent')?.value || '';

  const params = new URLSearchParams();
  if (brand) params.set('brand', brand);
  if (q) params.set('q', q);
  if (all) params.set('scope', 'all');
  else params.set('scope', 'latest_per_source');
  if (src && src !== 'all') params.set('model', src);
  if (sent) params.set('sentiment', sent);
  if (intent) params.set('intent', intent);
  
  // Add pagination params
  params.set('page', mentionsState.page);
  params.set('page_size', mentionsState.pageSize);
  
  const r = await fetch(`${API_BASE}/mentions?${params.toString()}`);
  const j = await r.json();
  
  // Update state
  mentionsState.total = j.total || 0;
  mentionsState.page = j.page || 1;
  mentionsState.pageSize = j.page_size || 50;
  
  const tb = document.getElementById('mentionsTable').querySelector('tbody');
  tb.innerHTML = '';
   
  (j.rows || []).forEach(row => {
    const urlCell = row.url ? `<a href="${encodeURI(row.url)}" target="_blank" rel="noopener noreferrer">${row.url}</a>` : '';
    const anchorCell = (row.anchor || '').toLowerCase();
    const srcLabel = (row.model || '').startsWith('gpt') ? 'GPT' :
                     (row.model === 'google-ai-overview' ? 'AIO' : row.model || '');
    const imageUrl = srcLabel === 'GPT' ? '/ai-visibility-company/public/assets/img/gpt.webp' : '/ai-visibility-company/public/assets/img/aiog.webp';
    
    // sentiment chip
    const s = (row.sentiment || '').toLowerCase();
    const sentLabel = s ? s.charAt(0).toUpperCase() + s.slice(1) : '—';
    const sentClass = s === 'positive' ? 'pos' : (s === 'negative' ? 'neg' : 'neu');
    const sentChip = s ? `<span class="badge-sent ${sentClass}">${sentLabel}</span>` : '';

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${row.run_at||''}</td>
      <td>${row.category||''}</td>
      <td>${row.prompt||''}</td>
      <td>${row.brand_id||''}</td>
      <td>${row.found_alias||''}</td>
      <td>${sentChip}</td>
      <td>${intentChip(row.intent)}</td>
      <td>${row.snippet||''}</td>
      <td>${anchorCell}</td>
      <td>${urlCell}</td>
      <td style="text-align: center;"><img src="${imageUrl}" /></td>
      <td><button style="background: #E8FFF2" class="btn pill-ok alt" data-rid="${row.response_id}">View</button></td>
    `;
    tb.appendChild(tr);
  });

  if (!j.rows || j.rows.length === 0) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="12">No mentions yet for this filter.</td>`;
    tb.appendChild(tr);
  }
  
  // Update pagination UI
  updateMentionsPagination();
}
/* ---- Mations Table Pagination Functions ---- */
function updateMentionsPagination() {
  const pages = Math.max(1, Math.ceil(mentionsState.total / mentionsState.pageSize));
  
  const info = document.getElementById('mentPgInfo');
  const prevBtn = document.getElementById('mentPgPrev');
  const nextBtn = document.getElementById('mentPgNext');
  const pager = document.getElementById('mentions-pager');
  
  if (info) {
    info.textContent = `Page ${mentionsState.page} / ${pages} — ${mentionsState.total} mention${mentionsState.total !== 1 ? 's' : ''}`;
  }
  
  if (prevBtn) {
    prevBtn.disabled = mentionsState.page <= 1;
  }
  
  if (nextBtn) {
    nextBtn.disabled = mentionsState.page >= pages;
  }
  
  // Show/hide pagination if only 1 page
  if (pager) {
    pager.style.display = pages > 1 ? 'flex' : 'none';
  }
}
// Mentions pagination controls
document.getElementById('mentPgPrev')?.addEventListener('click', () => {
  if (mentionsState.page > 1) {
    mentionsState.page--;
    loadMentions();
  }
});

document.getElementById('mentPgNext')?.addEventListener('click', () => {
  const pages = Math.max(1, Math.ceil(mentionsState.total / mentionsState.pageSize));
  if (mentionsState.page < pages) {
    mentionsState.page++;
    loadMentions();
  }
});
document.getElementById('mentionsBrand')?.addEventListener('change', () => {
  mentionsState.page = 1;
  loadMentions();
});

document.getElementById('mentionsSource')?.addEventListener('change', () => {
  mentionsState.page = 1;
  loadMentions();
});

document.getElementById('mentionsIntent')?.addEventListener('change', () => {
  mentionsState.page = 1;
  loadMentions();
});

document.getElementById('mentionsSentiment')?.addEventListener('change', () => {
  mentionsState.page = 1;
  loadMentions();
});

document.getElementById('mentionsAll')?.addEventListener('change', () => {
  mentionsState.page = 1;
  loadMentions();
});

document.getElementById('mentionsReload')?.addEventListener('click', () => {
  mentionsState.page = 1;
  loadMentions();
});



// small helpers
function escapeHtml(s){
  return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function openRespModal(html){
  const m = document.getElementById('respModal');
  
  document.getElementById('respModalBody').innerHTML = html;
  m.classList.remove('hidden');
}
function closeRespModal(){ document.getElementById('respModal').classList.add('hidden'); }
document.getElementById('respModalClose').onclick = closeRespModal;
document.getElementById('respModal').addEventListener('click', (e)=>{ if(e.target.id==='respModal') closeRespModal(); });

// Mentions table "View" → show modal with clickable links
document.getElementById('mentionsTable').addEventListener('click', async (e)=>{
  const rid = e.target?.dataset?.rid; if (!rid) return;
  const r = await fetch(`${API_BASE}/responses/${rid}`);
  const j = await r.json();
  
  const raw = escapeHtml(j.response.raw_answer || '(empty)');
  
  let linksHtml = '';
  if (Array.isArray(j.links) && j.links.length) {
    linksHtml = `
      <div class="resp-links">
        <b>Links</b>
        <ul>
          ${j.links.map(l=>{
            const t = l.anchor && l.anchor.trim() ? escapeHtml(l.anchor) : escapeHtml(l.url);
            return `<li><a href="${encodeURI(l.url)}" target="_blank" rel="noopener noreferrer">${t}</a></li>`;
          }).join('')}
        </ul>
      </div>`;
  }
  document.getElementById('modalTitle').innerHTML = "Response";
  openRespModal(`
    <div class="resp-ans">${raw}</div>
    ${linksHtml || '<div style="margin-top:10px;color:#aaa">No links extracted for this response.</div>'}
  `);
});
// Personas table "View" → show modal with clickable links
document.getElementById('cfgPersonasTable').addEventListener('click', async (e) => {
  const pid = e.target?.dataset?.pid;
  if (!pid) return;

  try {
    const res = await fetch(`${API_BASE}/admin?action=list_personas`);
    const j = await res.json();

    // Convert pid to integer explicitly to avoid string subtraction weirdness
    const perId = parseInt(pid, 10) - 1;
    if (!j.rows || !j.rows[perId]) {
      console.warn('Invalid persona ID:', perId);
      return;
    }

    // Escape HTML to prevent injection
    const raw = escapeHtml(j.rows[perId].description || '(empty)');
    const capName = (j.rows[perId].name || 'Unnamed').toUpperCase();

    document.getElementById('modalTitle').innerHTML = "Persona Description";

    openRespModal(`      
      <div class="resp-ans"><strong>${capName}</strong></div>
      <div class="resp-ans">${raw}</div>
    `);
  } catch (err) {
    console.error('Failed to load persona:', err);
  }
});


// ----- PROMPTS CSV -----
document.getElementById('promptsExportBtn').onclick = async () => {
  try {
    const scope = 'all'; // or 'all' based on your needs
    const url = `${API_BASE}/prompts/export?scope=${scope}`;
    
    // Fetch CSV
    const res = await fetch(url, {
      method: 'GET',
      headers: { 'X-API-Key': API_KEY }
    });
    
    if (!res.ok) {
      alert('Export failed: HTTP ' + res.status);
      return;
    }
    
    // Download CSV
    const blob = await res.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = downloadUrl;
    a.download = 'prompts_export_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(downloadUrl);
  } catch (e) {
    alert('Export failed: ' + e.message);
  }
};

document.getElementById('promptsImportBtn').onclick = async () => {
  const file = document.getElementById('promptsCsvFile').files[0];
  const replace = document.getElementById('promptsReplace').checked;
  const statusEl = document.getElementById('promptsCsvStatus');

  if (!file) { 
    alert('Pick a CSV file first.'); 
    return; 
  }
  
  try {
    statusEl.textContent = 'Uploading…';
    
    // Create FormData
    const formData = new FormData();
    formData.append('file', file);
    formData.append('replace', replace ? '1' : '0');
    
    // Upload to Laravel endpoint
    const res = await fetch(`${API_BASE}/prompts/import`, {
      method: 'POST',
      headers: { 'X-API-Key': API_KEY },
      body: formData
    });
    
    const j = await res.json();
    
    if (!res.ok || j.error) {
      throw new Error(j.error || ('HTTP ' + res.status));
    }
    
    // Success message
    let msg = `✓ Imported ${j.imported} prompts`;
    if (j.skipped > 0) {
      msg += `, skipped ${j.skipped}`;
    }
    if (j.errors && j.errors.length > 0) {
      msg += '\n\nErrors:\n' + j.errors.join('\n');
    }
    
    statusEl.textContent = msg;
    statusEl.style.color = '#10b981';
    
    // Clear after 5 seconds
    setTimeout(() => {
      statusEl.textContent = '';
      statusEl.style.color = '';
    }, 5000);
    
    // Refresh table
    loadPrompts();
  } catch (e) {
    statusEl.textContent = 'Error: ' + e.message;
    statusEl.style.color = '#ef4444';
  }
};

// ----- BRANDS CSV -----
document.getElementById('brandsExportBtn').onclick = async () => {
  try {
    const url = `${API_BASE}/brands/export`;
    
    // Fetch CSV
    const res = await fetch(url, {
      method: 'GET',
      headers: { 'X-API-Key': API_KEY }
    });
    
    if (!res.ok) {
      alert('Export failed: HTTP ' + res.status);
      return;
    }
    
    // Download CSV
    const blob = await res.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = downloadUrl;
    a.download = 'brands_export_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(downloadUrl);
  } catch (e) {
    alert('Export failed: ' + e.message);
  }
};

document.getElementById('brandsImportBtn').onclick = async () => {
  const file = document.getElementById('brandsCsvFile').files[0];
  const replace = document.getElementById('brandsReplace').checked;
  const primary = (document.getElementById('brandsPrimary').value || '').trim();
  const statusEl = document.getElementById('brandsCsvStatus');
  
  if (!file) { 
    alert('Pick a CSV file first.'); 
    return; 
  }
  
  try {
    statusEl.textContent = 'Uploading…';
    
    // Create FormData
    const formData = new FormData();
    formData.append('file', file);
    formData.append('replace', replace ? '1' : '0');
    if (primary) {
      formData.append('primary', primary);
    }
    
    // Upload to Laravel endpoint
    const res = await fetch(`${API_BASE}/brands/import`, {
      method: 'POST',
      headers: { 'X-API-Key': API_KEY },
      body: formData
    });
    
    const j = await res.json();
    
    if (!res.ok || j.error) {
      throw new Error(j.error || ('HTTP ' + res.status));
    }
    
    // Success message
    let msg = `✓ Imported ${j.imported} brands`;
    if (j.skipped > 0) {
      msg += `, skipped ${j.skipped}`;
    }
    if (j.primary) {
      msg += ` — primary: ${j.primary}`;
    }
    if (j.errors && j.errors.length > 0) {
      msg += '\n\nErrors:\n' + j.errors.join('\n');
    }
    
    statusEl.textContent = msg;
    statusEl.style.color = '#10b981';
    
    // Clear after 5 seconds
    setTimeout(() => {
      statusEl.textContent = '';
      statusEl.style.color = '';
    }, 5000);
    
    // Refresh brands UI
    loadBrands();
  } catch (e) {
    statusEl.textContent = 'Error: ' + e.message;
    statusEl.style.color = '#ef4444';
  }
};

/*-------------- Pending Suggestions ------------*/
// Detect base path from current URL
const getBasePath = () => {
    const path = window.location.pathname;
    // If we're in /ai-visibility-company/public/, use that as base
    if (path.includes('/ai-visibility-company/public/')) {
        return '/ai-visibility-company/public/api/admin';
    }
    // If we're in /ai-visibility-company/, use that as base
    if (path.includes('/ai-visibility-company/')) {
        return '/ai-visibility-company/api/admin';
    }
    // Default for root or localhost:8000
    return '/api/admin';
};

const API_BASE = getBasePath();
const API_KEY = window.userApiKey || 'temporary-fallback-key';
const personasCache = Object.create(null);

// ---- Suggestions Paginations State ----
let state = { page: 1, pageSize: 50, total: 0 };


function scoreChip(score){
  const s = Number(score||0);
  const label = s>=80?'High':(s>=60?'Mid':'Low');
  const cls = s>=80?'high':(s>=60?'mid':'low');
  return `<span class="chip ${cls}" title="Score ${s}">${label}</span>`;
}
function confChip(c){
  const cc = String(c||'').toLowerCase();
  const map = {high:'conf-hi',medium:'conf-md',low:'conf-lo'};
  const cls = map[cc] || 'conf-md';
  const lbl = cc?cc.charAt(0).toUpperCase()+cc.slice(1):'—';
  return `<span class="chip ${cls}">${lbl}</span>`;
}
function fmtDate(iso){
  if(!iso) return '—';
  try{ return new Date(iso.replace(' ','T')).toLocaleString(); }catch{ return iso; }
}
function scoreBucket(score) {
  const s = Number(score || 0);
  if (!Number.isFinite(s)) return 'low';      // treat missing as Low
  if (s >= 80) return 'high';
  if (s >= 60) return 'mid';
  return 'low';
}

async function loadPending(){
  const src  = document.getElementById('f-source').value;
  const lang = document.getElementById('f-lang').value;
  const geo  = document.getElementById('f-geo').value;

  // bucket or numeric?
  const raw = (document.getElementById('f-minscore').value || '').trim().toLowerCase();
  const isBucket = raw === 'low' || raw === 'mid' || raw === 'high';
  const numVal = Number(raw);
  const hasNumeric = !isBucket && Number.isFinite(numVal);

  const url = new URL(API_BASE + '/suggestions', location.origin);
  url.searchParams.set('status','new');
  url.searchParams.set('page', state.page);
  url.searchParams.set('page_size', state.pageSize);
  if (src)  url.searchParams.set('source', src);
  if (lang) url.searchParams.set('lang',   lang);
  if (geo)  url.searchParams.set('geo',    geo);
  // only send numeric min to the API
  if (hasNumeric) url.searchParams.set('min_score', String(numVal));

  const res = await fetch(url, { headers: { 'X-API-Key': API_KEY }});
  const data = await res.json(); // { rows, total, page, page_size }

  // rows we will actually render
  let rows = Array.isArray(data.rows) ? data.rows.slice() : [];
  if (isBucket) {
    rows = rows.filter(r => scoreBucket(r.score_auto) === raw);
  }

  // Page/pager info:
  state.total    = isBucket ? rows.length : (data.total || rows.length || 0);
  state.page     = data.page || 1;
  state.pageSize = data.page_size || 50;

  const tb = document.querySelector('#pending-table tbody');
  tb.innerHTML = '';

  rows.forEach(r=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="text-align:center;"><input type="checkbox" class="sugChk" value="${r.id}" data-id="${r.id}"></td>
      <td>${escapeHtml(r.text||'')}</td>
      <td>${scoreChip(r.score_auto)}</td>
      <td>${confChip(r.confidence)}</td>
      <td>${r.source||'—'}</td>
      <td>${r.lang||'—'}</td>
      <td>${r.geo||'—'}</td>
      <td>${fmtDate(r.collected_at)}</td>
      <td>
        <button class="btn pill-ok" onclick="approve(${r.id}, true)">Approve</button>
        <button class="btn pill-miss" onclick="rejectSug(${r.id})">Reject</button>
      </td>`;
    tb.appendChild(tr);
  });

  if (rows.length === 0) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="9" class="mono" style="opacity:.7">No results for this filter.</td>`;
    tb.appendChild(tr);
  }

  const pages = Math.max(1, Math.ceil(state.total / state.pageSize));
  document.getElementById('pg-info').textContent =
    `Page ${state.page} / ${pages} — ${state.total} pending`;
  document.getElementById('pg-prev').disabled = state.page<=1;
  document.getElementById('pg-next').disabled = state.page>=pages;


  // clear selections after any reload
  selectedSuggestions.clear();
  const selAll = document.getElementById('sugSelectAll');
  if (selAll) selAll.checked = false;
  updateSugBulkBar();
}

//refresh when filter changes
['f-source','f-lang','f-geo','f-minscore'].forEach(id=>{
  document.getElementById(id)?.addEventListener('change', () => {
    state.page = 1;
    loadPending();
  });
});

async function approve(id, makeActive){
  const res = await fetch(`${API_BASE}/suggestions/${id}/approve`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
    body: JSON.stringify({ make_active: !!makeActive })
  });
  const j = await res.json().catch(()=>({}));
  if (!res.ok || j.error) { alert(j.error || `HTTP ${res.status}`); return; }

  await Promise.all([ loadPending(), loadPrompts(), refreshPendingBadge() ]);
}

async function rejectSug(id){
  await fetch(`${API_BASE}/suggestions/${id}/reject`, {
    method:'POST',
    headers:{ 'X-API-Key':API_KEY }
  });
  loadPending();
}

document.getElementById('pg-prev').addEventListener('click', ()=>{ if(state.page>1){ state.page--; loadPending(); }});
document.getElementById('pg-next').addEventListener('click', ()=>{
  const pages = Math.max(1, Math.ceil(state.total / state.pageSize));
  if(state.page<pages){ state.page++; loadPending(); }
});

/*---------- Suggestions Count -------------*/
async function refreshPendingBadge(){
  const res = await fetch(`${API_BASE}/suggestions/count`, { headers: { 'X-API-Key': API_KEY }});
  const { pending } = await res.json();
  const el = document.querySelector('#prompts-tab-badge'); // add a span in your tab
  if (el) el.textContent = pending > 0 ? pending : '';
}

function getSelectedSugIds() {
  return Array.from(selectedSuggestions);
}

document.getElementById('bulkApproveSug')?.addEventListener('click', async () => {
  const ids = getSelectedSugIds();
  if (!ids.length) return alert('No suggestions selected');
  await fetch('../api/admin.php?action=bulk_approve_suggestions', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ ids })
  });
  selectedSuggestions.clear();
  const selAll = document.getElementById('sugSelectAll'); if (selAll) selAll.checked = false;
  await loadPending();
  await loadPrompts();
  await refreshPendingBadge?.();
  updateSugBulkBar();
});

document.getElementById('bulkRejectSug')?.addEventListener('click', async () => {
  const ids = getSelectedSugIds();
  if (!ids.length) return alert('No suggestions selected');
  await fetch('../api/admin.php?action=bulk_reject_suggestions', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ ids })
  });
  selectedSuggestions.clear();
  const selAll = document.getElementById('sugSelectAll'); if (selAll) selAll.checked = false;
  await loadPending();
  await refreshPendingBadge?.();
  updateSugBulkBar();
});


// Header select-all
document.getElementById('sugSelectAll')?.addEventListener('change', (e) => {
  const on = !!e.target.checked;
  document.querySelectorAll('.sugChk').forEach(cb => {
    cb.checked = on;
    const id = Number(cb.value);
    if (on) selectedSuggestions.add(id); else selectedSuggestions.delete(id);
  });
  updateSugBulkBar();
});

// Row checkbox (event delegation on the table)
document.getElementById('pending-table')?.addEventListener('change', (e) => {
  const cb = e.target;
  if (!cb.classList?.contains('sugChk')) return;
  const id = Number(cb.value);
  if (cb.checked) selectedSuggestions.add(id); else selectedSuggestions.delete(id);

  // If a single row was unchecked, uncheck header
  const selAll = document.getElementById('sugSelectAll');
  if (selAll && selAll.checked && !cb.checked) selAll.checked = false;

  updateSugBulkBar();
});


/*--------------- Config Logic ----------------*/
const postJSON = (url, payload) =>
  fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload||{}) })
    .then(async r=>{ const j=await r.json().catch(()=>({})); if(!r.ok||j.error) throw new Error(j.error||('HTTP '+r.status)); return j; });


/* ---------- PERSONA MAPPING FOR TOPICS ---------- */

// Load active personas for checkboxes
let activePersonas = [];

async function loadActivePersonas() {
  try {
    const res = await fetch(`${API_BASE}/topics/personas`, {
      headers: { 'X-API-Key': API_KEY }
    });
    const personas = await res.json();
    activePersonas = Array.isArray(personas) ? personas : [];
    return activePersonas;
  } catch (e) {
    console.error('Failed to load personas:', e);
    return [];
  }
}

// Enhanced topics list with persona info
async function loadTopicsListWithPersonas() {
  try {
    const res = await fetch(`${API_BASE}/topics/with-personas`, {
      headers: { 'X-API-Key': API_KEY }
    });
    const j = await res.json();
    
    const tb = $('#cfgTopicsTable tbody');
    tb.innerHTML = '';
    
    (j.rows || []).forEach(row => {
      const personaNames = (row.personas || []).map(p => p.name).join(', ');
      const personaCount = row.persona_count || 0;
      const personaDisplay = personaCount > 0 
        ? `${personaCount} selected` 
        : '<span style="color:#ef4444">⚠️ None</span>';
      
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${row.id}</td>
        <td>${row.name}</td>
        <td>${personaDisplay}</td>
        <td>${row.is_active ? 1 : 0}</td>
        <td>${row.last_generated_at || '—'}</td>
        <td style="display:flex;gap:4px;">
          <button class="btn pill-info" data-act="edit" data-id="${row.id}" 
                  title="Edit personas">✏️ Edit</button>
          <button class="btn pill-ok" data-act="regen" data-id="${row.id}" 
                  data-name="${row.name}">Regenerate</button>
          <button class="btn pill-or" data-act="toggle" data-id="${row.id}" 
                  data-active="${row.is_active ? 1 : 0}">
            ${row.is_active ? 'Deactivate' : 'Activate'}
          </button>
        </td>
      `;
      tb.appendChild(tr);
    });
    
    if (!j.rows || j.rows.length === 0) {
      const tr = document.createElement('tr');
      tr.innerHTML = '<td colspan="6" class="mono" style="opacity:.7">No topics yet.</td>';
      tb.appendChild(tr);
    }
  } catch (e) {
    console.error('Failed to load topics:', e);
  }
}

// Show persona selection modal
async function showPersonaModal(topicId, topicName) {
  // Load personas if not already loaded
  if (activePersonas.length === 0) {
    await loadActivePersonas();
  }
  
  if (activePersonas.length === 0) {
    alert('No personas found. Create at least one persona first.');
    return;
  }
  
  // Get current mappings
  let currentPersonas = [];
  try {
    const res = await fetch(`${API_BASE}/topics/${topicId}`, {
      headers: { 'X-API-Key': API_KEY }
    });
    const data = await res.json();
    currentPersonas = (data.personas || []).map(p => p.id);
  } catch (e) {
    console.error('Failed to load topic personas:', e);
  }
  
  // Build modal HTML
  const modalHTML = `
    <div id="personaModal" class="modal" style="display:block">
      <div class="modal-content" style="max-width:500px;background:#fff;padding:24px;border-radius:8px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <h3 style="margin:0">Select Personas for "${topicName}"</h3>
          <button class="btn alt" onclick="closePersonaModal()">&times;</button>
        </div>
        
        <p style="color:#666;font-size:14px;margin-bottom:16px;">
          Choose which personas should generate suggestions for this topic:
        </p>
        
        <div id="personaCheckboxes" style="max-height:300px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:12px;">
          ${activePersonas.map(p => `
            <label style="display:block;padding:8px;cursor:pointer;border-radius:4px;transition:background 0.2s;"
                   onmouseover="this.style.background='#f3f4f6'"
                   onmouseout="this.style.background='white'">
              <input type="checkbox" 
                     class="persona-checkbox" 
                     value="${p.id}" 
                     ${currentPersonas.includes(p.id) ? 'checked' : ''}
                     style="margin-right:8px;">
              <span style="font-weight:500;">${p.name}</span>
            </label>
          `).join('')}
        </div>
        
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;">
          <div id="personaModalMsg" class="mono" style="color:#ef4444;font-size:12px;min-height:20px;"></div>
        </div>
        
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
          <button class="btn alt" onclick="closePersonaModal()">Cancel</button>
          <button class="btn pill-ok" onclick="saveTopicPersonas(${topicId})">Save Personas</button>
        </div>
      </div>
    </div>
  `;
  
  // Add to page
  const existing = document.getElementById('personaModal');
  if (existing) existing.remove();
  
  document.body.insertAdjacentHTML('beforeend', modalHTML);
}

// Show persona selection matrix for bulk topic creation
function showBulkTopicPersonaModal(topics) {
  const modalHTML = `
    <div id="bulkPersonaModal" class="modal" style="display:block">
      <div class="modal-content" style="max-width:900px;background:#fff;padding:24px;border-radius:8px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <h3 style="margin:0">Map Personas to ${topics.length} Topic(s)</h3>
          <button class="btn alt" onclick="closeBulkPersonaModal()">&times;</button>
        </div>
        
        <p style="color:#666;font-size:14px;margin-bottom:16px;">
          Select which personas should generate suggestions for each topic:
        </p>
        
        <div style="overflow-x:auto;max-height:500px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;">
          <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead style="position:sticky;top:0;background:#f9fafb;z-index:10;">
              <tr>
                <th style="padding:12px;text-align:left;border-bottom:2px solid #e5e7eb;border-right:1px solid #e5e7eb;min-width:200px;">
                  Topic
                </th>
                ${activePersonas.map(p => `
                  <th style="padding:12px;text-align:center;border-bottom:2px solid #e5e7eb;border-right:1px solid #e5e7eb;min-width:120px;">
                    <div style="font-weight:600;margin-bottom:4px;">${p.name}</div>
                    <label style="font-weight:400;font-size:12px;color:#6b7280;cursor:pointer;">
                      <input type="checkbox" class="select-all-persona" data-persona-id="${p.id}" 
                             style="margin-right:4px;">
                      Select All
                    </label>
                  </th>
                `).join('')}
              </tr>
            </thead>
            <tbody>
              ${topics.map((topic, topicIdx) => `
                <tr style="border-bottom:1px solid #e5e7eb;">
                  <td style="padding:12px;border-right:1px solid #e5e7eb;font-weight:500;">
                    <div style="display:flex;align-items:center;gap:8px;">
                      <span>${topic}</span>
                      <label style="font-size:12px;color:#6b7280;font-weight:400;cursor:pointer;margin-left:auto;">
                        <input type="checkbox" class="select-all-topic" data-topic-idx="${topicIdx}"
                               style="margin-right:4px;">
                        All
                      </label>
                    </div>
                  </td>
                  ${activePersonas.map(p => `
                    <td style="padding:12px;text-align:center;border-right:1px solid #e5e7eb;background:#fafafa;">
                      <input type="checkbox" 
                             class="topic-persona-checkbox" 
                             data-topic-idx="${topicIdx}"
                             data-persona-id="${p.id}"
                             checked
                             style="width:18px;height:18px;cursor:pointer;">
                    </td>
                  `).join('')}
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
        
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;">
          <div id="bulkPersonaModalMsg" class="mono" style="color:#ef4444;font-size:12px;min-height:20px;"></div>
        </div>
        
        <div style="display:flex;gap:8px;justify-content:space-between;margin-top:16px;">
          <div style="display:flex;gap:8px;">
            <button class="btn pill-info" onclick="selectAllMatrix()">Select All</button>
            <button class="btn alt" onclick="deselectAllMatrix()">Deselect All</button>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn alt" onclick="closeBulkPersonaModal()">Cancel</button>
            <button class="btn pill-ok" onclick="saveBulkTopicsWithPersonas()">Save & Generate</button>
          </div>
        </div>
      </div>
    </div>
  `;
  
  const existing = document.getElementById('bulkPersonaModal');
  if (existing) existing.remove();
  
  document.body.insertAdjacentHTML('beforeend', modalHTML);
  
  // Add event listeners for "Select All" checkboxes
  
  // Select all for a persona (column)
  document.querySelectorAll('.select-all-persona').forEach(cb => {
    cb.addEventListener('change', (e) => {
      const personaId = e.target.dataset.personaId;
      const checked = e.target.checked;
      document.querySelectorAll(`[data-persona-id="${personaId}"].topic-persona-checkbox`).forEach(box => {
        box.checked = checked;
      });
    });
  });
  
  // Select all for a topic (row)
  document.querySelectorAll('.select-all-topic').forEach(cb => {
    cb.addEventListener('change', (e) => {
      const topicIdx = e.target.dataset.topicIdx;
      const checked = e.target.checked;
      document.querySelectorAll(`[data-topic-idx="${topicIdx}"].topic-persona-checkbox`).forEach(box => {
        box.checked = checked;
      });
    });
  });
}

// Select all checkboxes in matrix
window.selectAllMatrix = function() {
  document.querySelectorAll('.topic-persona-checkbox').forEach(cb => cb.checked = true);
  document.querySelectorAll('.select-all-persona').forEach(cb => cb.checked = true);
  document.querySelectorAll('.select-all-topic').forEach(cb => cb.checked = true);
};

// Deselect all checkboxes in matrix
window.deselectAllMatrix = function() {
  document.querySelectorAll('.topic-persona-checkbox').forEach(cb => cb.checked = false);
  document.querySelectorAll('.select-all-persona').forEach(cb => cb.checked = false);
  document.querySelectorAll('.select-all-topic').forEach(cb => cb.checked = false);
};

// Close bulk modal
window.closeBulkPersonaModal = function() {
  const modal = document.getElementById('bulkPersonaModal');
  if (modal) modal.remove();
  $('#cfgTopics').value = ''; // Clear textarea
};

// Save topics with selected personas from matrix
window.saveBulkTopicsWithPersonas = async function() {
  const msgEl = document.getElementById('bulkPersonaModalMsg');
  
  const raw = ($('#cfgTopics').value || '').trim();
  const topics = raw.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
  
  // Build array: [{ topic: "...", persona_ids: [1,2,3] }, ...]
  const topicPersonaMappings = topics.map((topic, idx) => {
    const checkboxes = document.querySelectorAll(`.topic-persona-checkbox[data-topic-idx="${idx}"]:checked`);
    const personaIds = Array.from(checkboxes).map(cb => parseInt(cb.dataset.personaId));
    return { topic, persona_ids: personaIds };
  });
  
  // Validate: each topic needs at least one persona
  const invalid = topicPersonaMappings.filter(m => m.persona_ids.length === 0);
  if (invalid.length > 0) {
    msgEl.textContent = `⚠️ Select at least one persona for each topic (${invalid.length} topic(s) have none)`;
    return;
  }
  
  try {
    msgEl.textContent = 'Creating topics and generating suggestions...';
    msgEl.style.color = '#f59e0b';
    
    // Create each topic with its personas
    for (let i = 0; i < topicPersonaMappings.length; i++) {
      const mapping = topicPersonaMappings[i];
      
      msgEl.textContent = `(${i + 1}/${topics.length}) Creating "${mapping.topic}"...`;
      
      // Create topic with personas
      const res = await fetch(`${API_BASE}/topics/store-with-personas`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-API-Key': API_KEY
        },
        body: JSON.stringify({
          name: mapping.topic,
          persona_ids: mapping.persona_ids
        })
      });
      
      const result = await res.json();
      
      if (!res.ok || result.error) {
        throw new Error(result.error || 'Failed to create topic');
      }
      
      // Now generate for this topic with selected personas
      msgEl.textContent = `(${i + 1}/${topics.length}) Generating for "${mapping.topic}"...`;
      await runTopicBatched(mapping.topic);
    }
    
    // Success
    $('#cfgTopicsMsg').textContent = `✓ All ${topics.length} topic(s) created and generated!`;
    $('#cfgTopicsMsg').style.color = '#10b981';
    
    closeBulkPersonaModal();
    await loadTopicsListWithPersonas();
    if (typeof loadPending === 'function') loadPending();
    if (typeof refreshPendingBadge === 'function') refreshPendingBadge();
    
    setTimeout(() => {
      if ($('#cfgTopicsMsg').textContent.includes('created')) {
        $('#cfgTopicsMsg').textContent = '';
        $('#cfgTopicsMsg').style.color = '';
      }
    }, 5000);
    
  } catch (e) {
    msgEl.textContent = '✗ Error: ' + e.message;
    msgEl.style.color = '#ef4444';
  }
};

// Close modal
window.closePersonaModal = function() {
  const modal = document.getElementById('personaModal');
  if (modal) modal.remove();
};

// Save persona mappings
window.saveTopicPersonas = async function(topicId) {
  const checkboxes = document.querySelectorAll('.persona-checkbox:checked');
  const personaIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
  
  const msgEl = document.getElementById('personaModalMsg');
  
  if (personaIds.length === 0) {
    msgEl.textContent = '⚠️ Please select at least one persona';
    return;
  }
  
  try {
    const res = await fetch(`${API_BASE}/topics/${topicId}/personas`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': API_KEY
      },
      body: JSON.stringify({ persona_ids: personaIds })
    });
    
    const result = await res.json();
    
    if (!res.ok || result.error) {
      throw new Error(result.error || 'Failed to save');
    }
    
    // Success
    closePersonaModal();
    await loadTopicsListWithPersonas();
    
  } catch (e) {
    msgEl.textContent = '✗ Error: ' + e.message;
  }
};

/* ---------- END PERSONA MAPPING ---------- */
async function loadPersonasList(){
  const r = await fetch(`${API_BASE}/admin?action=list_personas`);
  const j = await r.json();
  const tb = $('#cfgPersonasTable tbody'); tb.innerHTML='';

  (j.rows||[]).forEach(p=>{
    personasCache[p.id] = p; // <-- store full row

    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${p.id}</td><td>${p.name}</td><td>${p.brand_id??''}</td>
      <td>${p.is_active?1:0}</td><td>${p.updated_at||''}</td>
      <td style="display:flex;gap:5px;padding:11px;">
        <button class="btn pill-or perDeactBtn ${p.is_active ? '' : 'alt'}"
                data-act="togglePersona" data-id="${p.id}" data-active="${p.is_active?1:0}">
          ${p.is_active?'Deactivate':'Activate'}
        </button>
        <button class="btn pill-miss" data-act="delPersona" data-id="${p.id}">Delete</button>
      </td><td><button style="background: #E8FFF2" class="btn pill-ok alt" data-pid="${p.id}">View</button></td>`;
    tb.appendChild(tr);
  });
}

async function loadBrandsIntoPersonaSelect(){
  const r = await fetch(`${API_BASE}/admin?action=list_brands&t=${Date.now()}`, { cache: 'no-store' });
  const j = await r.json();
  const sel = $('#cfgPersonaBrand');
  sel.innerHTML = '<option value="">— Brand (Mandatory) —</option>';
  (j.brands || []).forEach(b => {
    const o = document.createElement('option');
    o.value = b.id; 
    o.textContent = `${b.name} (${b.id})`;
    sel.appendChild(o);
  });
}

async function loadConfig(){ 
  await Promise.all([
    loadTopicsListWithPersonas(),  // ← CHANGED
    loadPersonasList(), 
    loadBrandsIntoPersonaSelect()
  ]); 
}

// --- batching settings (tune if needed)
const PERSONAS_PER_CALL = 4;   // try 3 if your server is tight
const MAX_RETRIES = 2;

async function runTopicBatched(topic) {
  let personaStart = 0;
  let done = false;
  let totalGenerated = 0;

  while (!done) {
    // simple progress
    $('#cfgTopicsMsg').textContent =
      `Generating "${topic}" — personas ${personaStart + 1} to ${personaStart + PERSONAS_PER_CALL}…`;

    // retry wrapper for flaky networks
    let attempts = 0, lastErr = null;
    while (attempts <= MAX_RETRIES) {
      try {
        const res = await postJSON(`${API_BASE}/topics`, {
          topic,
          persona_start: personaStart,
          persona_limit: PERSONAS_PER_CALL
        });
        
        // Accumulate total generated
        totalGenerated += (res.generated || 0);
        
        // cursor from the server
        done = !!res.done;
        personaStart = (typeof res.next_persona === 'number')
          ? res.next_persona
          : (personaStart + PERSONAS_PER_CALL);
        break;
      } catch (e) {
        lastErr = e; attempts++;
        if (attempts > MAX_RETRIES) throw lastErr;
        await new Promise(r => setTimeout(r, 1200));
      }
    }
  }

  // Show completion message
  $('#cfgTopicsMsg').textContent = `✓ Topic "${topic}" completed! Generated ${totalGenerated} suggestions.`;
  
  // Auto-clear message after 5 seconds
  setTimeout(() => {
    if ($('#cfgTopicsMsg').textContent.includes('completed')) {
      $('#cfgTopicsMsg').textContent = '';
    }
  }, 5000);
}

$('#cfgSaveTopics')?.addEventListener('click', async () => {
  const raw = ($('#cfgTopics').value || '').trim();
  const topics = raw.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
  
  if (!topics.length) {
    $('#cfgTopicsMsg').textContent = 'Nothing to save.';
    return;
  }
  
  try {
    // Load personas first
    if (activePersonas.length === 0) {
      await loadActivePersonas();
    }
    
    if (activePersonas.length === 0) {
      $('#cfgTopicsMsg').textContent = '✗ No personas found. Create at least one persona first.';
      return;
    }
    
    // Show persona selection modal for the topics
    showBulkTopicPersonaModal(topics);
    
  } catch (e) {
    $('#cfgTopicsMsg').textContent = '✗ Error: ' + e.message;
  }
});

// Topics table actions
$('#cfgTopicsTable')?.addEventListener('click', async (e)=>{
  const t = e.target; 
  const id = Number(t.dataset.id || 0); 
  if (!id) return;
  
  // NEW: Edit personas
  if (t.dataset.act === 'edit') {
    const name = t.closest('tr').querySelector('td:nth-child(2)').textContent;
    await showPersonaModal(id, name);
    return;
  }
  
  // Toggle active/inactive
  if (t.dataset.act === 'toggle') {
    const active = t.dataset.active === '1' ? 0 : 1;
    try {
      await fetch(`${API_BASE}/topics/set-active`, {  // ← FIXED: Added parenthesis
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-API-Key': API_KEY
        },
        body: JSON.stringify({ id, active })
      });
      await loadTopicsListWithPersonas();
    } catch (e) {
      alert('Toggle failed: ' + e.message);
    }
    return;
  }
  
  // Regenerate
  if (t.dataset.act === 'regen') {
    const name = t.dataset.name || '';
    try {
      await fetch(`${API_BASE}/topics/touch`, {  // ← FIXED: Added parenthesis
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-API-Key': API_KEY
        },
        body: JSON.stringify({ id })
      });
      await runTopicBatched(name);
      await loadTopicsListWithPersonas();
      if (typeof loadPending === 'function') loadPending();
    } catch (e) {
      alert('Regenerate failed: ' + e.message);
    }
    return;
  }
});

$('#cfgSavePersona')?.addEventListener('click', async ()=>{
  const name = ($('#cfgPersonaName').value||'').trim();
  const brand_id = ($('#cfgPersonaBrand').value||'').trim() || null;
  const description = ($('#cfgPersonaDesc').value||'').trim();
  const attrsRaw = ($('#cfgPersonaAttrs').value||'').trim();
  if (!name || !description){ $('#cfgPersonaMsg').textContent='Name & description required.'; return; }
  let attributes = null; if (attrsRaw){ try{ attributes=JSON.parse(attrsRaw); }catch{ attributes=attrsRaw; } }
  try{
    await postJSON(`${API_BASE}/admin?action=save_persona`, { name, brand_id, description, attributes });
    $('#cfgPersonaMsg').textContent='Saved.'; $('#cfgPersonaName').value=''; $('#cfgPersonaDesc').value=''; $('#cfgPersonaAttrs').value='';
    await loadPersonasList();
  }catch(e){ $('#cfgPersonaMsg').textContent='Error: '+e.message; }
});

// Personas table actions (robust: click -> closest button)
$('#cfgPersonasTable')?.addEventListener('click', async (e) => {
  const btn = e.target.closest('button');
  if (!btn || !btn.dataset) return;

  const act = btn.dataset.act;
  const id  = Number(btn.dataset.id || 0);
  if (!id || !act) return;

  // DELETE
  if (act === 'delPersona') {
    try {
      await postJSON(`${API_BASE}/admin?action=delete_persona`, { id });
      await loadPersonasList();
    } catch (err) {
      alert(err.message || err);
    }
    return;
  }

  // TOGGLE (activate/deactivate)
  if (act === 'togglePersona') {
    const makeActive = btn.dataset.active === '1' ? 0 : 1;

    // Use cached row so other fields aren't wiped
    const p = personasCache[id] || {};
    try {
      await postJSON(`${API_BASE}/admin?action=save_persona`, {
        id,
        name: p.name ?? null,
        brand_id: p.brand_id ?? null,
        description: p.description ?? null,
        attributes: p.attributes ?? null,
        is_active: makeActive
      });
      // visual feedback while the table reloads
      btn.classList.toggle('alt', !makeActive);
      await loadPersonasList();
    } catch (err) {
      alert(err.message || err);
    }
  }
});


/* ---------- init ---------- */
show('Dashboard');
fetchMetrics().then(loadMentions);
loadPrompts();
loadBrands();
loadPending();
loadConfig();
