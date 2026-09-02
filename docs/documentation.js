// Documentation System - Dynamic Content Loading and Management

// Page content is stored in docs/pages/**/*.html files.
// To add a new page: create a new file in docs/pages/ (or a subdirectory), then add it to getNavigation() below.

class DocumentationApp {
    constructor() {
        this.searchQuery = '';
        this.searchResults = [];
        this.mobileMenuOpen = false;
        this.currentPage = this.getCurrentPage();
        this.navigation = this.getNavigation();
        this.pages = [];
        this.pageCache = {};
        this.contentLoaded = false;
    }

    // Get current page from URL hash (hash navigation avoids file:// cross-origin restrictions)
    getCurrentPage() {
        const hash = window.location.hash.replace('#', '');
        if (!hash || hash === 'index.html') {
            return 'quick-start.html';
        }
        return hash.endsWith('.html') ? hash : hash + '.html';
    }

    // Navigation structure
    getNavigation() {
        return [
            {
                title: 'Getting Started',
                items: [
                    { title: 'Quick Start', url: 'quick-start.html' },
                    { title: 'Installation', url: 'installation.html' },
                    { title: 'Configuration', url: 'configuration.html' }
                ]
            },
            {
                title: 'Core Concepts',
                items: [
                    {
                        title: 'Manageable Models',
                        url: 'manageable-models/manageable-models.html',
                        children: [
                            { title: 'mainSetup', url: 'manageable-models/manageable-models-main-setup.html' },
                            { title: 'browseSetup', url: 'manageable-models/manageable-models-browse-setup.html' },
                        ]
                    },
                    { title: 'Custom Pages', url: 'custom-pages.html' },
                    { title: 'Authentication', url: 'authentication.html' },
                    { title: 'Authorization', url: 'authorization.html' },
                    { title: 'Permissions', url: 'permissions.html' }
                ]
            },
            {
                title: 'Optional Features',
                items: [
                    { title: 'Site Configuration', url: 'site-configuration.html' }
                ]
            },
            {
                title: 'Versions',
                items: [
                    { title: 'Version History', url: 'versions/versions.html' },
                    { title: 'Releasing a Version', url: 'versions/releasing.html' }
                ]
            }
        ];
    }

    // Fetch a single page from pages/ directory (with caching)
    async fetchPage(filename) {
        if (this.pageCache[filename] !== undefined) {
            return this.pageCache[filename];
        }
        try {
            const response = await fetch(`pages/${filename}?v=${Date.now()}`, { cache: 'no-store' });
            const html = response.ok ? await response.text() : null;
            this.pageCache[filename] = html;
            return html;
        } catch {
            this.pageCache[filename] = null;
            return null;
        }
    }

    // Build search index by fetching all navigation pages
    async loadPages() {
        const parser = new DOMParser();
        const filenames = [...this.navigation.flatMap(s => s.items.flatMap(i => [i.url, ...(i.children || []).map(c => c.url)]))];
        const unique = [...new Set(filenames)];

        const settled = await Promise.allSettled(unique.map(async filename => {
            const html = await this.fetchPage(filename);
            if (!html) return null;
            const doc = parser.parseFromString(html, 'text/html');
            const h1 = doc.querySelector('h1');
            const title = h1 ? h1.textContent.trim() : filename.replace('.html', '');
            const content = doc.body.textContent.replace(/\s+/g, ' ').trim();
            const navUrl = filename === 'quick-start.html' ? 'quick-start.html' : filename;
            return { url: navUrl, file: filename, title, content };
        }));

        this.pages = settled
            .filter(r => r.status === 'fulfilled' && r.value)
            .map(r => r.value);
    }

    // Initialize the application
    async initApp() {
        this.currentPage = this.getCurrentPage();
        this.setupInterception(); // Set up immediately — before any async loading
        // Load the current page first so the spinner is visible during the fetch.
        // Then build the search index in the background (no await).
        await this.loadContent();
        this.loadPages();
    }

    // Load content for the current page from pages/ directory
    async loadContent() {
        const contentArea = document.getElementById('content-area');
        if (!contentArea) return;

        let contentFile = this.currentPage;

        // If it's index.html, show quick-start content
        if (contentFile === 'index.html' || contentFile === '') {
            contentFile = 'quick-start.html';
        }

        const html = await this.fetchPage(contentFile);
        const spinner = document.getElementById('content-spinner');
        if (spinner) spinner.remove();
        contentArea.innerHTML = html
            ?? '<p class="text-gray-500 italic mt-4">This page is coming soon.</p>';
        this.updateTitle();
        this.contentLoaded = true;
        this.initCopyButtons();
        if (this.currentPage === 'versions/versions.html') {
            this.loadVersionsList();
        } else if (/^versions\/v[\d.]+\.html$/.test(this.currentPage)) {
            this.loadVersionDetail();
        } else if (this.currentPage === 'versions/releasing.html') {
            this.loadLatestVersion();
        }
    }

    // Released doc pages. Packagist's p2 metadata has no CORS headers, so it can't be
    // fetched from the browser — add an entry here whenever a new
    // docs/pages/versions/vX.Y.Z.html page is added (see releasing.html step 2).
    getVersionManifest() {
        return [
            { version: '0.8.0', date: '2026-08-19' },
            { version: '0.8.1', date: '2026-09-01' },
            { version: '0.8.2', date: '2026-09-02' },
            { version: '0.8.3', date: '2026-09-02' },
        ];
    }

    // Compare two dotted numeric version strings. Returns >0 when a is newer.
    compareVersions(a, b) {
        const pa = String(a).split('.').map(Number);
        const pb = String(b).split('.').map(Number);
        const len = Math.max(pa.length, pb.length);
        for (let i = 0; i < len; i++) {
            const diff = (pa[i] || 0) - (pb[i] || 0);
            if (diff !== 0) return diff;
        }
        return 0;
    }

    // Manifest entries, newest first
    getSortedVersions() {
        return [...this.getVersionManifest()].sort((a, b) => this.compareVersions(b.version, a.version));
    }

    // Populate [data-latest-version] on the releasing page with the newest release
    loadLatestVersion() {
        const el = document.querySelector('[data-latest-version]');
        if (!el) return;
        const latest = this.getSortedVersions()[0];
        el.textContent = latest ? `v${latest.version}` : 'No versions published yet';
    }

    // Populate [data-version-number] and [data-version-date] on a version detail page
    loadVersionDetail() {
        const match = this.currentPage.match(/^versions\/v([\d.]+)\.html$/);
        if (!match) return;
        const version = match[1];
        const entry = this.getVersionManifest().find(v => v.version === version);
        const numEl = document.querySelector('[data-version-number]');
        const dateEl = document.querySelector('[data-version-date]');
        if (numEl) numEl.textContent = `v${version}`;
        if (dateEl && entry) dateEl.textContent = entry.date;
    }

    // Render the manifest into #versions-list and wire up the search filter
    loadVersionsList() {
        const container = document.getElementById('versions-list');
        if (!container) return;
        const versions = this.getSortedVersions();
        if (!versions.length) {
            container.innerHTML = '<p class="text-gray-500 italic">No versions recorded yet.</p>';
            return;
        }
        container.innerHTML = versions.map(v => `
            <a href="versions/v${v.version}.html" data-version="${v.version}" class="flex items-center justify-between p-4 bg-white border border-gray-200 rounded-lg hover:border-primary-300 hover:bg-primary-50 transition-colors group">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary-100 text-primary-800">v${v.version}</span>
                    <span class="text-gray-500 text-sm">${v.date}</span>
                </div>
                <span class="text-primary-500 text-sm font-medium group-hover:underline">View release notes &rarr;</span>
            </a>
        `).join('');
        this.initVersionSearch();
    }

    // Filter the rendered version list live as the user types in #version-search
    initVersionSearch() {
        const input = document.getElementById('version-search');
        const container = document.getElementById('versions-list');
        const empty = document.getElementById('version-search-empty');
        if (!input || !container) return;
        input.value = '';
        empty?.classList.add('hidden');
        input.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            let visibleCount = 0;
            container.querySelectorAll('[data-version]').forEach(el => {
                const matches = el.dataset.version.toLowerCase().includes(query);
                el.classList.toggle('hidden', !matches);
                if (matches) visibleCount++;
            });
            empty?.classList.toggle('hidden', visibleCount !== 0);
        });
    }

    // Inject an absolutely-positioned copy button into every .docs-code-block
    initCopyButtons() {
        const CLIPBOARD_ICON = `<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>`;
        const CHECK_ICON = `<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>`;

        document.querySelectorAll('#content-area .docs-code-block').forEach(block => {
            // Skip if a button was already injected
            if (block.querySelector('.docs-copy-btn')) return;

            const btn = document.createElement('button');
            btn.className = 'docs-copy-btn absolute top-2 right-2 p-1.5 rounded text-gray-500 hover:text-white hover:bg-gray-700 transition-colors focus:outline-none';
            btn.title = 'Copy to clipboard';
            btn.innerHTML = CLIPBOARD_ICON;

            btn.addEventListener('click', () => {
                const codeEl = block.querySelector('code') || block.querySelector('pre');
                const text = codeEl ? codeEl.textContent : block.innerText;
                navigator.clipboard.writeText(text).then(() => {
                    btn.innerHTML = CHECK_ICON;
                    btn.classList.add('text-green-400');
                    btn.classList.remove('text-gray-500');
                    setTimeout(() => {
                        btn.innerHTML = CLIPBOARD_ICON;
                        btn.classList.remove('text-green-400');
                        btn.classList.add('text-gray-500');
                    }, 2000);
                }).catch(() => {
                    // Fallback for browsers without clipboard API
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    btn.innerHTML = CHECK_ICON;
                    btn.classList.add('text-green-400');
                    btn.classList.remove('text-gray-500');
                    setTimeout(() => {
                        btn.innerHTML = CLIPBOARD_ICON;
                        btn.classList.remove('text-green-400');
                        btn.classList.add('text-gray-500');
                    }, 2000);
                });
            });

            block.appendChild(btn);
        });
    }

    // Update page title
    updateTitle() {
        const page = this.pages.find(p => p.url === this.currentPage);
        if (page) {
            document.title = `${page.title} - WRLA Documentation`;
        } else {
            document.title = 'WRLA Documentation';
        }
    }

    // Setup link interception for SPA-like behavior
    setupInterception() {
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (link && link.getAttribute('href')) {
                const href = link.getAttribute('href');

                // Check if it's a local documentation link
                if (href.endsWith('.html') && !href.startsWith('http') && !href.startsWith('#')) {
                    e.preventDefault();
                    this.navigateTo(href);
                }
            }
        });

        // Handle browser back/forward via hash changes (works with file:// protocol)
        window.addEventListener('hashchange', () => {
            this.currentPage = this.getCurrentPage();
            this.loadContent();
        });
    }

    // Navigate to a page using hash-based routing (compatible with file:// protocol)
    navigateTo(url) {
        const isHome = url === 'index.html' || url === 'quick-start.html';
        // Set hash; empty hash for quick-start page keeps the URL clean
        window.location.hash = isHome ? '' : url;
        this.currentPage = isHome ? 'quick-start.html' : url;
        this.loadContent();

        // Close mobile menu and clear search if open
        this.mobileMenuOpen = false;
        this.searchQuery = '';
        this.searchResults = [];

        // Scroll to top
        window.scrollTo(0, 0);
    }

    // Perform search
    performSearch() {
        if (this.searchQuery.length < 2) {
            this.searchResults = [];
            return;
        }

        const query = this.searchQuery.toLowerCase();
        this.searchResults = this.pages
            .filter(page =>
                page.title.toLowerCase().includes(query) ||
                page.content.toLowerCase().includes(query)
            )
            .map(page => ({
                title: page.title,
                url: page.url,
                preview: page.content.substring(0, 100) + '...'
            }));
    }

    // Check if mobile
    isMobile() {
        return window.innerWidth < 768;
    }
}

// Initialize Alpine.js with our app
document.addEventListener('alpine:init', () => {
    Alpine.data('documentationApp', () => new DocumentationApp());
});

// If Alpine is already initialized, register the component
if (window.Alpine) {
    Alpine.data('documentationApp', () => new DocumentationApp());
}
