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
                    { title: 'Authentication', url: 'authentication.html' },
                    { title: 'Authorization', url: 'authorization.html' },
                    { title: 'Permissions', url: 'permissions.html' }
                ]
            },
            {
                title: 'Advanced',
                items: [
                    { title: 'Updating & Version Handling', url: 'version-handling.html' },
                    { title: 'Customization', url: 'customization.html' },
                    { title: 'Extending', url: 'extending.html' },
                    { title: 'API Reference', url: 'api-reference.html' }
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
                    { title: 'Version History', url: 'versions/versions.html' }
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
            const response = await fetch(`pages/${filename}`);
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
        }
    }

    // Fetch versions.json and render the list into #versions-list
    async loadVersionsList() {
        const container = document.getElementById('versions-list');
        if (!container) return;
        try {
            const response = await fetch('versions.json');
            if (!response.ok) throw new Error('Failed to load');
            const versions = await response.json();
            if (!Array.isArray(versions) || !versions.length) {
                container.innerHTML = '<p class="text-gray-500 italic">No versions recorded yet.</p>';
                return;
            }
            container.innerHTML = versions.map(v => `
                <a href="versions/v${v.version}.html" class="flex items-center justify-between p-4 bg-white border border-gray-200 rounded-lg hover:border-primary-300 hover:bg-primary-50 transition-colors group">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary-100 text-primary-800">v${v.version}</span>
                        <span class="text-gray-500 text-sm">${v.date}</span>
                    </div>
                    <span class="text-primary-500 text-sm font-medium group-hover:underline">View release notes &rarr;</span>
                </a>
            `).join('');
        } catch {
            container.innerHTML = '<p class="text-red-500 italic text-sm">Failed to load version history.</p>';
        }
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
