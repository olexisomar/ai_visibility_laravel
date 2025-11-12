
// Toggle export menu
document.getElementById('exportMenuBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  const menu = document.getElementById('exportMenu');
  menu.classList.toggle('hidden');
});

// Close menu when clicking outside
document.addEventListener('click', () => {
  document.getElementById('exportMenu')?.classList.add('hidden');
});

// Prevent menu close when clicking inside
document.getElementById('exportMenu')?.addEventListener('click', (e) => {
  e.stopPropagation();
});

// CSV Export
document.getElementById('exportCSVBtn')?.addEventListener('click', async () => {
  const btn = document.getElementById('exportCSVBtn');
  const menu = document.getElementById('exportMenu');
  
  const params = new URLSearchParams({
    scope: new URLSearchParams(window.location.search).get('scope') || 'latest_per_source',
  });
  
  const brandFilter = document.getElementById('brandFilter')?.value;
  const sentimentFilter = document.querySelector('input[name="sentiment"]:checked')?.value;
  
  if (brandFilter) params.append('brand', brandFilter);
  if (sentimentFilter) params.append('sentiment', sentimentFilter);
  
  const exportUrl = `${API_BASE}/mentions/export?${params.toString()}`;
  
  btn.innerHTML = '<span>⏳</span><div><div style="font-weight: 600;">Downloading...</div><div style="font-size: 12px; color: #6b7280;">Please wait</div></div>';
  
  try {
    const response = await fetch(exportUrl, {
      headers: { 'X-API-Key': API_KEY }
    });
    
    if (!response.ok) throw new Error('Export failed');
    
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `mentions_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
    
    btn.innerHTML = '<span>✅</span><div><div style="font-weight: 600;">Downloaded!</div><div style="font-size: 12px; color: #6b7280;">Check your downloads</div></div>';
    
    setTimeout(() => {
      menu.classList.add('hidden');
      btn.innerHTML = '<span>💾</span><div><div style="font-weight: 600;">Download CSV</div><div style="font-size: 12px; color: #6b7280;">For Excel, analysis</div></div>';
    }, 2000);
    
  } catch (error) {
    console.error('Export error:', error);
    alert('Failed to export CSV');
    btn.innerHTML = '<span>💾</span><div><div style="font-weight: 600;">Download CSV</div><div style="font-size: 12px; color: #6b7280;">For Excel, analysis</div></div>';
  }
});

// Looker Studio Export
document.getElementById('exportSheetsBtn')?.addEventListener('click', async () => {
  const btn = document.getElementById('exportSheetsBtn');
  
  btn.innerHTML = '<span>⏳</span><div><div style="font-weight: 600;">Preparing...</div><div style="font-size: 12px; color: #6b7280;">Opening Looker Studio</div></div>';
  
  try {
    const params = new URLSearchParams({
      scope: new URLSearchParams(window.location.search).get('scope') || 'all',
    });
    
    const brandFilter = document.getElementById('brandFilter')?.value;
    const sentimentFilter = document.querySelector('input[name="sentiment"]:checked')?.value;
    
    if (brandFilter) params.append('brand', brandFilter);
    if (sentimentFilter) params.append('sentiment', sentimentFilter);
    
    // Get the data URL
    const dataUrl = `${window.location.origin}${API_BASE}/mentions/export-sheets?${params.toString()}&api_key=${API_KEY}`;
    
    // Open Looker Studio with CSV connector
    const lookerUrl = `https://lookerstudio.google.com/datasources/create?connectorId=AKfycbxCOKAG7llk8tKlDlZO6W3C8_5HfQcUmEQUXvXvF-cKfkWmkyE`;
    
    // Show instructions modal
    showLookerInstructions(dataUrl);
    
    btn.innerHTML = '<span>📊</span><div><div style="font-weight: 600;">Open in Looker Studio</div><div style="font-size: 12px; color: #6b7280;">Create live dashboard</div></div>';
    
  } catch (error) {
    console.error('Looker export error:', error);
    alert('Failed to prepare Looker Studio export');
    btn.innerHTML = '<span>📊</span><div><div style="font-weight: 600;">Open in Looker Studio</div><div style="font-size: 12px; color: #6b7280;">Create live dashboard</div></div>';
  }
});

// Show Looker Studio instructions
function showLookerInstructions(dataUrl) {
  const modal = document.createElement('div');
  modal.className = 'modal';
  modal.innerHTML = `
    <div class="modal-overlay"></div>
    <div class="modal-dialog" style="max-width: 600px;">
      <div class="modal-content">
        <div class="modal-header">
          <h3 style="margin: 0;">📊 Connect to Looker Studio</h3>
          <button class="modal-close" onclick="this.closest('.modal').remove()">&times;</button>
        </div>
        
        <div class="modal-body" style="padding: 24px;">
        <div>
          <h3>🚀 Quick Start (Recommended)</h3>
            <p>View our pre-built dashboard with all key metrics:</p>
            <ul>
              <li>✅ Branded vs Non-Branded Analysis</li>
              <li>✅ Competitive Intelligence</li>
              <li>✅ Content Strategy Insights</li>
              <li>✅ Missed Opportunities</li>
            </ul>
          <a href="https://lookerstudio.google.com/reporting/5aab2cf2-f0d4-4705-89ea-5061dd84c9ce/page/wm30B" target="_blank" class="btn" style="width: auto; text-align: center; display: block; padding: 12px; background: #4285f4; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
            📈 Open Default Report
          </a>
          <hr><p style="text-align:center;">OR</p><hr>
        </div>
          <h3>🛠️ Build Your Own Report</h3>
          <p>Connect our data to create custom dashboards:</p>
          <p style="margin-bottom: 16px;"><strong>Step 1:</strong> Copy this API URL:</p>
          <div style="background: #f3f4f6; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 12px; word-break: break-all; margin-bottom: 20px;">
            ${dataUrl}
          </div>
          <button onclick="navigator.clipboard.writeText('${dataUrl}')" class="btn pill-ok" style="width: 100%; margin-bottom: 20px;">
            📋 Copy URL
          </button>
          
          <p style="margin-bottom: 16px;"><strong>Step 2:</strong> Click below to open Looker Studio:</p>
          <a href="https://lookerstudio.google.com/datasources/create?connectorId=AKfycbxCOKAG7llk8tKlDlZO6W3C8_5HfQcUmEQUXvXvF-cKfkWmkyE" target="_blank" class="btn" style="width: auto; text-align: center; display: block; padding: 12px; background: #4285f4; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
            🚀 Open Looker Studio
          </a>
          
          <div style="margin-top: 20px; padding: 12px; background: #fef3c7; border-radius: 8px; font-size: 13px;">
            <strong>⚠️ Note:</strong> In Looker Studio, use JSON by Windsor.ai connector, select "URL" as data source and paste the API URL above.
          </div>
          
        </div>
      </div>
    </div>
  `;
  
  document.body.appendChild(modal);
  modal.classList.remove('hidden');
}