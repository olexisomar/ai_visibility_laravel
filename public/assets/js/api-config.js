// API Endpoint Mapping for Laravel
window.API_BASE = '/ai-visibility-company/public/api/admin';
window.API_KEY = 'super-long-random-string';

// Get CSRF token
window.getCsrfToken = () => {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
};

// Override native fetch to auto-map old API URLs to new Laravel routes
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
  if (typeof url === 'string') {
    // Handle action-based URLs
    if (url.includes('?action=')) {
      const [path, queryString] = url.split('?');
      const params = new URLSearchParams(queryString);
      const action = params.get('action');
      
      params.delete('action');
      const remainingQuery = params.toString();
      
      const actionRoutes = {
        'list_prompts': '/ai-visibility-company/public/api/admin/prompts',
        'prompts_status': '/ai-visibility-company/public/api/admin/prompts/status',
        'save_prompt': '/ai-visibility-company/public/api/admin/prompts',
        'delete_prompt': '/ai-visibility-company/public/api/admin/prompts',
        'toggle_pause_prompt': '/ai-visibility-company/public/api/admin/prompts',
        'bulk_pause_prompts': '/ai-visibility-company/public/api/admin/prompts/bulk-pause',
        'bulk_resume_prompts': '/ai-visibility-company/public/api/admin/prompts/bulk-resume',
        'bulk_delete_prompts': '/ai-visibility-company/public/api/admin/prompts/bulk-delete',
        'list_brands': '/ai-visibility-company/public/api/admin/brands',
        'save_brand': '/ai-visibility-company/public/api/admin/brands',
        'delete_brand': '/ai-visibility-company/public/api/admin/brands',
        'set_primary_brand': '/ai-visibility-company/public/api/admin/brands/set-primary',
        'list_topics': '/ai-visibility-company/public/api/admin/topics',
        'save_topics': '/ai-visibility-company/public/api/admin/topics',
        'topic_set_active': '/ai-visibility-company/public/api/admin/topics/set-active',
        'topic_touch': '/ai-visibility-company/public/api/admin/topics/touch',
        'list_personas': '/ai-visibility-company/public/api/admin/personas',
        'save_persona': '/ai-visibility-company/public/api/admin/personas',
        'delete_persona': '/ai-visibility-company/public/api/admin/personas/delete',
        'bulk_approve_suggestions': '/ai-visibility-company/public/api/admin/suggestions/bulk-approve',
        'bulk_reject_suggestions': '/ai-visibility-company/public/api/admin/suggestions/bulk-reject',
        'list': '/ai-visibility-company/public/api/admin/mentions',
        'response': '/ai-visibility-company/public/api/admin/mentions',
        'brand_mentions_overtime': '/ai-visibility-company/public/api/admin/performance',
        'citations_overtime': '/ai-visibility-company/public/api/admin/performance',
        'intent_overtime': '/ai-visibility-company/public/api/admin/performance',
        'sentiment_overtime': '/ai-visibility-company/public/api/admin/performance',
        'persona_overtime': '/ai-visibility-company/public/api/admin/performance',
        'sentiment_sources': '/ai-visibility-company/public/api/admin/performance',
        'sentiment_explore': '/ai-visibility-company/public/api/admin/performance',
        'market_share': '/ai-visibility-company/public/api/admin/performance',
        'market_share_trend': '/ai-visibility-company/public/api/admin/performance',
        'market_share_table': '/ai-visibility-company/public/api/admin/performance',
      };
      
      if (action && actionRoutes[action]) {
        if (action.includes('overtime') || action.includes('sentiment') || action.includes('market')) {
          url = actionRoutes[action] + '?action=' + action + (remainingQuery ? '&' + remainingQuery : '');
        } else {
          url = actionRoutes[action] + (remainingQuery ? '?' + remainingQuery : '');
        }
      }
    }
    
    // Map old paths (most specific first)
    const pathMappings = [
      ['/ai-visibility-company/api/suggestions.php', '/api/admin/suggestions'],
      ['/ai-visibility-company/api/suggestions_approve.php', '/api/admin/suggestions/approve'],
      ['/ai-visibility-company/api/suggestions_reject.php', '/api/admin/suggestions/reject'],
      ['/ai-visibility-company/api/suggestions_count.php', '/api/admin/suggestions/count'],
      ['/ai-visibility-company/api/admin.php', '/api/admin'],
      ['/ai-visibility-company/api/metrics.php', '/api/admin/metrics'],
      ['/ai-visibility-company/api/mentions.php', '/api/admin/mentions'],
      ['/ai-visibility-company/api/performance.php', '/api/admin/performance'],
      ['/ai-visibility-company/api/run.php', '/api/admin/run/gpt'],
      ['/ai-visibility-company/api/run_gai.php', '/api/admin/run/google-aio'],
      ['/ai-visibility-company/api/prompts.php', '/api/admin/prompts/export'],
      ['/ai-visibility-company/api/brands.php', '/api/admin/brands/export'],
      ['../api/suggestions.php', '/api/admin/suggestions'],
      ['../api/admin.php', '/api/admin'],
      ['../api/metrics.php', '/ai-visibility-company/public/api/admin/metrics'],
      ['../api/mentions.php', '/ai-visibility-company/public/api/admin/mentions'],
      ['../api/performance.php', '/ai-visibility-company/public/api/admin/performance'],
      ['../api/run.php', '/ai-visibility-company/public/api/admin/run/gpt'],
      ['../api/run_gai.php', '/ai-visibility-company/public/api/admin/run/google-aio'],
      ['../api/prompts.php', '/ai-visibility-company/public/api/admin/prompts/export'],
      ['../api/brands.php', '/ai-visibility-company/public/api/admin/brands/export'],
    ];
    
    pathMappings.forEach(([oldPath, newPath]) => {
      url = url.replace(oldPath, newPath);
    });

    // ===== NEW: ADD CACHE BUSTING FOR GET REQUESTS =====
    if (url.includes('/api/admin/')) {
      const method = (options.method || 'GET').toUpperCase();
      if (method === 'GET') {
        const separator = url.includes('?') ? '&' : '?';
        url = `${url}${separator}_t=${Date.now()}`;
      }
    }

    // ===== NEW: ADD CSRF TOKEN FOR MUTATIONS =====
    if (url.startsWith('/') || url.startsWith(window.location.origin)) {
      options.headers = options.headers || {};
      
      const method = (options.method || 'GET').toUpperCase();
      if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
        const token = window.getCsrfToken();
        if (token) {
          options.headers['X-CSRF-TOKEN'] = token;
        }
      }

      // Add API key
      if (!options.headers['X-API-Key'] && window.API_KEY) {
        options.headers['X-API-Key'] = window.API_KEY;
      }

      // Ensure credentials
      options.credentials = options.credentials || 'same-origin';
    }
  }
  
  return originalFetch(url, options);
};
