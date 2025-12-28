// Service Worker for TruckChecks
const CACHE_NAME = 'truckChecks-v1';
const API_CACHE_NAME = 'truckChecks-api-v1';

// Files to cache on install
const STATIC_ASSETS = [
    '/',
    '/index.php',
    '/check_locker_items.php',
    '/styles/styles.css',
    '/styles/check_locker_items.css',
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
    console.log('Service Worker: Installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('Service Worker: Caching static assets');
                // Use default cache behavior for better installation performance
                return cache.addAll(STATIC_ASSETS)
                    .catch(err => {
                        console.log('Service Worker: Error caching static assets', err);
                        // Don't fail install if some assets can't be cached
                        return Promise.resolve();
                    });
            })
            .then(() => {
                console.log('Service Worker: Installed');
                return self.skipWaiting();
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('Service Worker: Activating...');
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName !== CACHE_NAME && cacheName !== API_CACHE_NAME) {
                            console.log('Service Worker: Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => {
                console.log('Service Worker: Activated');
                return self.clients.claim();
            })
    );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Handle API requests differently
    if (url.pathname.includes('api_status.php') || url.pathname.includes('api_check_data.php')) {
        event.respondWith(
            // Network first, fallback to cache for API requests
            fetch(request)
                .then((response) => {
                    // Clone the response before caching
                    const responseClone = response.clone();
                    caches.open(API_CACHE_NAME).then((cache) => {
                        cache.put(request, responseClone);
                    });
                    return response;
                })
                .catch(() => {
                    // If network fails, try cache
                    return caches.match(request)
                        .then((cachedResponse) => {
                            if (cachedResponse) {
                                return cachedResponse;
                            }
                            // Return a basic error response
                            return new Response(
                                JSON.stringify({ error: 'Network unavailable and no cached data' }),
                                {
                                    status: 503,
                                    statusText: 'Service Unavailable',
                                    headers: { 'Content-Type': 'application/json' }
                                }
                            );
                        });
                })
        );
    } else {
        // Cache first, fallback to network for static assets
        event.respondWith(
            caches.match(request)
                .then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    // If not in cache, fetch from network
                    return fetch(request)
                        .then((response) => {
                            // Don't cache POST requests or non-successful responses
                            if (request.method !== 'GET' || !response || !response.ok) {
                                return response;
                            }
                            // Clone and cache the response
                            const responseClone = response.clone();
                            caches.open(CACHE_NAME).then((cache) => {
                                cache.put(request, responseClone);
                            });
                            return response;
                        });
                })
        );
    }
});

// Handle messages from the client
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
