{{--
    WRLA Package Styles
    -------------------
    Semantic wrla-* CSS classes for the admin layout.
    Uses <style type="text/tailwindcss"> so the Tailwind CDN processes @apply
    automatically — no publishing required.

    Custom colors (slate-550, slate-725, slate-750, slate-850) are available
    because the CDN config is set synchronously before DOMContentLoaded.
--}}
<style type="text/tailwindcss">

    /* Prevent native buttons from receiving browser or Tailwind focus rings. */
    button:focus,
    button:focus-visible {
        outline: none !important;
        --tw-ring-offset-shadow: 0 0 #0000 !important;
        --tw-ring-shadow: 0 0 #0000 !important;
        box-shadow: var(--tw-shadow, 0 0 #0000) !important;
    }

    /* ================================================================
     * SIDEBAR
     * ================================================================ */

    /** Main sidebar wrapper */
    .wrla-sidebar {
        @apply sticky whitespace-nowrap top-0 md:flex flex flex-col justify-start items-start
               h-full border-r-2 border-slate-300 dark:border-slate-950
               bg-slate-700 dark:bg-slate-700
               shadow-lg shadow-slate-500 dark:shadow-slate-950 z-10;
    }

    /** Collapse / expand toggle button fixed to top-right of the panel */
    .wrla-sidebar-collapse-btn {
        @apply absolute z-10 top-0 pt-1 w-7 h-[34px] opacity-60 text-sm
               flex justify-center items-center
               border-b bg-slate-800 text-slate-400
               shadow-md dark:shadow-slate-900 dark:border-slate-400 cursor-pointer;
    }

    /** Drag-to-resize handle on the right edge */
    .wrla-sidebar-resize-bar {
        @apply absolute top-0 -right-1 h-full w-[4px]
               bg-slate-400 dark:bg-slate-800
               border-r border-slate-400 dark:border-slate-500;
    }

    /** Logo image wrapper */
    .wrla-sidebar-logo {
        @apply w-3/4 max-w-48 mx-auto pt-4 pb-4;
    }

    /** Horizontal rule separating sidebar sections */
    .wrla-sidebar-divider {
        @apply w-full border-t border-slate-700;
    }

    /** Impersonation notice bar */
    .wrla-sidebar-impersonate-bar {
        @apply w-full px-5 pt-2 pb-3 bg-slate-850 text-slate-200
               overflow-hidden text-sm border-b border-slate-600;
    }

    /** Logged-in user profile block */
    .wrla-sidebar-profile {
        @apply flex w-full justify-start items-center gap-4
               px-5 py-4 bg-slate-800 text-slate-200 overflow-hidden;
    }

    /** Scrollable area containing the navigation */
    .wrla-sidebar-scroll {
        @apply flex flex-col justify-start items-start w-full h-full overflow-y-auto;
    }


    /* ================================================================
     * NAVIGATION — leaf items (no children)
     * ================================================================ */

    /** Outer nav list wrapper */
    .wrla-nav-container {
        @apply flex flex-col gap-1 w-full pb-0.5 border-t border-slate-600 select-none;
    }

    /** Base layout for every nav link (both leaf and group trigger) */
    .wrla-nav-item {
        @apply grid grid-cols-[36px,1fr] justify-start items-center
               whitespace-nowrap w-full select-none pl-2 pt-2 pb-1 font-bold;
    }

    /** Applied when the item is enabled / clickable */
    .wrla-nav-item-enabled {
        @apply text-slate-200
               hover:bg-slate-800 hover:!text-primary-500 dark:hover:!text-primary-500;
    }

    /** Applied when the item is disabled */
    .wrla-nav-item-disabled {
        @apply text-slate-400;
    }

    /** Applied when the item matches the current route */
    .wrla-nav-item-active {
        @apply !text-primary-500 bg-slate-800 !border-t-2 !border-b-2 border-slate-600;
    }

    /** Icon cell inside a nav item */
    .wrla-nav-item-icon {
        @apply text-center w-8 h-8 overflow-hidden;
    }

    /** Label text inside a nav item */
    .wrla-nav-item-label {
        @apply relative text-sm;
    }


    /* ================================================================
     * NAVIGATION — group items (collapsible, with children)
     * ================================================================ */

    /** Header row for a collapsible group */
    .wrla-nav-group-header {
        @apply relative flex items-stretch justify-between
               h-fit w-full whitespace-nowrap select-none font-bold bg-slate-700;
    }

    /** Clickable trigger (link or span) for a group header */
    .wrla-nav-group-trigger {
        @apply grid grid-cols-[36px,1fr] justify-start items-center
               whitespace-nowrap w-full select-none pl-2 pt-2 pb-1 font-bold
               text-slate-200 hover:text-primary-500 bg-slate-700 hover:bg-slate-800;
    }

    /** Alpine-toggled: group itself is the active route */
    .wrla-nav-group-active {
        @apply !text-primary-500 !bg-slate-800;
    }

    /** Alpine-toggled: a child of this group is the active route */
    .wrla-nav-group-child-active {
        @apply !text-primary-500 bg-slate-750;
    }

    /** Alpine-toggled: active/selected border when dropdown is closed */
    .wrla-nav-group-border-closed {
        @apply !border-t-2 !border-b-2 border-slate-600;
    }

    /** Alpine-toggled: active/selected border when dropdown is open */
    .wrla-nav-group-border-open {
        @apply !border-t-2 !border-b border-slate-600;
    }

    /** Chevron / arrow toggle on the far right of a group header */
    .wrla-nav-group-arrow {
        @apply border-l border-slate-550 bg-slate-725 absolute right-0
               flex justify-center items-center w-10 min-w-10 min-h-full
               hover:bg-slate-800 text-slate-300 dark:text-slate-300
               cursor-pointer hover:text-primary-500;
    }

    /** Dropdown panel containing child links */
    .wrla-nav-group-children {
        @apply w-full bg-slate-725 border-t border-b border-slate-800;
        /* Note: border-bottom-color is overridden inline via config value */
    }


    /* ================================================================
     * NAVIGATION — child items inside a group dropdown
     * ================================================================ */

    /** Base layout for a child link */
    .wrla-nav-child-item {
        @apply grid grid-cols-[36px,1fr] items-center justify-start
               w-full pl-7 pr-6 pt-1 pb-0 font-bold;
    }

    /** Applied when the child is the active route */
    .wrla-nav-child-item-active {
        @apply !text-primary-500 !bg-slate-800;
    }

    /** Applied when the child is enabled */
    .wrla-nav-child-item-enabled {
        @apply text-slate-200
               hover:bg-slate-800 hover:!text-primary-500 dark:hover:!text-primary-500;
    }

    /** Applied when the child is disabled */
    .wrla-nav-child-item-disabled {
        @apply text-slate-400;
    }

</style>
