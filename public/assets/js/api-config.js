// API Endpoint Mapping for Laravel
window.API_BASE = '/ai-visibility-laravel/public/api/admin';

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
        'list_prompts': '/ai-visibility-laravel/public/api/admin/prompts',
        'prompts_status': '/ai-visibility-laravel/public/api/admin/prompts/status',
        'save_prompt': '/ai-visibility-laravel/public/api/admin/prompts',
        'delete_prompt': '/ai-visibility-laravel/public/api/admin/prompts',
        'toggle_pause_prompt': '/ai-visibility-laravel/public/api/admin/prompts',
        'bulk_pause_prompts': '/ai-visibility-laravel/public/api/admin/prompts/bulk-pause',
        'bulk_resume_prompts': '/ai-visibility-laravel/public/api/admin/prompts/bulk-resume',
        'bulk_delete_prompts': '/ai-visibility-laravel/public/api/admin/prompts/bulk-delete',
        'list_brands': '/ai-visibility-laravel/public/api/admin/brands',
        'save_brand': '/ai-visibility-laravel/public/api/admin/brands',
        'delete_brand': '/ai-visibility-laravel/public/api/admin/brands',
        'set_primary_brand': '/ai-visibility-laravel/public/api/admin/brands/set-primary',
        'list_topics': '/ai-visibility-laravel/public/api/admin/topics',
        'save_topics': '/ai-visibility-laravel/public/api/admin/topics',
        'topic_set_active': '/ai-visibility-laravel/public/api/admin/topics/set-active',
        'topic_touch': '/ai-visibility-laravel/public/api/admin/topics/touch',
        'list_personas': '/ai-visibility-laravel/public/api/admin/personas',
        'save_persona': '/ai-visibility-laravel/public/api/admin/personas',
        'delete_persona': '/ai-visibility-laravel/public/api/admin/personas/delete',
        'bulk_approve_suggestions': '/ai-visibility-laravel/public/api/admin/suggestions/bulk-approve',
        'bulk_reject_suggestions': '/ai-visibility-laravel/public/api/admin/suggestions/bulk-reject',
        'list': '/ai-visibility-laravel/public/api/admin/mentions',
        'response': '/ai-visibility-laravel/public/api/admin/mentions',
        'brand_mentions_overtime': '/ai-visibility-laravel/public/api/admin/performance',
        'citations_overtime': '/ai-visibility-laravel/public/api/admin/performance',
        'intent_overtime': '/ai-visibility-laravel/public/api/admin/performance',
        'sentiment_overtime': '/ai-visibility-laravel/public/api/admin/performance',
        'persona_overtime': '/ai-visibility-laravel/public/api/admin/performance',
        'sentiment_sources': '/ai-visibility-laravel/public/api/admin/performance',
        'sentiment_explore': '/ai-visibility-laravel/public/api/admin/performance',
        'market_share': '/ai-visibility-laravel/public/api/admin/performance',
        'market_share_trend': '/ai-visibility-laravel/public/api/admin/performance',
        'market_share_table': '/ai-visibility-laravel/public/api/admin/performance',
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
      ['/ai-visibility-laravel/api/suggestions.php', '/api/admin/suggestions'],
      ['/ai-visibility-laravel/api/suggestions_approve.php', '/api/admin/suggestions/approve'],
      ['/ai-visibility-laravel/api/suggestions_reject.php', '/api/admin/suggestions/reject'],
      ['/ai-visibility-laravel/api/suggestions_count.php', '/api/admin/suggestions/count'],
      ['/ai-visibility-laravel/api/admin.php', '/api/admin'],
      ['/ai-visibility-laravel/api/metrics.php', '/api/admin/metrics'],
      ['/ai-visibility-laravel/api/mentions.php', '/api/admin/mentions'],
      ['/ai-visibility-laravel/api/performance.php', '/api/admin/performance'],
      ['/ai-visibility-laravel/api/run.php', '/api/admin/run/gpt'],
      ['/ai-visibility-laravel/api/run_gai.php', '/api/admin/run/google-aio'],
      ['/ai-visibility-laravel/api/prompts.php', '/api/admin/prompts/export'],
      ['/ai-visibility-laravel/api/brands.php', '/api/admin/brands/export'],
      ['../api/suggestions.php', '/api/admin/suggestions'],
      ['../api/admin.php', '/api/admin'],
      ['../api/metrics.php', '/ai-visibility-laravel/public/api/admin/metrics'],
      ['../api/mentions.php', '/ai-visibility-laravel/public/api/admin/mentions'],
      ['../api/performance.php', '/ai-visibility-laravel/public/api/admin/performance'],
      ['../api/run.php', '/ai-visibility-laravel/public/api/admin/run/gpt'],
      ['../api/run_gai.php', '/ai-visibility-laravel/public/api/admin/run/google-aio'],
      ['../api/prompts.php', '/ai-visibility-laravel/public/api/admin/prompts/export'],
      ['../api/brands.php', '/ai-visibility-laravel/public/api/admin/brands/export'],
    ];

    pathMappings.forEach(([oldPath, newPath]) => {
      url = url.replace(oldPath, newPath);
    });
    
    //console.log('🔄 Mapped:', url);
  }
  return originalFetch(url, options);
};

//console.log('✅ API config loaded ');