# Service Worker Implementation

This document describes the service worker implementation for TruckChecks application.

## Overview

Service workers have been implemented to enable:
1. **Dynamic updates** - The status page now updates dynamically without full page reloads
2. **Offline support** - Basic caching of static assets for offline viewing
3. **Background sync** - API data is cached for better performance
4. **Reduced server load** - Only data changes are fetched, not entire pages

## Files Added

### 1. `/sw.js` - Service Worker
The main service worker file that handles:
- Caching of static assets (CSS, JS, HTML)
- Network-first strategy for API endpoints
- Cache-first strategy for static assets
- Automatic cache cleanup on version changes

### 2. `/api_status.php` - Status API Endpoint
RESTful API endpoint that returns JSON data for the status page:
- Returns truck and locker status information
- Includes color coding based on check status
- Provides missing items information
- Returns timestamps for last checks

### 3. `/js/sw-register.js` - Service Worker Registration
Handles registration of the service worker:
- Checks for browser support
- Registers the service worker
- Handles updates and version changes
- Periodic update checks

### 4. `/js/status-updater.js` - Dynamic Status Updater
Client-side JavaScript module that:
- Fetches status updates from API
- Updates the UI dynamically without page reload
- Maintains refresh interval from config
- Updates last refreshed timestamp
- Handles errors gracefully

## Configuration

The refresh interval is controlled by the `REFRESH` constant in `config.php`:
```php
if (!defined('REFRESH')) define('REFRESH', 30000); // 30 seconds
```

## How It Works

### Previous Behavior (Before Service Workers)
- Every 30 seconds, the entire page was reloaded using `window.location.reload()`
- This consumed more bandwidth and resources
- User experience was interrupted during reload

### New Behavior (With Service Workers)
1. Service worker is registered on page load
2. Static assets are cached for offline use
3. Every 30 seconds (configurable), the status updater:
   - Fetches JSON data from `/api_status.php`
   - Updates only the changed locker statuses
   - Updates the "last refreshed" timestamp
   - No page reload or interruption
4. If network is unavailable, cached data is used

## Benefits

1. **Better User Experience**
   - No page flicker or reload interruption
   - Smoother updates
   - Modal dialogs stay open during updates

2. **Reduced Bandwidth**
   - Only JSON data is transferred (typically < 5KB)
   - vs. full page HTML (~50KB+)
   - ~90% reduction in data transfer per update

3. **Offline Support**
   - Page remains viewable when offline
   - Last cached data is displayed
   - Automatic sync when connection restored

4. **Performance**
   - Faster updates (API response only)
   - Static assets served from cache
   - Reduced server load

## Browser Support

Service workers are supported in:
- Chrome/Edge 40+
- Firefox 44+
- Safari 11.1+
- Opera 27+

For unsupported browsers, the application still works but without service worker benefits.

## Testing

To test the service worker:

1. **Check Registration**
   - Open browser DevTools (F12)
   - Go to Application/Storage tab
   - Look for "Service Workers" section
   - Should show sw.js registered

2. **Test Dynamic Updates**
   - Keep status page open
   - Make changes to locker status in database
   - Observe automatic updates without page reload
   - Check console for "StatusUpdater" logs

3. **Test Offline Support**
   - Load the page normally
   - Open DevTools Network tab
   - Select "Offline" mode
   - Refresh page - should still load from cache

4. **Test API Caching**
   - Check Network tab for api_status.php requests
   - Should see responses marked "(from service worker)"

## Future Enhancements

Possible future improvements:
- Add check page dynamic updates
- Implement push notifications for critical alerts
- Add background sync for offline check submissions
- Progressive Web App (PWA) manifest for install capability
- More sophisticated caching strategies

## Troubleshooting

### Service Worker not registering
- Check browser console for errors
- Ensure site is served over HTTPS (or localhost)
- Clear browser cache and reload

### Updates not appearing
- Check console for API errors
- Verify api_status.php is accessible
- Check REFRESH interval in config.php

### Old version stuck
- Unregister service worker in DevTools
- Clear all site data
- Reload page

## Notes

- Service workers only work over HTTPS (or localhost for development)
- Browser support should be checked before relying on features
- Cache must be manually cleared if sw.js changes significantly
