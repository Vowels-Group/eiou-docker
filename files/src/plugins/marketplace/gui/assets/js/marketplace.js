/**
 * Claude Code Marketplace - Frontend JavaScript
 *
 * Handles all client-side marketplace functionality including:
 * - Plugin browsing, searching, and filtering
 * - Plugin installation, updates, and removal
 * - Repository management
 * - UI interactions and state management
 */

const Marketplace = {
    // API base URL
    apiBase: '/api/v1/marketplace',

    // Current state
    state: {
        currentTab: 'browse',
        currentPage: 1,
        totalPages: 1,
        filters: {
            category: '',
            sort: 'downloads',
            search: ''
        },
        installedPlugins: [],
        availableUpdates: []
    },

    /**
     * Initialize the marketplace
     */
    init() {
        this.bindEvents();
        this.loadBrowseTab();
        this.checkForUpdates();
    },

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Tab navigation
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.switchTab(e.target.dataset.tab));
        });

        // Search
        const searchInput = document.getElementById('plugin-search');
        const searchBtn = document.getElementById('search-btn');
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.search();
        });
        searchBtn.addEventListener('click', () => this.search());

        // Filters
        document.getElementById('category-filter').addEventListener('change', (e) => {
            this.state.filters.category = e.target.value;
            this.loadPlugins();
        });
        document.getElementById('sort-filter').addEventListener('change', (e) => {
            this.state.filters.sort = e.target.value;
            this.loadPlugins();
        });

        // Pagination
        document.getElementById('prev-page').addEventListener('click', () => this.changePage(-1));
        document.getElementById('next-page').addEventListener('click', () => this.changePage(1));

        // Updates
        document.getElementById('check-updates-btn').addEventListener('click', () => this.checkForUpdates());
        document.getElementById('update-all-btn').addEventListener('click', () => this.updateAll());

        // Repositories
        document.getElementById('add-repo-btn').addEventListener('click', () => this.showAddRepoModal());
        document.getElementById('sync-all-btn').addEventListener('click', () => this.syncAllRepos());
        document.getElementById('add-repo-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.addRepository();
        });

        // Modal close buttons
        document.querySelectorAll('.modal-close, .modal-cancel').forEach(btn => {
            btn.addEventListener('click', () => this.closeModals());
        });

        // Close modal on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) this.closeModals();
            });
        });
    },

    /**
     * Switch between tabs
     */
    switchTab(tab) {
        this.state.currentTab = tab;

        // Update tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });

        // Update tab panels
        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === `${tab}-tab`);
            panel.classList.toggle('hidden', panel.id !== `${tab}-tab`);
        });

        // Load tab content
        switch (tab) {
            case 'browse':
                this.loadBrowseTab();
                break;
            case 'installed':
                this.loadInstalledTab();
                break;
            case 'updates':
                this.loadUpdatesTab();
                break;
            case 'repositories':
                this.loadRepositoriesTab();
                break;
        }
    },

    // ==================== Browse Tab ====================

    /**
     * Load browse tab content
     */
    async loadBrowseTab() {
        await this.loadPlugins();
    },

    /**
     * Load plugins list
     */
    async loadPlugins() {
        this.showLoading('Loading plugins...');

        try {
            const params = new URLSearchParams({
                page: this.state.currentPage,
                per_page: 20,
                sort: this.state.filters.sort
            });

            if (this.state.filters.category) {
                params.append('category', this.state.filters.category);
            }
            if (this.state.filters.search) {
                params.append('search', this.state.filters.search);
            }

            const response = await this.api(`/plugins?${params}`);

            if (response.success) {
                this.renderPluginGrid(response.data.plugins);
                this.updatePagination(response.data.pagination);
            }
        } catch (error) {
            this.showError('Failed to load plugins');
        }

        this.hideLoading();
    },

    /**
     * Render plugin grid
     */
    renderPluginGrid(plugins) {
        const grid = document.getElementById('plugin-grid');
        const template = document.getElementById('plugin-card-template');

        grid.innerHTML = '';

        if (plugins.length === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <h3>No plugins found</h3>
                    <p>Try adjusting your search or filters</p>
                </div>
            `;
            return;
        }

        plugins.forEach(plugin => {
            const card = template.content.cloneNode(true);
            const cardElement = card.querySelector('.plugin-card');

            cardElement.dataset.pluginId = plugin.id;
            card.querySelector('.plugin-name').textContent = plugin.name;
            card.querySelector('.plugin-version').textContent = `v${plugin.version}`;
            card.querySelector('.plugin-description').textContent = plugin.description || 'No description available';
            card.querySelector('.plugin-author').textContent = plugin.author || 'Unknown author';
            card.querySelector('.plugin-downloads').textContent = `${this.formatNumber(plugin.downloads)} downloads`;
            card.querySelector('.plugin-rating').textContent = this.formatRating(plugin.rating);

            // Tags
            const tagsContainer = card.querySelector('.plugin-tags');
            (plugin.tags || []).slice(0, 3).forEach(tag => {
                const tagEl = document.createElement('span');
                tagEl.className = 'plugin-tag';
                tagEl.textContent = tag;
                tagsContainer.appendChild(tagEl);
            });

            // Install button
            const installBtn = card.querySelector('.install-btn');
            if (plugin.installed) {
                installBtn.textContent = 'Installed';
                installBtn.disabled = true;
                installBtn.classList.add('btn-secondary');
                installBtn.classList.remove('btn-primary');
            } else {
                installBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.installPlugin(plugin.id);
                });
            }

            // Details button
            card.querySelector('.details-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                this.showPluginDetails(plugin.id);
            });

            // Card click
            cardElement.addEventListener('click', () => this.showPluginDetails(plugin.id));

            grid.appendChild(card);
        });
    },

    /**
     * Search for plugins
     */
    search() {
        this.state.filters.search = document.getElementById('plugin-search').value;
        this.state.currentPage = 1;
        this.loadPlugins();
    },

    /**
     * Change page
     */
    changePage(delta) {
        const newPage = this.state.currentPage + delta;
        if (newPage >= 1 && newPage <= this.state.totalPages) {
            this.state.currentPage = newPage;
            this.loadPlugins();
        }
    },

    /**
     * Update pagination UI
     */
    updatePagination(pagination) {
        this.state.currentPage = pagination.page;
        this.state.totalPages = pagination.total_pages;

        document.getElementById('page-info').textContent =
            `Page ${pagination.page} of ${pagination.total_pages}`;
        document.getElementById('prev-page').disabled = pagination.page <= 1;
        document.getElementById('next-page').disabled = pagination.page >= pagination.total_pages;
    },

    // ==================== Plugin Details ====================

    /**
     * Show plugin details modal
     */
    async showPluginDetails(pluginId) {
        this.showLoading('Loading plugin details...');

        try {
            const response = await this.api(`/plugins/${encodeURIComponent(pluginId)}`);

            if (response.success) {
                this.renderPluginDetails(response.data.plugin);
                document.getElementById('plugin-modal').classList.remove('hidden');
            }
        } catch (error) {
            this.showError('Failed to load plugin details');
        }

        this.hideLoading();
    },

    /**
     * Render plugin details
     */
    renderPluginDetails(plugin) {
        const container = document.getElementById('plugin-detail');

        container.innerHTML = `
            <div class="plugin-detail-header">
                <h2>${plugin.name}</h2>
                <span class="plugin-version">v${plugin.version}</span>
            </div>
            <p class="plugin-description">${plugin.description || 'No description available'}</p>

            <div class="plugin-detail-meta">
                <div class="meta-item">
                    <span class="meta-label">Author</span>
                    <span class="meta-value">${plugin.author || 'Unknown'}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">License</span>
                    <span class="meta-value">${plugin.license}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Category</span>
                    <span class="meta-value">${plugin.category}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Downloads</span>
                    <span class="meta-value">${this.formatNumber(plugin.downloads)}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Rating</span>
                    <span class="meta-value">${this.formatRating(plugin.rating)} (${plugin.rating_count} reviews)</span>
                </div>
            </div>

            ${plugin.homepage ? `<p><a href="${plugin.homepage}" target="_blank">Visit Homepage</a></p>` : ''}

            <div class="plugin-detail-actions">
                ${plugin.installed
                    ? `<button class="btn-secondary" disabled>Installed (v${plugin.installed_version})</button>
                       ${plugin.has_update
                           ? `<button class="btn-primary" onclick="Marketplace.updatePlugin('${plugin.id}')">Update to v${plugin.version}</button>`
                           : ''}`
                    : `<button class="btn-primary" onclick="Marketplace.installPlugin('${plugin.id}')">Install</button>`
                }
            </div>
        `;
    },

    // ==================== Installed Tab ====================

    /**
     * Load installed plugins tab
     */
    async loadInstalledTab() {
        this.showLoading('Loading installed plugins...');

        try {
            const response = await this.api('/installed');

            if (response.success) {
                this.state.installedPlugins = response.data.installed;
                this.renderInstalledPlugins(response.data.installed);
            }
        } catch (error) {
            this.showError('Failed to load installed plugins');
        }

        this.hideLoading();
    },

    /**
     * Render installed plugins list
     */
    renderInstalledPlugins(plugins) {
        const list = document.getElementById('installed-list');
        const template = document.getElementById('installed-plugin-template');

        list.innerHTML = '';

        if (plugins.length === 0) {
            list.innerHTML = `
                <div class="empty-state">
                    <h3>No plugins installed</h3>
                    <p>Browse the marketplace to discover plugins</p>
                </div>
            `;
            return;
        }

        plugins.forEach(plugin => {
            const item = template.content.cloneNode(true);
            const itemElement = item.querySelector('.installed-plugin');

            itemElement.dataset.pluginId = plugin.plugin_id;
            item.querySelector('.plugin-name').textContent = plugin.plugin_id;
            item.querySelector('.plugin-version').textContent = `v${plugin.installed_version}`;

            const statusEl = item.querySelector('.plugin-status');
            statusEl.textContent = plugin.is_active ? 'Active' : 'Inactive';
            statusEl.classList.add(plugin.is_active ? 'active' : 'inactive');

            // Update button
            const updateBtn = item.querySelector('.update-btn');
            if (plugin.has_update) {
                updateBtn.classList.remove('hidden');
                updateBtn.addEventListener('click', () => this.updatePlugin(plugin.plugin_id));
            }

            // Uninstall button
            item.querySelector('.uninstall-btn').addEventListener('click', () => {
                if (confirm(`Uninstall ${plugin.plugin_id}?`)) {
                    this.uninstallPlugin(plugin.plugin_id);
                }
            });

            list.appendChild(item);
        });
    },

    // ==================== Updates Tab ====================

    /**
     * Load updates tab
     */
    async loadUpdatesTab() {
        await this.checkForUpdates();
    },

    /**
     * Check for available updates
     */
    async checkForUpdates() {
        this.showLoading('Checking for updates...');

        try {
            const response = await this.api('/updates');

            if (response.success) {
                this.state.availableUpdates = response.data.updates;
                this.renderUpdates(response.data);
                this.updateUpdateBadge(response.data.updates_available);
            }
        } catch (error) {
            this.showError('Failed to check for updates');
        }

        this.hideLoading();
    },

    /**
     * Render available updates
     */
    renderUpdates(data) {
        const list = document.getElementById('updates-list');
        const updateAllBtn = document.getElementById('update-all-btn');

        if (data.updates_available === 0) {
            list.innerHTML = `
                <div class="empty-state">
                    <h3>All plugins are up to date</h3>
                    <p>No updates available at this time</p>
                </div>
            `;
            updateAllBtn.classList.add('hidden');
            return;
        }

        updateAllBtn.classList.remove('hidden');
        updateAllBtn.textContent = `Update All (${data.updates_available})`;

        list.innerHTML = data.updates.map(update => `
            <div class="installed-plugin" data-plugin-id="${update.plugin_id}">
                <div class="plugin-info">
                    <h3 class="plugin-name">${update.name}</h3>
                    <span class="plugin-version">v${update.installed_version} → v${update.available_version}</span>
                </div>
                <div class="plugin-controls">
                    <button class="btn-primary" onclick="Marketplace.updatePlugin('${update.plugin_id}')">Update</button>
                </div>
            </div>
        `).join('');
    },

    /**
     * Update badge count
     */
    updateUpdateBadge(count) {
        const badge = document.getElementById('update-badge');
        badge.textContent = count;
        badge.classList.toggle('hidden', count === 0);
    },

    /**
     * Update all plugins
     */
    async updateAll() {
        for (const update of this.state.availableUpdates) {
            await this.updatePlugin(update.plugin_id);
        }
    },

    // ==================== Repositories Tab ====================

    /**
     * Load repositories tab
     */
    async loadRepositoriesTab() {
        this.showLoading('Loading repositories...');

        try {
            const response = await this.api('/repositories');

            if (response.success) {
                this.renderRepositories(response.data.repositories);
            }
        } catch (error) {
            this.showError('Failed to load repositories');
        }

        this.hideLoading();
    },

    /**
     * Render repositories list
     */
    renderRepositories(repos) {
        const list = document.getElementById('repo-list');
        const template = document.getElementById('repo-template');

        list.innerHTML = '';

        if (repos.length === 0) {
            list.innerHTML = `
                <div class="empty-state">
                    <h3>No repositories configured</h3>
                    <p>Add a repository to discover plugins</p>
                </div>
            `;
            return;
        }

        repos.forEach(repo => {
            const item = template.content.cloneNode(true);
            const itemElement = item.querySelector('.repo-item');

            itemElement.dataset.repoId = repo.id;
            item.querySelector('.repo-name').textContent = repo.name;
            item.querySelector('.repo-url').textContent = repo.url;
            item.querySelector('.repo-plugins').textContent = `${repo.plugin_count} plugins`;
            item.querySelector('.repo-sync').textContent = repo.last_sync
                ? `Last sync: ${new Date(repo.last_sync).toLocaleDateString()}`
                : 'Never synced';

            // Official badge
            if (repo.is_official) {
                item.querySelector('.badge-official').classList.remove('hidden');
            }

            // Status badge
            const statusBadge = item.querySelector('.badge-status');
            statusBadge.textContent = repo.sync_status;
            statusBadge.classList.add(repo.sync_status);

            // Sync button
            item.querySelector('.sync-btn').addEventListener('click', () => this.syncRepo(repo.id));

            // Remove button
            const removeBtn = item.querySelector('.remove-btn');
            if (repo.is_official) {
                removeBtn.style.display = 'none';
            } else {
                removeBtn.addEventListener('click', () => {
                    if (confirm(`Remove repository "${repo.name}"?`)) {
                        this.removeRepository(repo.id);
                    }
                });
            }

            list.appendChild(item);
        });
    },

    /**
     * Show add repository modal
     */
    showAddRepoModal() {
        document.getElementById('repo-url').value = '';
        document.getElementById('repo-name').value = '';
        document.getElementById('repo-modal').classList.remove('hidden');
    },

    /**
     * Add repository
     */
    async addRepository() {
        const url = document.getElementById('repo-url').value;
        const name = document.getElementById('repo-name').value;

        this.showLoading('Adding repository...');

        try {
            const response = await this.api('/repositories', {
                method: 'POST',
                body: JSON.stringify({ url, name })
            });

            if (response.success) {
                this.closeModals();
                await this.loadRepositoriesTab();
                this.showSuccess('Repository added successfully');
            }
        } catch (error) {
            this.showError('Failed to add repository');
        }

        this.hideLoading();
    },

    /**
     * Remove repository
     */
    async removeRepository(id) {
        this.showLoading('Removing repository...');

        try {
            const response = await this.api(`/repositories/${id}`, { method: 'DELETE' });

            if (response.success) {
                await this.loadRepositoriesTab();
                this.showSuccess('Repository removed');
            }
        } catch (error) {
            this.showError('Failed to remove repository');
        }

        this.hideLoading();
    },

    /**
     * Sync single repository
     */
    async syncRepo(id) {
        this.showLoading('Syncing repository...');

        try {
            const response = await this.api(`/sync?repository_id=${id}`, { method: 'POST' });

            if (response.success) {
                await this.loadRepositoriesTab();
                this.showSuccess('Repository synced');
            }
        } catch (error) {
            this.showError('Failed to sync repository');
        }

        this.hideLoading();
    },

    /**
     * Sync all repositories
     */
    async syncAllRepos() {
        this.showLoading('Syncing all repositories...');

        try {
            const response = await this.api('/sync', { method: 'POST' });

            if (response.success) {
                await this.loadRepositoriesTab();
                this.showSuccess(`Synced ${response.data.total_synced} plugins`);
            }
        } catch (error) {
            this.showError('Failed to sync repositories');
        }

        this.hideLoading();
    },

    // ==================== Plugin Actions ====================

    /**
     * Install a plugin
     */
    async installPlugin(pluginId) {
        this.showLoading(`Installing ${pluginId}...`);

        try {
            const response = await this.api(`/install/${encodeURIComponent(pluginId)}`, {
                method: 'POST'
            });

            if (response.success) {
                this.closeModals();
                this.showSuccess(response.data.message);
                this.loadPlugins();
            }
        } catch (error) {
            this.showError(`Failed to install ${pluginId}`);
        }

        this.hideLoading();
    },

    /**
     * Uninstall a plugin
     */
    async uninstallPlugin(pluginId) {
        this.showLoading(`Uninstalling ${pluginId}...`);

        try {
            const response = await this.api(`/uninstall/${encodeURIComponent(pluginId)}`, {
                method: 'POST'
            });

            if (response.success) {
                this.showSuccess(response.data.message);
                this.loadInstalledTab();
            }
        } catch (error) {
            this.showError(`Failed to uninstall ${pluginId}`);
        }

        this.hideLoading();
    },

    /**
     * Update a plugin
     */
    async updatePlugin(pluginId) {
        this.showLoading(`Updating ${pluginId}...`);

        try {
            const response = await this.api(`/update/${encodeURIComponent(pluginId)}`, {
                method: 'POST'
            });

            if (response.success) {
                this.closeModals();
                this.showSuccess(response.data.message);
                await this.checkForUpdates();
            }
        } catch (error) {
            this.showError(`Failed to update ${pluginId}`);
        }

        this.hideLoading();
    },

    // ==================== API Helper ====================

    /**
     * Make API request
     */
    async api(endpoint, options = {}) {
        const response = await fetch(`${this.apiBase}${endpoint}`, {
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error?.message || 'API request failed');
        }

        return data;
    },

    // ==================== UI Helpers ====================

    /**
     * Close all modals
     */
    closeModals() {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.add('hidden');
        });
    },

    /**
     * Show loading overlay
     */
    showLoading(message = 'Loading...') {
        document.getElementById('loading-message').textContent = message;
        document.getElementById('loading-overlay').classList.remove('hidden');
    },

    /**
     * Hide loading overlay
     */
    hideLoading() {
        document.getElementById('loading-overlay').classList.add('hidden');
    },

    /**
     * Show error notification
     */
    showError(message) {
        console.error(message);
        alert(message); // Replace with toast notification
    },

    /**
     * Show success notification
     */
    showSuccess(message) {
        console.log(message);
        alert(message); // Replace with toast notification
    },

    /**
     * Format large numbers
     */
    formatNumber(num) {
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
        return num.toString();
    },

    /**
     * Format rating as stars
     */
    formatRating(rating) {
        const full = Math.floor(rating);
        const half = rating % 1 >= 0.5 ? 1 : 0;
        const empty = 5 - full - half;
        return '★'.repeat(full) + (half ? '½' : '') + '☆'.repeat(empty) + ` (${rating.toFixed(1)})`;
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => Marketplace.init());
