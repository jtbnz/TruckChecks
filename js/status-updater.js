// Status page updater using Service Worker and dynamic updates
class StatusUpdater {
    constructor(refreshInterval = 30000) {
        this.refreshInterval = refreshInterval;
        this.updateTimer = null;
        this.isUpdating = false;
    }

    // Initialize the status updater
    init() {
        console.log('StatusUpdater: Initializing...');
        this.buildLockerIndex();
        this.startAutoUpdate();
        this.displayLastRefreshed();
    }

    // Build an index of locker cells for O(1) lookup
    buildLockerIndex() {
        this.lockerIndex = new Map();
        const truckListings = document.querySelectorAll('.truck-listing');
        
        truckListings.forEach(truckListing => {
            const truckButton = truckListing.querySelector('.truck-button');
            const truckName = truckButton ? truckButton.textContent.split(' - ')[0].trim() : null;
            
            if (truckName) {
                const lockerCells = truckListing.querySelectorAll('.locker-cell');
                lockerCells.forEach(cell => {
                    const lockerName = cell.textContent.trim().replace('!', '').trim();
                    const key = `${truckName}:${lockerName}`;
                    this.lockerIndex.set(key, cell);
                });
            }
        });
        
        console.log(`StatusUpdater: Indexed ${this.lockerIndex.size} locker cells`);
    }

    // Start automatic updates
    startAutoUpdate() {
        console.log('StatusUpdater: Starting auto-update with interval:', this.refreshInterval);
        this.updateTimer = setInterval(() => {
            this.updateStatus();
        }, this.refreshInterval);
    }

    // Stop automatic updates
    stopAutoUpdate() {
        if (this.updateTimer) {
            clearInterval(this.updateTimer);
            this.updateTimer = null;
        }
    }

    // Display last refreshed time
    displayLastRefreshed() {
        const now = new Date();
        const formattedTime = now.toLocaleString();
        const element = document.getElementById('last-refreshed');
        if (element) {
            element.textContent = 'Last refreshed at: ' + formattedTime;
        }
    }

    // Update status from API
    async updateStatus() {
        if (this.isUpdating) {
            console.log('StatusUpdater: Update already in progress, skipping...');
            return;
        }

        this.isUpdating = true;
        console.log('StatusUpdater: Fetching status update...');

        try {
            const response = await fetch('api_status.php', {
                cache: 'no-cache',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            console.log('StatusUpdater: Data received, updating UI...');
            this.updateUI(data);
            this.displayLastRefreshed();
        } catch (error) {
            console.error('StatusUpdater: Error updating status:', error);
            // Don't show error to user, just log it
        } finally {
            this.isUpdating = false;
        }
    }

    // Update the UI with new data
    updateUI(data) {
        if (!data || !data.trucks) {
            console.error('StatusUpdater: Invalid data format');
            return;
        }

        // Update each truck's lockers using the index for O(1) lookup
        data.trucks.forEach(truck => {
            truck.lockers.forEach(locker => {
                const key = `${truck.name}:${locker.name}`;
                const cell = this.lockerIndex.get(key);
                
                if (cell) {
                    // Update background color
                    cell.style.backgroundColor = locker.background_color;
                    
                    // Update badge for missing items
                    const existingBadge = cell.querySelector('.badge');
                    if (locker.has_missing_items && !existingBadge) {
                        // Add badge
                        const badge = document.createElement('span');
                        badge.className = 'badge';
                        badge.textContent = '!';
                        cell.appendChild(badge);
                    } else if (!locker.has_missing_items && existingBadge) {
                        // Remove badge
                        existingBadge.remove();
                    }

                    // Update the onclick handler to use updated data
                    const lockerUrl = 'check_locker_items.php?truck_id=' + truck.id + '&locker_id=' + locker.id;
                    // JSON encode missing items - escaping happens at PHP render time in original
                    // For JS, we need to properly escape for HTML attribute context
                    const missingItemsJson = JSON.stringify(locker.missing_items)
                        .replace(/\\/g, '\\\\')
                        .replace(/'/g, "\\'");
                    cell.setAttribute('onclick', 
                        `showLockerInfo('${this.escapeHtml(locker.name)}', '${this.escapeHtml(locker.last_checked)}', '${this.escapeHtml(locker.checked_by)}', ${missingItemsJson}, '${lockerUrl}')`
                    );
                }
            });
        });

        console.log('StatusUpdater: UI updated successfully');
    }

    // Helper function to escape HTML
    escapeHtml(text) {
        // Handle null, undefined, and non-string values
        if (text === null || text === undefined) {
            return '';
        }
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Get refresh interval from PHP config (passed via data attribute)
    const refreshInterval = parseInt(document.body.getAttribute('data-refresh-interval')) || 30000;
    const statusUpdater = new StatusUpdater(refreshInterval);
    statusUpdater.init();
    
    // Expose to window for debugging
    window.statusUpdater = statusUpdater;
});
