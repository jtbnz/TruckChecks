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
        this.startAutoUpdate();
        this.displayLastRefreshed();
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

        // Update each truck's lockers
        data.trucks.forEach(truck => {
            truck.lockers.forEach(locker => {
                // Find the locker cell by its data attributes or ID
                const lockerCells = document.querySelectorAll('.locker-cell');
                lockerCells.forEach(cell => {
                    // Check if this cell corresponds to the locker we're updating
                    const cellText = cell.textContent.trim().replace('!', '').trim();
                    if (cellText === locker.name) {
                        // Get the parent container to identify the truck
                        const truckContainer = cell.closest('.truck-listing');
                        if (truckContainer) {
                            const truckButton = truckContainer.querySelector('.truck-button');
                            if (truckButton && truckButton.textContent.includes(truck.name)) {
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
                                // Escape JSON for safe attribute insertion
                                const escapedMissingItems = this.escapeHtml(JSON.stringify(locker.missing_items));
                                cell.setAttribute('onclick', 
                                    `showLockerInfo('${this.escapeHtml(locker.name)}', '${this.escapeHtml(locker.last_checked)}', '${this.escapeHtml(locker.checked_by)}', ${escapedMissingItems}, '${lockerUrl}')`
                                );
                            }
                        }
                    }
                });
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
