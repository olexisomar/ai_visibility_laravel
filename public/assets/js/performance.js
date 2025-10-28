/*--------------Performance Tab here------------*/

const perfState = { showMentioned:true, showNot:true, showNone:true, rows:[] };

function svgStackedColumns(rows, opts={}){
  // dims
  const m = { top:16, right:8, bottom:36, left:48 };
  const barW = 36, gap = 24;
  const n = rows.length;
  const chartW = Math.max(1, n*(barW+gap)-gap);
  const chartH = 240;
  const W = chartW + m.left + m.right, H = chartH + m.top + m.bottom;

  // y-scale
  const yMax = Math.max(1, ...rows.map(r => (r.mentioned||0)+(r.not_mentioned||0)+(r.no_brands||0)));
  const y = v => m.top + chartH - (v/yMax)*chartH;

  // colors/series (respect legend)
  const series = [];
  if (perfState.showMentioned) series.push({key:'mentioned', color:'#6EE7B7', stroke:'#10B981', label:'Mentioned'});
  if (perfState.showNot)       series.push({key:'not_mentioned', color:'#C4B5FD', stroke:'#8B5CF6', label:'Not mentioned'});
  if (perfState.showNone && rows.some(r=>r.no_brands != null))
                               series.push({key:'no_brands', color:'#FCA5A5', stroke:'#EF4444', label:'No brands found'});

  const x = i => m.left + i*(barW+gap);

  // axes (simple)
  const ticks = 4;
  let grid = '';
  for(let t=0;t<=ticks;t++){
    const v = (yMax/ticks)*t;
    const yy = y(v);
    grid += `<line x1="${m.left}" y1="${yy}" x2="${m.left+chartW}" y2="${yy}" stroke="#eee"/>`+
            `<text x="${m.left-6}" y="${yy+4}" text-anchor="end" font-size="11" fill="#777">${Math.round(v)}</text>`;
  }

  // bars
  let bars = '', labels = '';
  rows.forEach((r, i) => {
    let yCursor = y(0); // bottom
    const total = series.reduce((s,sr)=> s + Number(r[sr.key]||0), 0);
    series.forEach(sr=>{
      const v = Number(r[sr.key]||0);
      if (!v) return;
      const h = (v/yMax)*chartH;
      const yy = yCursor - h;
      bars += `<rect x="${x(i)}" y="${yy}" width="${barW}" height="${h}"
                 fill="${sr.color}" stroke="${sr.stroke}" stroke-width="0.6"
                 ><title>${r.week_start}\n${sr.label}: ${v}${total?` (${Math.round(100*v/total)}%)`:''}</title></rect>`;
      yCursor = yy;
    });
    labels += `<text x="${x(i)+barW/2}" y="${m.top+chartH+16}" text-anchor="middle" font-size="11" fill="#666">${r.week_start.slice(5)}</text>`;
  });

  return `<svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}">
    <rect x="0" y="0" width="${W}" height="${H}" fill="transparent"/>
    ${grid}
    ${bars}
    ${labels}
  </svg>`;
}
// --- helper: render with custom flags without touching global perfState
function renderStacked(container, rows, flags){
  const old = { showMentioned:perfState.showMentioned, showNot:perfState.showNot, showNone:perfState.showNone };
  perfState.showMentioned = !!flags.showMentioned;
  perfState.showNot       = !!flags.showNot;
  perfState.showNone      = !!flags.showNone;   // we won't show "no_brands" for citations
  container.innerHTML = svgStackedColumns(rows);
  Object.assign(perfState, old);
}

async function loadPerformanceMentions(){
  // default dates last 21d
  const fromEl = document.getElementById('perfFrom');
  const toEl   = document.getElementById('perfTo');
  if (fromEl && !fromEl.value){
    const to = new Date(), from = new Date(); from.setDate(to.getDate()-21);
    toEl.value = to.toISOString().slice(0,10);
    fromEl.value = from.toISOString().slice(0,10);
  }

  const params = new URLSearchParams({
    action:'brand_mentions_overtime',
    model: document.getElementById('perfModel')?.value || 'all',
    from:  fromEl?.value || '',
    to:    toEl?.value || ''
  });
  params.set('group_by', getGroupBy());

  const cont = document.getElementById('perfMentionsChart');
  cont.innerHTML = '<div class="mono" style="opacity:.7">Loading…</div>';

  const r = await fetch(`${API_BASE}/performance?` + params.toString());
  const j = await r.json();
  const rows = j.rows || [];
  perfState.rows = rows;

  if (!rows.length){
    cont.innerHTML = '<div class="mono" style="opacity:.7">No data in this range.</div>';
    return;
  }
  cont.innerHTML = svgStackedColumns(rows);
}


/*----- Website Citation------*/

// --- utils to build query like your other performance fetches
function perfQS(params = {}) {
  const q = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v == null || v === '' || (Array.isArray(v) && v.length === 0)) return;
    if (Array.isArray(v)) v.forEach(val => q.append(k, val));
    else q.set(k, v);
  });
  return q.toString() ? ('?' + q.toString()) : '';
}

async function loadPerfCitations() {
  // use same controls as Brand Mentions
  const from = document.getElementById('perfFrom')?.value || '';
  const to   = document.getElementById('perfTo')?.value   || '';
  const model= document.getElementById('perfModel')?.value || 'all';

  const q = new URLSearchParams({ action:'citations_overtime', from, to, model });
  q.set('group_by', getGroupBy());
  const r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  const j = await r.json();
  const rows = Array.isArray(j.rows) ? j.rows : [];

  // update header meta (consistent with left card)
  const meta = document.getElementById('perfCitationsMeta');
  if (meta) {
    const total = rows.reduce((s,r)=> s+(r.total||0), 0);
    const cited = rows.reduce((s,r)=> s+(r.cited||0), 0);
    meta.textContent = `(${from||'—'} → ${to||'—'}) • total: ${total} • cited: ${cited}`;
  }

  // map to the same keys your renderer expects
  const mapped = rows.map(r => ({
    week_start:    r.week_start,
    mentioned:     Number(r.cited||0),
    not_mentioned: Number(r.not_cited||0),
    no_brands:     null // keep hidden on citations chart
  }));

  const host = document.getElementById('citationsSpark');
  if (!host) return;

  // read legend flags
  const flags = {
    showMentioned: !!document.getElementById('perfCited')?.checked,
    showNot:       !!document.getElementById('perfNotCited')?.checked,
    showNone:      false
  };

  renderStacked(host, mapped, flags);
}

// --- tiny helper: render custom series with the same SVG engine
function svgStackedCustom(rows, seriesList){
  // Temporarily override flags to show all series; colors come from seriesList
  const m = { top:16, right:8, bottom:36, left:48 };
  const barW = 36, gap = 24;
  const n = rows.length;
  const chartW = Math.max(1, n*(barW+gap)-gap);
  const chartH = 240;
  const W = chartW + m.left + m.right, H = chartH + m.top + m.bottom;

  const yMax = Math.max(1, ...rows.map(r => seriesList.reduce((s,sr)=> s + Number(r[sr.key]||0), 0)));
  const y = v => m.top + chartH - (v/yMax)*chartH;
  const x = i => m.left + i*(barW+gap);

  // grid
  const ticks = 4;
  let grid = '';
  for(let t=0;t<=ticks;t++){
    const v = (yMax/ticks)*t;
    const yy = y(v);
    grid += `<line x1="${m.left}" y1="${yy}" x2="${m.left+chartW}" y2="${yy}" stroke="#eee"/>`+
            `<text x="${m.left-6}" y="${yy+4}" text-anchor="end" font-size="11" fill="#777">${Math.round(v)}</text>`;
  }

  // bars + labels
  let bars = '', labels = '';
  rows.forEach((r,i)=>{
    let yCursor = y(0);
    const total = seriesList.reduce((s,sr)=> s + Number(r[sr.key]||0), 0);
    seriesList.forEach(sr=>{
      const v = Number(r[sr.key]||0);
      if (!v) return;
      const h = (v/yMax)*chartH;
      const yy = yCursor - h;
      bars += `<rect x="${x(i)}" y="${yy}" width="${barW}" height="${h}" fill="${sr.color}" stroke="${sr.stroke}" stroke-width="0.6">
                 <title>${r.week_start}\n${sr.label}: ${v}${total?` (${Math.round(100*v/total)}%)`:''}</title>
               </rect>`;
      yCursor = yy;
    });
    labels += `<text x="${x(i)+barW/2}" y="${m.top+chartH+16}" text-anchor="middle" font-size="11" fill="#666">${r.week_start.slice(5)}</text>`;
  });

  return `<svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}">
    <rect x="0" y="0" width="${W}" height="${H}" fill="transparent"/>
    ${grid}
    ${bars}
    ${labels}
  </svg>`;
}
/*----- Intent Performance Card -----*/
// state for the intent card (toggles)
const perfIntentState = { info:true, nav:true, tran:true, other:true, rows:[] };

function repaintIntentFromCache(){
  const host = document.getElementById('perfIntentChart');
  if (!host) return;
  const rows = perfIntentState.rows || [];

  const seriesAll = [
    { key:'informational', label:'Informational', color:'#93C5FD', stroke:'#3B82F6' },
    { key:'navigational',  label:'Navigational',  color:'#A7F3D0', stroke:'#10B981' },
    { key:'transactional', label:'Transactional', color:'#FDE68A', stroke:'#F59E0B' },
    { key:'other',         label:'Other',         color:'#E5E7EB', stroke:'#9CA3AF' },
  ];
  const visible = seriesAll.filter(sr =>
    (sr.key==='informational' && perfIntentState.info) ||
    (sr.key==='navigational'  && perfIntentState.nav)  ||
    (sr.key==='transactional' && perfIntentState.tran) ||
    (sr.key==='other'         && perfIntentState.other)
  );

  host.innerHTML = svgStackedCustom(rows, visible);
}


// loader
async function loadPerfIntent(){
  const from = document.getElementById('perfFrom')?.value || '';
  const to   = document.getElementById('perfTo')?.value   || '';
  const model= document.getElementById('perfModel')?.value || 'all';

  const host = document.getElementById('perfIntentChart');
  if (!host) return;
  host.innerHTML = '<div class="mono" style="opacity:.7">Loading…</div>';

  const q = new URLSearchParams({ action:'intent_overtime', from, to, model });
  q.set('group_by', getGroupBy());
  const r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  const j = await r.json();
  perfIntentState.rows = Array.isArray(j.rows) ? j.rows : [];

  if (!perfIntentState.rows.length){
    host.innerHTML = '<div class="mono" style="opacity:.7">No data in this range.</div>';
    return;
  }

  repaintIntentFromCache(); // << draw without refetching again
}

/*-------- Sentiment Over Time Card --------*/

// Helpers
const perfSentState = { pos:true, neu:true, neg:true, rows:[] };

function repaintSentFromCache(){
  const host = document.getElementById('perfSentChart');
  if (!host) return;
  const rows = perfSentState.rows || [];

  // Reuse the same stacked renderer with a custom series list
  const seriesAll = [
    { key:'positive', label:'Positive', color:'#A7F3D0', stroke:'#10B981' },
    { key:'neutral',  label:'Neutral',  color:'#E5E7EB', stroke:'#9CA3AF' },
    { key:'negative', label:'Negative', color:'#FCA5A5', stroke:'#EF4444' },
  ];
  const visible = seriesAll.filter(sr =>
    (sr.key==='positive' && perfSentState.pos) ||
    (sr.key==='neutral'  && perfSentState.neu) ||
    (sr.key==='negative' && perfSentState.neg)
  );

  host.innerHTML = svgStackedCustom(rows, visible);
}

//Loader
async function loadPerfSentiment(){
  const from  = document.getElementById('perfFrom')?.value || '';
  const to    = document.getElementById('perfTo')?.value   || '';
  const model = document.getElementById('perfModel')?.value || 'all';

  const host = document.getElementById('perfSentChart');
  if (!host) return;
  host.innerHTML = '<div class="mono" style="opacity:.7">Loading…</div>';

  const q = new URLSearchParams({ action:'sentiment_overtime', from, to, model });
  q.set('group_by', getGroupBy());
  const r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  const j = await r.json();
  perfSentState.rows = Array.isArray(j.rows) ? j.rows : [];

  const meta = document.getElementById('perfSentMeta');
  if (meta) {
    const total = perfSentState.rows.reduce((s, r) => s + (r.total||0), 0);
    const pos   = perfSentState.rows.reduce((s, r) => s + (r.positive||0), 0);
    const neu   = perfSentState.rows.reduce((s, r) => s + (r.neutral||0), 0);
    const neg   = perfSentState.rows.reduce((s, r) => s + (r.negative||0), 0);
    meta.textContent = `(${from||'—'} → ${to||'—'}) • total: ${total} • +${pos} / ~${neu} / -${neg}`;
  }

  if (!perfSentState.rows.length){
    host.innerHTML = '<div class="mono" style="opacity:.7">No data in this range.</div>';
    return;
  }

  repaintSentFromCache();
}

// ---------- Persona Performance Over Time ----------
const perfPersonaState = {
  rows: [],              // pivoted rows keyed by series keys
  on: Object.create(null) // personaName -> boolean (visible)
};

// palette (soft, readable). Add more if you have many personas.
const personaColors = [
  { fill:'#93C5FD', stroke:'#3B82F6' },
  { fill:'#A7F3D0', stroke:'#10B981' },
  { fill:'#FDE68A', stroke:'#F59E0B' },
  { fill:'#C4B5FD', stroke:'#8B5CF6' },
  { fill:'#FCA5A5', stroke:'#EF4444' },
  { fill:'#FBCFE8', stroke:'#DB2777' },
  { fill:'#DDD6FE', stroke:'#7C3AED' },
  { fill:'#D1FAE5', stroke:'#059669' },
  { fill:'#E5E7EB', stroke:'#9CA3AF' },
];

async function loadPerfPersona(){
  const from  = document.getElementById('perfFrom')?.value || '';
  const to    = document.getElementById('perfTo')?.value   || '';
  const model = document.getElementById('perfModel')?.value || 'all';

  const host = document.getElementById('perfPersonaChart');
  if (!host) return;
  host.innerHTML = '<div class="mono" style="opacity:.7">Loading…</div>';

  const q = new URLSearchParams({ action:'persona_overtime', from, to, model });
  q.set('group_by', getGroupBy());
  const r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  const j = await r.json();
  const raw = Array.isArray(j.rows) ? j.rows : [];

  // Collect personas and week buckets
  const personas = Array.from(new Set(raw.map(d => d.persona))).sort();
  const byWeek = new Map();
  raw.forEach(d => {
    const wk = d.week_start;
    if (!byWeek.has(wk)) byWeek.set(wk, { week_start: wk });
    const row = byWeek.get(wk);
    row[d.persona] = (row[d.persona] || 0) + Number(d.count || 0);
  });
  const rows = Array.from(byWeek.values());

  // meta
  const meta = document.getElementById('perfPersonaMeta');
  if (meta) {
    const total = raw.reduce((s,d)=> s + (d.count||0), 0);
    meta.textContent = `(${from||'—'} → ${to||'—'}) • total: ${total}`;
  }

  // init visibility defaults (once per persona)
  personas.forEach(name => {
    if (!(name in perfPersonaState.on)) perfPersonaState.on[name] = true;
  });

  // Build series list based on current toggles
  const seriesList = [];
  personas.forEach((name, idx) => {
    if (!perfPersonaState.on[name]) return;
    const c = personaColors[idx % personaColors.length];
    seriesList.push({ key: name, label: name, color: c.fill, stroke: c.stroke });
  });

  // If nothing selected, show empty state
  if (!rows.length) {
    host.innerHTML = '<div class="mono" style="opacity:.7">No data in this range.</div>';
  } else {
    // svgStackedCustom expects keys to be present on rows; we already put persona names as keys
    host.innerHTML = svgStackedCustom(rows, seriesList);
  }

  // Render legend (checkboxes) — change events only, no anchors
  const legend = document.getElementById('perfPersonaLegend');
  if (legend) {
    legend.innerHTML = '';
    personas.forEach((name, idx) => {
      const c = personaColors[idx % personaColors.length];
      const id = `pp_${idx}`; // unique, not reused elsewhere
      const wrap = document.createElement('label');
      wrap.style.cssText = 'display:flex;align-items:center;gap:6px;cursor:pointer;';
      wrap.innerHTML = `
        <input type="checkbox" id="${id}" ${perfPersonaState.on[name] ? 'checked' : ''}>
        <span style="width:12px;height:12px;background:${c.fill};border:1px solid ${c.stroke};display:inline-block"></span>
        ${name}
      `;
      // attach after insertion so event doesn’t bubble before DOM has finished
      legend.appendChild(wrap);
      legend.querySelector('#' + id).addEventListener('change', (e) => {
        perfPersonaState.on[name] = e.target.checked;
        loadPerfPersona(); // same pattern as Intent
      });
    });
  }
}

/* ===== Sentiment Sources + Explore ===== */

// Sentiment sources pagination state
const sentSourcesState = { 
  showAll: false,
  limit: 4 
};

// Helper: Open sentiment modal with proper title
function openSentimentModal(domain = '') {
  sentState.domain = domain;
  
  const modalTitle = document.querySelector('#sentModal .modal-header b');
  if (modalTitle) {
    if (domain && domain !== '') {
      modalTitle.textContent = `Sentiment Analysis - ${domain}`;
    } else {
      modalTitle.textContent = 'Sentiment Analysis - All Sources';
    }
  }
  
  document.getElementById('sentModal').classList.remove('hidden');
  loadSentimentExplore();
}

function currentPerfFilters() {
  const picked = document.getElementById('mentionsBrand')?.value || '';
  // only keep a valid brand id; otherwise empty
  const brand = asBrandIdOrEmpty(picked) || asBrandIdOrEmpty(PRIMARY_BRAND_ID) || '';

  return {
    from:  document.getElementById('perfFrom')?.value || '',
    to:    document.getElementById('perfTo')?.value   || '',
    model: document.getElementById('perfModel')?.value || 'all',
    brand,
    owned_host: OWNED_HOST || ''
  };
}

function getSelectedPolarity() {
  const r = document.querySelector('input[name="sentPol"]:checked');
  return r ? r.value : 'negative';
}

async function loadSentimentSources() {
  const { from, to, model } = currentPerfFilters();
  const polarity = getSelectedPolarity();
  const metric = document.getElementById('sentMetric')?.value || 'citations';
  const brand = document.getElementById('sentBrandFilter')?.value || '';
  
  const q = new URLSearchParams({ 
    action: 'sentiment_sources', 
    from, to, model, 
    polarity,
    metric
  });
  
  if (brand) q.set('brand', brand);

  const r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  const j = await r.json();
  
  if (j.owned_host) {
    OWNED_HOST = String(j.owned_host).toLowerCase();
  }

  const host = document.getElementById('sentSourcesList');

  const apiRows = Array.isArray(j.rows) ? j.rows : [];
  const totalFromApi = Number(j.total || 0);
  const sumCounts = apiRows.reduce((s, r) => s + (r.count || 0), 0);
  const denom = totalFromApi || sumCounts || 1;

  const rows = apiRows.map((r, i) => ({
    rank: i + 1,
    host: (r.domain === '(unsourced)') ? 'Unsourced' : (r.domain || '(unknown)'),
    count: r.count || 0,
    share: Number.isFinite(+r.pct) ? (+r.pct)/100 : ((r.count || 0) / denom)
  }));

  if (rows.length === 0) {
    host.innerHTML = '<div class="mono" style="opacity:.7">No sources for this slice.</div>';
    return;
  }

  // Pagination logic
  const limit = sentSourcesState.limit;
  const hasMore = rows.length > limit;
  const displayRows = sentSourcesState.showAll ? rows : rows.slice(0, limit);

  // Update label based on metric
  const labelText = metric === 'mentions' ? 'brand mentions' : 'website citations';

  host.innerHTML = displayRows.map(row => {
    const pct = (row.share*100).toFixed(2) + '%';
    const badge = `<span class="pill pill-miss" style="min-width:38px;text-align:center;">${row.count}</span>`;
    const domainAttr = row.host === 'Unsourced' ? '(unsourced)' : row.host;
    
    return `
      <div class="src-row" data-domain="${domainAttr}"
           style="display:flex;align-items:center;justify-content:space-between;border:1px solid #eee;border-radius:10px;padding:10px 12px;margin:8px 0;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="mono" style="opacity:.7">#${row.rank}</span>
          <div>
            <div style="font-weight:600">${row.host}</div>
            <div style="font-size:12px;color:#666">${pct} of ${polarity} ${labelText}</div>
          </div>
        </div>
        ${badge}
      </div>`;
  }).join('');

  // Add "Show More" / "Show Less" button if needed
  if (hasMore) {
    const toggleBtn = document.createElement('button');
    toggleBtn.className = 'btn pill-info';
    toggleBtn.style.cssText = 'width:100%;margin-top:8px;';
    
    if (sentSourcesState.showAll) {
      toggleBtn.textContent = `Show Less`;
    } else {
      const hiddenCount = rows.length - limit;
      toggleBtn.textContent = `Show ${hiddenCount} More`;
    }
    
    toggleBtn.onclick = () => {
      sentSourcesState.showAll = !sentSourcesState.showAll;
      loadSentimentSources();
    };
    
    host.appendChild(toggleBtn);
  }
}

// Event listeners
document.getElementById('sentMetric')?.addEventListener('change', () => {
  sentSourcesState.showAll = false; // Reset pagination on metric change
  loadSentimentSources();
});

document.getElementById('sentBrandFilter')?.addEventListener('change', () => {
  sentSourcesState.showAll = false; // Reset pagination on brand change
  loadSentimentSources();
});

// Polarity change should also reset pagination
document.querySelectorAll('input[name="sentPol"]').forEach(el => {
  el.addEventListener('change', () => {
    sentSourcesState.showAll = false;
    loadSentimentSources();
  });
});

// Event listeners
document.getElementById('sentMetric')?.addEventListener('change', () => {
  sentSourcesState.showAll = false; // Reset pagination on metric change
  loadSentimentSources();
});

document.getElementById('sentBrandFilter')?.addEventListener('change', () => {
  sentSourcesState.showAll = false; // Reset pagination on brand change
  loadSentimentSources();
});

// Polarity change should also reset pagination
document.querySelectorAll('input[name="sentPol"]').forEach(el => {
  el.addEventListener('change', () => {
    sentSourcesState.showAll = false;
    loadSentimentSources();
  });
});

// keyword cloud (super light)
function renderCloud(words) {
  const wrap = document.getElementById('sentCloud');
  if (!words || words.length === 0) {
    wrap.innerHTML = '<div class="mono" style="opacity:.7">No keywords for this slice.</div>';
    return;
  }
  // scale font by rank
  const max = words[0].count || 1;
  wrap.innerHTML = words.map((w,i) => {
    const size = 12 + Math.round(24 * (w.count / max));
    return `<span style="font-size:${size}px;line-height:1.2">${w.term}</span>`;
  }).join(' ');
}
// Click a source row to open Explore scoped to that domain (including "(unsourced)")
document.getElementById('sentSourcesList')?.addEventListener('click', (e) => {
  const row = e.target.closest('.src-row');
  if (!row) return;
  
  const domain = row.dataset.domain || '';
  sentState.page = 1;
  sentState.pageSize = Number(document.getElementById('sentPageSize')?.value || 20);
  sentState.polarity = (document.querySelector('input[name="sentPol"]:checked')?.value === 'negative') ? 'negative' : 'positive';
  
  openSentimentModal(domain); // ✅ Specific domain
});


let sentState = { page:1, pageSize:20, polarity:'positive', total:0, domain:'' };

async function loadSentimentExplore() {
  const { from, to, model, brand, owned_host } = currentPerfFilters();

  const params = {
    action:'sentiment_explore',
    from, to, model, brand,
    polarity: sentState.polarity,
    page: String(sentState.page),
    page_size: String(sentState.pageSize),
    owned_host: getOwnedHost()
    };

    // Only add domain if it exists
    if (sentState.domain && sentState.domain !== '') {
    params.domain = sentState.domain;
    }

    // Only add owned_host if it exists
    if (owned_host && owned_host !== '') {
    params.owned_host = owned_host;
    }

  const q = new URLSearchParams(params);
  const r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  const j = await r.json();
  sentState.total = j.total || 0;

  renderCloud(j.keywords || []);

  const tb = document.querySelector('#sentTable tbody');
tb.innerHTML = (j.rows||[]).map(row => {
  const date = row.date || row.run_at || '';
  return `
    <tr>
      <td>${(row.statement||'').replace(/</g,'&lt;')}</td>
      <td>${row.source || ''}</td>
      <td>${(row.prompt||'').replace(/</g,'&lt;')}</td>
      <td>${(row.topic||'').replace(/</g,'&lt;')}</td>
      <td>${date}</td>
    </tr>
  `;
}).join('');

  const pages = Math.max(1, Math.ceil(sentState.total / sentState.pageSize));
  document.getElementById('sentPagerInfo').textContent = `Page ${sentState.page} / ${pages} • ${sentState.total} rows`;
  document.getElementById('sentPrev').disabled = (sentState.page <= 1);
  document.getElementById('sentNext').disabled = (sentState.page >= pages);
}

async function loadBrandsIntoSentimentFilter() {
  const r = await fetch(`${API_BASE}/brands`);
  const j = await r.json();
  const sel = document.getElementById('sentBrandFilter');
  
  if (!sel) return;
  
  sel.innerHTML = '<option value="">All Brands</option>';
  (j.brands || []).forEach(b => {
    const opt = document.createElement('option');
    opt.value = b.id;
    opt.textContent = `${b.name} (${b.id})`;
    sel.appendChild(opt);
  });
}

// open/close modal
document.getElementById('sentExploreBtn')?.addEventListener('click', () => {
  sentState.page = 1;
  sentState.pageSize = Number(document.getElementById('sentPageSize')?.value || 20);
  sentState.polarity = (document.querySelector('input[name="sentPol"]:checked')?.value === 'negative') ? 'negative' : 'positive';
  
  openSentimentModal(''); // ✅ All sources
});;

document.getElementById('sentModalClose')?.addEventListener('click', () => {
  document.getElementById('sentModal').classList.add('hidden');
});

// modal controls
document.getElementById('sentPolPos')?.addEventListener('click', () => {
  sentState.polarity = 'positive'; sentState.page = 1; loadSentimentExplore();
});
document.getElementById('sentPolNeg')?.addEventListener('click', () => {
  sentState.polarity = 'negative'; sentState.page = 1; loadSentimentExplore();
});
document.getElementById('sentPrev')?.addEventListener('click', () => {
  if (sentState.page > 1) { sentState.page--; loadSentimentExplore(); }
});
document.getElementById('sentNext')?.addEventListener('click', () => {
  const pages = Math.max(1, Math.ceil(sentState.total / sentState.pageSize));
  if (sentState.page < pages) { sentState.page++; loadSentimentExplore(); }
});
document.getElementById('sentPageSize')?.addEventListener('change', (e) => {
  sentState.pageSize = Number(e.target.value||20);
  sentState.page = 1; loadSentimentExplore();
});

// tab/polarity changes for the card
document.querySelectorAll('input[name="sentPol"]').forEach(el=>{
  el.addEventListener('change', loadSentimentSources);
});

/*---------- Market Share and trends ---------*/
/* ===== Market Share (state) ===== */
const msState = {
  by: 'mentions',           // 'mentions' | 'citations'
  donut: [],                // [{brand, count, pct}]
  trendRows: [],            // raw rows from API [{week_start, brand, count, pct}]
  page: 1, pageSize: 10, total: 0   // modal
};

// soft palette (cycled)
const msColors = [
  { fill:'#93C5FD', stroke:'#3B82F6' },
  { fill:'#A7F3D0', stroke:'#10B981' },
  { fill:'#FDE68A', stroke:'#F59E0B' },
  { fill:'#C4B5FD', stroke:'#8B5CF6' },
  { fill:'#FCA5A5', stroke:'#EF4444' },
  { fill:'#FBCFE8', stroke:'#DB2777' },
  { fill:'#DDD6FE', stroke:'#7C3AED' },
  { fill:'#D1FAE5', stroke:'#059669' },
  { fill:'#E5E7EB', stroke:'#9CA3AF' },
];
/*---------- SVG Donut ----------*/
function svgDonut(parts){
  // parts: [{label, value (0..1), abs (count)}], already sorted desc
  const W = 280, H = 280, cx = W/2, cy = H/2, R = 100, r = 58;
  const toXY = (a, rad) => [cx + rad*Math.cos(a), cy + rad*Math.sin(a)];
  let a0 = -Math.PI/2, arcs = '';

  parts.forEach((p, i) => {
    const frac = Math.max(0, Math.min(1, p.value||0));
    const a1 = a0 + frac * Math.PI * 2;
    const [x0,y0] = toXY(a0, R), [x1,y1] = toXY(a1, R);
    const [ix0,iy0] = toXY(a0, r), [ix1,iy1] = toXY(a1, r);
    const large = (a1 - a0) > Math.PI ? 1 : 0;
    const c = msColors[i % msColors.length];

    arcs += `
      <path d="
        M ${x0} ${y0}
        A ${R} ${R} 0 ${large} 1 ${x1} ${y1}
        L ${ix1} ${iy1}
        A ${r} ${r} 0 ${large} 0 ${ix0} ${iy0}
        Z"
        fill="${c.fill}" stroke="${c.stroke}" stroke-width="0.8">
        <title>${p.label}: ${(p.value*100).toFixed(1)}% (${p.abs})</title>
      </path>`;
    a0 = a1;
  });

  return `<svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}">
    <circle cx="${cx}" cy="${cy}" r="${R}" fill="transparent" />
    ${arcs}
  </svg>`;
}
/*---------- SVG Multi-Line (Market Share Trend) ----------*/
function svgLines(seriesByBrand, weeks){
  // seriesByBrand: { brand -> [{x:index, y:pct(0..1)}...] }, weeks = ['YYYY-MM-DD', ...]
  const m = { top:16, right:8, bottom:36, left:48 };
  const W = Math.max(600, 52*weeks.length) + m.left + m.right;
  const H = 280, chartW = W - m.left - m.right, chartH = H - m.top - m.bottom;

  const x = (i) => m.left + (weeks.length<=1 ? chartW/2 : (i * (chartW/(weeks.length-1))));
  const y = (p) => m.top + chartH - (p * chartH); // p is 0..1

  // grid + y ticks 0,25,50,75,100
  const ticks = [0,0.25,0.5,0.75,1];
  let grid = '';
  ticks.forEach(t=>{
    const yy = y(t);
    grid += `<line x1="${m.left}" y1="${yy}" x2="${m.left+chartW}" y2="${yy}" stroke="#eee"/>
             <text x="${m.left-6}" y="${yy+4}" font-size="11" text-anchor="end" fill="#777">${Math.round(t*100)}%</text>`;
  });

  // lines + points
  let paths = ''; let idx=0;
  Object.entries(seriesByBrand).forEach(([brand, pts])=>{
    const c = msColors[idx++ % msColors.length];
    let d = '';
    pts.forEach((p, i)=>{
      const xx = x(p.x), yy = y(Math.max(0, Math.min(1, p.y || 0)));
      d += (i===0 ? `M ${xx} ${yy}` : ` L ${xx} ${yy}`);
    });
    paths += `<path d="${d}" fill="none" stroke="${c.stroke}" stroke-width="1.6">
                <title>${brand}</title>
              </path>`;
    pts.forEach(p=>{
      const xx = x(p.x), yy = y(Math.max(0, Math.min(1, p.y||0)));
      paths += `<circle cx="${xx}" cy="${yy}" r="2.2" fill="${c.fill}" stroke="${c.stroke}" stroke-width="0.8">
                  <title>${brand} • ${(p.y*100).toFixed(1)}%</title>
                </circle>`;
    });
  });

  // x labels
  let xlab = '';
  weeks.forEach((w,i)=>{
    xlab += `<text x="${x(i)}" y="${m.top+chartH+16}" text-anchor="middle" font-size="11" fill="#666">${w.slice(5)}</text>`;
  });

  return `<svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}">
    ${grid}
    ${paths}
    ${xlab}
  </svg>`;
}
/*---------- Loaders (donut + trend) and legend ----------*/
function msCurrentFilters(){
  const picked = document.getElementById('mentionsBrand')?.value || '';
  const brand = picked || PRIMARY_BRAND_ID || '';
  return {
    from:  document.getElementById('perfFrom')?.value || '',
    to:    document.getElementById('perfTo')?.value   || '',
    model: document.getElementById('perfModel')?.value || 'all',
    brand
  };
}


async function loadMarketShareUI(){
  const cont = document.getElementById('marketDonut');
  const legend = document.getElementById('marketLegend');
  if (!cont) return;

  const { from, to, model } = msCurrentFilters();
  const bySel = document.getElementById('msBy');
  if (bySel) msState.by = bySel.value || 'mentions';

  cont.innerHTML = '<div class="mono" style="opacity:.7">Loading…</div>';
  if (legend) legend.textContent = '';

  // primary attempt (metric=…)
  let q = new URLSearchParams({ action:'market_share', metric: msState.by, from, to, model });
  
  let r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  // if backend uses 'by=' instead of 'metric=', try once more
  if (!r.ok && r.status === 400) {
    q = new URLSearchParams({ action:'market_share', by: msState.by, from, to, model });
    r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  }

  let j = {};
  try { j = await r.json(); } catch { /*noop*/ }

  if (!r.ok || !j || !Array.isArray(j.rows)) {
    const msg = (await r.text().catch(()=>'')) || 'Bad Request';
    cont.innerHTML = `<div class="mono" style="opacity:.7">Market Share: ${msg}</div>`;
    if (legend) legend.textContent = '';
    return;
  }

  const rows = j.rows || [];
  if (!rows.length){
    cont.innerHTML = '<div class="mono" style="opacity:.7">No data in this range.</div>';
    return;
  }

  const denom = (j.total && j.total>0) ? j.total : rows.reduce((s,r)=>s+(r.count||0),0) || 1;
  const parts = rows
    .sort((a,b)=> (b.count||0) - (a.count||0))
    .map((r,i)=>({
      label: r.brand || r.brand_id || `Brand ${i+1}`,
      value: Number.isFinite(+r.pct) ? (+r.pct)/100 : (r.count||0)/denom,
      abs:   r.count || Math.round(denom*(+r.pct||0)/100)
    }));

  msState.donut = parts;

  cont.innerHTML = svgDonut(parts);
  if (legend) {
    legend.innerHTML = parts.map((p,i)=>{
      const c = msColors[i % msColors.length];
      const pct = (p.value*100).toFixed(1)+'%';
      return `
        <span style="display:inline-flex;align-items:center;gap:6px;margin-right:10px;">
          <span style="width:10px;height:10px;background:${c.fill};border:1px solid ${c.stroke};display:inline-block"></span>
          ${p.label} — ${pct} (${p.abs})
        </span>`;
    }).join('');
  }
}

async function loadMarketShareTrendUI(){
  const host = document.getElementById('marketTrend');
  const meta = document.getElementById('marketTrendMeta');
  if (!host) return;

  const { from, to, model } = msCurrentFilters();
  host.innerHTML = '<div class="mono" style="opacity:.7">Loading…</div>';
  if (meta) meta.textContent = '';

  // primary attempt (metric=…)
  let q = new URLSearchParams({ action:'market_share_trend', metric: msState.by, from, to, model });
  
  q.set('group_by', getGroupBy());
  let r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  if (!r.ok && r.status === 400) {
    q = new URLSearchParams({ action:'market_share_trend', by: msState.by, from, to, model });
    if (brand) q.set('brand', brand);
    q.set('group_by', getGroupBy());
    r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  }

  let j = {};
  try { j = await r.json(); } catch { /*noop*/ }

  if (!r.ok || !j || !Array.isArray(j.rows)) {
    const msg = (await r.text().catch(()=>'')) || 'Bad Request';
    host.innerHTML = `<div class="mono" style="opacity:.7">Market Share Trend: ${msg}</div>`;
    return;
  }

  const raw = j.rows || [];
  if (!raw.length){
    host.innerHTML = '<div class="mono" style="opacity:.7">No data in this range.</div>';
    return;
  }

  const weeks = Array.from(new Set(raw.map(d=>d.week_start))).sort();
  const brands = Array.from(new Set(raw.map(d=> d.brand || d.brand_id))).sort();

  const byWeek = new Map();
  raw.forEach(d=>{
    const wk = d.week_start;
    if (!byWeek.has(wk)) byWeek.set(wk, []);
    byWeek.get(wk).push(d);
  });

  const series = {};
  brands.forEach(b => { series[b] = []; });

  weeks.forEach((wk, wi)=>{
    const rows = byWeek.get(wk) || [];
    const total = rows.reduce((s,r)=> s + (r.count||0), 0);
    brands.forEach(b=>{
      const rec = rows.find(r => (r.brand||r.brand_id)===b) || {};
      const pct = Number.isFinite(+rec.pct) ? (+rec.pct)/100
                 : (total>0 ? (rec.count||0)/total : 0);
      series[b].push({ x: wi, y: Math.max(0, Math.min(1, pct||0)) });
    });
  });

  host.innerHTML = svgLines(series, weeks);
  if (meta) meta.textContent = `(${from||'—'} → ${to||'—'}) • ${brands.length} brands`;
}

/*--------- Explore modal (open/close + table loader) ---------*/
function openMarketModal(){ document.getElementById('marketModal')?.classList.remove('hidden'); }
function closeMarketModal(){ document.getElementById('marketModal')?.classList.add('hidden'); }

async function loadMarketModalTable(){
  const tbody = document.querySelector('#marketTable tbody');
  const info  = document.getElementById('marketPagerInfo');
  if (!tbody) return;

  const { from, to, model } = msCurrentFilters();
  if (info) info.textContent = 'Loading…';

  // Reset headers for mentions
  const thead = document.querySelector('#marketTable thead tr');
  if (thead) {
    thead.innerHTML = `
      <th>Brand</th>
      <th>Market Share</th>
      <th>Change</th>
      <th>Prompts with Mentions</th>
      <th>Change</th>
      <th>Mentioned Topics</th>
    `;
  }
  // primary attempt (metric=…)
  let q = new URLSearchParams({
    action:'market_share_table',
    metric: msState.by,
    from, to, model,
    page: String(msState.page),
    page_size: String(msState.pageSize)
  });
  
  let r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  if (!r.ok && r.status === 400) {
    q = new URLSearchParams({
      action:'market_share_table',
      by: msState.by,
      from, to, model,
      page: String(msState.page),
      page_size: String(msState.pageSize)
    });
    if (brand) q.set('brand', brand);
    r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache:'no-store' });
  }

  let j = {};
  try { j = await r.json(); } catch { /*noop*/ }

  if (!r.ok || !j || !Array.isArray(j.rows)) {
    const msg = (await r.text().catch(()=>'')) || 'Bad Request';
    tbody.innerHTML = `<tr><td colspan="6" class="mono" style="opacity:.7">${msg}</td></tr>`;
    msState.total = 0;
    if (info) info.textContent = 'Page 1 / 1 • 0 rows';
    document.getElementById('marketPrev')?.setAttribute('disabled','disabled');
    document.getElementById('marketNext')?.setAttribute('disabled','disabled');
    return;
  }

  const rows = j.rows || [];
  msState.total = Number(j.total_brands||rows.length||0);

  tbody.innerHTML = rows.map((row,i)=>{
    const brandName = row.brand || row.brand_id || `Brand ${i+1}`;
    const share = Number.isFinite(+row.pct) ? (+row.pct).toFixed(2)+'%' :
                  (row.share!=null ? (100*row.share).toFixed(2)+'%' : '—');
    const d1 = row.change_share!=null ? ((row.change_share>=0?'+':'') + (100*row.change_share).toFixed(1)+'%') :
              (row.delta_pct!=null ? ((row.delta_pct>=0?'+':'') + row.delta_pct.toFixed(1)+'%') : '—');
    const prompts = row.prompts_with_mentions ?? row.prompts ?? row.count ?? 0;
    const d2 = row.change_prompts!=null ? ((row.change_prompts>=0?'+':'') + row.change_prompts) : '—';
    const topics = Array.isArray(row.topics) ? row.topics.join(', ') : (row.topics || '—');

    return `<tr>
      <td>${brandName}</td>
      <td>${share}</td>
      <td>${d1}</td>
      <td>${prompts}</td>
      <td>${d2}</td>
      <td>${topics}</td>
    </tr>`;
  }).join('');

  const pages = Math.max(1, Math.ceil(msState.total / msState.pageSize));
  if (info) info.textContent = `Page ${msState.page} / ${pages} • ${msState.total} rows`;
  const prevBtn = document.getElementById('marketPrev');
  const nextBtn = document.getElementById('marketNext');
  if (prevBtn) prevBtn.disabled = (msState.page<=1);
  if (nextBtn) nextBtn.disabled = (msState.page>=pages);
}

async function loadMarketModalTableCitations() {
  const tbody = document.querySelector('#marketTable tbody');
  const info = document.getElementById('marketPagerInfo');
  if (!tbody) return;

  const { from, to, model } = msCurrentFilters();
  if (info) info.textContent = 'Loading…';

  const q = new URLSearchParams({
    action: 'market_share_table_citations',
    from, to, model
  });

  const r = await fetch(`${API_BASE}/performance?` + q.toString(), { cache: 'no-store' });
  const j = await r.json().catch(() => ({ rows: [] }));

  if (!r.ok || !j || !Array.isArray(j.rows)) {
    tbody.innerHTML = `<tr><td colspan="6" class="mono" style="opacity:.7">No data</td></tr>`;
    msState.total = 0;
    if (info) info.textContent = 'Page 1 / 1 • 0 rows';
    return;
  }

  // Update table headers for citations
  const thead = document.querySelector('#marketTable thead tr');
  if (thead) {
    thead.innerHTML = `
      <th>Domain</th>
      <th>Market Share</th>
      <th>Change</th>
      <th>Responses with Citation</th>
      <th>Change</th>
      <th>Cited Topics</th>
    `;
  }

  const allRows = j.rows || [];
  msState.total = allRows.length;

  // Client-side pagination
  const start = (msState.page - 1) * msState.pageSize;
  const end = start + msState.pageSize;
  const rows = allRows.slice(start, end);

  tbody.innerHTML = rows.map((row, i) => {
    const domain = row.brand_name || row.brand_id || 'Unknown';
    const share = Number.isFinite(+row.pct) ? (+row.pct).toFixed(2) + '%' : '—';
    const count = row.citations || row.count || 0;
    const changeShare = row.change_share != null ? ((row.change_share >= 0 ? '+' : '') + row.change_share.toFixed(1) + '%') : '—';
    const changeCnt = row.change_citations != null ? ((row.change_citations >= 0 ? '+' : '') + row.change_citations) : '—';
    const topics = row.mentioned_topics || '—';

    return `<tr>
      <td>${domain}</td>
      <td>${share}</td>
      <td>${changeShare}</td>
      <td>${count}</td>
      <td>${changeCnt}</td>
      <td>${topics}</td>
    </tr>`;
  }).join('');

  const pages = Math.max(1, Math.ceil(msState.total / msState.pageSize));
  if (info) info.textContent = `Page ${msState.page} / ${pages} • ${msState.total} rows`;
  
  const prevBtn = document.getElementById('marketPrev');
  const nextBtn = document.getElementById('marketNext');
  if (prevBtn) prevBtn.disabled = (msState.page <= 1);
  if (nextBtn) nextBtn.disabled = (msState.page >= pages);
}

/* ===== Market Share — modal donut ===== */

function svgDonutFromRows(rows) {
  const W = 560, H = 240; // Fixed height
  const cx = 125, cy = H / 2, R = 92, r = 54;

  const colors = [
    '#93C5FD', '#A7F3D0', '#FDE68A', '#C4B5FD', '#FCA5A5',
    '#FBCFE8', '#DDD6FE', '#D1FAE5', '#E5E7EB', '#FED7AA',
    '#BFDBFE', '#A7F3D0'
  ];

  // coerce numbers
  const clean = rows.map(x => ({
    brand_id:   x.brand_id,
    brand_name: x.brand_name,
    count:      Number(x.count || 0),
    pct:        Number(x.pct ?? 0)
  }));

  const total = clean.reduce((s, x) => s + x.count, 0) || 1;

  let a0 = -Math.PI / 2;
  let paths = '';

  clean.forEach((row, i) => {
    const v = row.count;
    if (!v) return;

    const a1 = a0 + (2 * Math.PI) * (v / total);
    const large = (a1 - a0) > Math.PI ? 1 : 0;

    const x0 = cx + R * Math.cos(a0), y0 = cy + R * Math.sin(a0);
    const x1 = cx + R * Math.cos(a1), y1 = cy + R * Math.sin(a1);
    const x2 = cx + r * Math.cos(a1), y2 = cy + r * Math.sin(a1);
    const x3 = cx + r * Math.cos(a0), y3 = cy + r * Math.sin(a0);

    const fill = colors[i % colors.length];

    paths += `
      <path d="
        M ${x0} ${y0}
        A ${R} ${R} 0 ${large} 1 ${x1} ${y1}
        L ${x2} ${y2}
        A ${r} ${r} 0 ${large} 0 ${x3} ${y3}
        Z"
        fill="${fill}" stroke="#fff" stroke-width="1">
        <title>${row.brand_name || row.brand_id} — ${v} (${row.pct.toFixed(2)}%)</title>
      </path>`;
    a0 = a1;
  });

  // Show top 12 in legend
  const topCount = 10;
  const legendRows = clean.slice(0, topCount);
  const remainingCount = clean.length - topCount;

  const legend = legendRows.map((row, i) => `
    <g transform="translate(260, ${18 + i * 20})">
      <rect x="0" y="-10" width="12" height="12" fill="${colors[i % colors.length]}" stroke="#cbd5e1"/>
      <text x="18" y="0" font-size="12" fill="#374151">
        ${(row.brand_name || row.brand_id)} (${row.pct.toFixed(2)}%)
      </text>
    </g>
  `).join('');

  const moreText = remainingCount > 0 ? `
    <g transform="translate(260, ${18 + topCount * 20})">
      <text x="0" y="0" font-size="11" fill="#6b7280" font-style="italic">
        ...and ${remainingCount} more (see table below)
      </text>
    </g>
  ` : '';

  return `
    <svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}">
      ${paths}
      ${legend}
      ${moreText}
    </svg>`;
}

async function loadMarketModalDonut() {
  const host = document.getElementById('marketModalDonut');
  if (!host) return;

  // Read from modal dropdown first, fall back to card, then state
  const modalVal = document.getElementById('msByModal')?.value;
  const cardVal  = document.getElementById('msBy')?.value;
  const by = modalVal || cardVal || msState.by || 'mentions';
  
  // Update state to match
  msState.by = by;
  
  const metric = (by === 'citations') ? 'citations' : 'mentions';

  const { from, to, model } = currentPerfFilters();
  
  host.innerHTML = '<div class="mono" style="opacity:.7">Loading…</div>';

  const q = new URLSearchParams({ action: 'market_share', metric, from, to, model });
  
  const res = await fetch(`${API_BASE}/performance?` + q.toString(), { cache: 'no-store' });
  const data = await res.json().catch(() => ({ rows: [] }));
  const rows = Array.isArray(data.rows) ? data.rows : [];

  if (!rows.length) {
    host.innerHTML = '<div class="mono" style="opacity:.7">No data in this range.</div>';
    return;
  }

  host.innerHTML = svgDonutFromRows(rows);
}

/*---------- Wire the events (STRICTLY scoped to the new IDs) ----------*/
// card controls
document.getElementById('msBy')?.addEventListener('change', ()=>{
  msState.by = document.getElementById('msBy').value || 'mentions';
  loadMarketShareUI();
  loadMarketShareTrendUI();
});

// explore modal open/close
document.getElementById('marketExploreBtn')?.addEventListener('click', ()=>{
  const sel = document.getElementById('msByModal');
  if (sel) sel.value = msState.by;
  msState.page = 1;
  openMarketModal();
  loadMarketModalDonut();
  
  // Load appropriate table based on metric
  if (msState.by === 'citations') {
    loadMarketModalTableCitations();
  } else {
    loadMarketModalTable();
  }
});
document.getElementById('marketModalClose')?.addEventListener('click', closeMarketModal);

// modal controls
document.getElementById('msByModal')?.addEventListener('change', (e)=>{
  msState.by = e.target.value || 'mentions';
  // also keep the card dropdown in sync if present
  const cardSel = document.getElementById('msBy'); if (cardSel) cardSel.value = msState.by;
  // refresh everything for consistency
  loadMarketShareUI();
  loadMarketShareTrendUI();
  msState.page = 1;
  loadMarketModalDonut();
  // Load appropriate table
  if (msState.by === 'citations') {
    loadMarketModalTableCitations();
  } else {
    loadMarketModalTable();
  }
});

document.getElementById('marketPrev')?.addEventListener('click', ()=>{
  if (msState.page>1){ 
    msState.page--; 
    if (msState.by === 'citations') {
      loadMarketModalTableCitations();
    } else {
      loadMarketModalTable();
    }
  }
});

document.getElementById('marketNext')?.addEventListener('click', ()=>{
  const pages = Math.max(1, Math.ceil(msState.total / msState.pageSize));
  if (msState.page < pages){ 
    msState.page++; 
    if (msState.by === 'citations') {
      loadMarketModalTableCitations();
    } else {
      loadMarketModalTable();
    }
  }
});

document.getElementById('marketPageSize')?.addEventListener('change', (e)=>{
  msState.pageSize = Number(e.target.value||20);
  msState.page = 1;
  if (msState.by === 'citations') {
    loadMarketModalTableCitations();
  } else {
    loadMarketModalTable();
  }
});

function loadPerfSourceMix(){
  loadMarketShareUI();
  loadMarketShareTrendUI();
}

// legend & controls
['perfReload','perfModel','perfFrom','perfTo','perfGroupBy'].forEach(id=>{
  document.getElementById(id)?.addEventListener('change', () => { loadPerformanceMentions(); loadPerfCitations(); loadPerfIntent();loadPerfSentiment();loadPerfSourceMix();loadPerfPersona();loadSentimentSources();});
  document.getElementById(id)?.addEventListener('click',  e=>{ if(id==='perfReload') {loadPerformanceMentions(); loadPerfCitations(); loadPerfIntent();loadPerfSentiment();loadPerfSourceMix();loadPerfPersona();loadSentimentSources();}});
});

document.getElementById('perfShowMentioned')?.addEventListener('change', (e)=>{ perfState.showMentioned = e.target.checked; document.getElementById('perfMentionsChart').innerHTML = svgStackedColumns(perfState.rows); });
document.getElementById('perfShowNot')?.addEventListener('change', (e)=>{ perfState.showNot = e.target.checked; document.getElementById('perfMentionsChart').innerHTML = svgStackedColumns(perfState.rows); });
document.getElementById('perfShowNone')?.addEventListener('change', (e)=>{ perfState.showNone = e.target.checked; document.getElementById('perfMentionsChart').innerHTML = svgStackedColumns(perfState.rows); });
// citations legend
document.getElementById('perfCited')?.addEventListener('change', loadPerfCitations);
document.getElementById('perfNotCited')?.addEventListener('change', loadPerfCitations);
// Intent legend events
document.getElementById('perfIntentInfo') ?.addEventListener('change', e=>{ perfIntentState.info  = e.target.checked; repaintIntentFromCache(); });
document.getElementById('perfIntentNav')  ?.addEventListener('change', e=>{ perfIntentState.nav   = e.target.checked; repaintIntentFromCache(); });
document.getElementById('perfIntentTran') ?.addEventListener('change', e=>{ perfIntentState.tran  = e.target.checked; repaintIntentFromCache(); });
document.getElementById('perfIntentOther')?.addEventListener('change', e=>{ perfIntentState.other = e.target.checked; repaintIntentFromCache(); });
//Sentiment Legends
document.getElementById('perfSentPos')?.addEventListener('change', e => { perfSentState.pos = e.target.checked; repaintSentFromCache(); });
document.getElementById('perfSentNeu')?.addEventListener('change', e => { perfSentState.neu = e.target.checked; repaintSentFromCache(); });
document.getElementById('perfSentNeg')?.addEventListener('change', e => { perfSentState.neg = e.target.checked; repaintSentFromCache(); });

// Auto-load Performance when tab is opened
document.addEventListener('DOMContentLoaded', () => {
  const perfTab = document.getElementById('tabPerformance');
  if (perfTab) {
    perfTab.addEventListener('click', () => {
      // Small delay to ensure tab is visible
      setTimeout(() => {
        if (!perfState.rows || perfState.rows.length === 0) {
          // Only load if not already loaded
          loadPerformanceMentions();
          loadPerfCitations();
          loadPerfIntent();
          loadPerfSentiment();
          loadPerfPersona();
          loadSentimentSources();
          loadPerfSourceMix();
          loadBrandsIntoSentimentFilter();
        }
      }, 100);
    });
  }
});