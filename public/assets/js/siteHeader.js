// Account switcher dropdown
    document.getElementById('accountSwitcher')?.addEventListener('click', (e) => {
        e.stopPropagation();
        const dropdown = document.getElementById('accountDropdown');
        const isVisible = dropdown.style.display !== 'none';
        dropdown.style.display = isVisible ? 'none' : 'block';
        document.getElementById('userDropdown').style.display = 'none';
    });

    // User menu dropdown
    document.getElementById('userMenu')?.addEventListener('click', (e) => {
        e.stopPropagation();
        const dropdown = document.getElementById('userDropdown');
        const isVisible = dropdown.style.display !== 'none';
        dropdown.style.display = isVisible ? 'none' : 'block';
        document.getElementById('accountDropdown').style.display = 'none';
    });
    
    // Switch account handler
    document.querySelectorAll('.account-option').forEach(option => {
        option.addEventListener('click', async (e) => {
            e.preventDefault();
            const accountId = e.currentTarget.dataset.accountId;
            
            if (!accountId) return;
            
            // Show loading
            const originalText = e.currentTarget.innerHTML;
            e.currentTarget.innerHTML = '<span style="color: #3b82f6;">⏳ Switching...</span>';
            
            try {
                const response = await fetch('/ai-visibility-company/public/switch-account', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ account_id: parseInt(accountId) }),
                    credentials: 'same-origin'
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                                        
                    // Clear caches
                    if (window.sessionStorage) sessionStorage.clear();
                    if (window.localStorage) localStorage.clear();
                    
                    // Force full reload with cache bust
                    window.location.href = window.location.href.split('?')[0] + '?_reload=' + Date.now();
                } else {
                    throw new Error(data.message || 'Unknown error');
                }
            } catch (error) {
                console.error('Switch error:', error);
                e.currentTarget.innerHTML = originalText;
                alert('Failed to switch: ' + error.message);
            }
        });
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#accountSwitcher') && !e.target.closest('#accountDropdown')) {
            document.getElementById('accountDropdown').style.display = 'none';
        }
        if (!e.target.closest('#userMenu') && !e.target.closest('#userDropdown')) {
            document.getElementById('userDropdown').style.display = 'none';
        }
    });