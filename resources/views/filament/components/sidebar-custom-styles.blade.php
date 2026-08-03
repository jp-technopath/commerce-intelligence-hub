<style>
    /* Modernized Sidebar Styling */
    .fi-sidebar {
        border-right: 1px solid rgba(226, 232, 240, 0.8);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .dark .fi-sidebar {
        border-right: 1px solid rgba(30, 41, 59, 0.8);
    }

    /* Sidebar Navigation Padding & Scrollbar */
    .fi-sidebar-nav {
        padding-top: 1rem !important;
        padding-bottom: 2rem !important;
    }

    .fi-sidebar-nav::-webkit-scrollbar {
        width: 4px;
    }

    .fi-sidebar-nav::-webkit-scrollbar-track {
        background: transparent;
    }

    .fi-sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(203, 213, 225, 0.5);
        border-radius: 9999px;
    }

    .dark .fi-sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(51, 65, 85, 0.5);
    }

    /* Modern Group Headers */
    .fi-sidebar-group-label {
        font-size: 0.68rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.08em !important;
        text-transform: uppercase !important;
        color: #64748b !important;
        margin-bottom: 0.35rem !important;
    }

    .dark .fi-sidebar-group-label {
        color: #94a3b8 !important;
    }

    /* Navigation Item Buttons */
    .fi-sidebar-item-button {
        border-radius: 0.6rem !important;
        padding: 0.45rem 0.65rem !important;
        transition: all 0.15s ease-in-out !important;
        font-weight: 500 !important;
    }

    /* Hover States for Menu Items */
    .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-button:hover {
        background-color: rgba(241, 245, 249, 0.9) !important;
        transform: translateX(3px);
        color: #0f172a !important;
    }

    .dark .fi-sidebar-item:not(.fi-active) .fi-sidebar-item-button:hover {
        background-color: rgba(30, 41, 59, 0.7) !important;
        color: #f8fafc !important;
    }

    /* Modern Active State */
    .fi-sidebar-item.fi-active .fi-sidebar-item-button {
        background: linear-gradient(135deg, rgba(224, 242, 254, 0.85), rgba(219, 234, 254, 0.85)) !important;
        color: #0369a1 !important;
        font-weight: 600 !important;
        box-shadow: 0 1px 3px rgba(2, 132, 199, 0.08) !important;
        border-left: 3px solid #0284c7 !important;
        border-radius: 0 0.6rem 0.6rem 0 !important;
    }

    .dark .fi-sidebar-item.fi-active .fi-sidebar-item-button {
        background: linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(59, 130, 246, 0.18)) !important;
        color: #38bdf8 !important;
        font-weight: 600 !important;
        border-left: 3px solid #38bdf8 !important;
        border-radius: 0 0.6rem 0.6rem 0 !important;
    }

    /* Active Icon Glow */
    .fi-sidebar-item.fi-active .fi-sidebar-item-icon {
        color: #0284c7 !important;
        filter: drop-shadow(0 1px 2px rgba(2, 132, 199, 0.25));
    }

    .dark .fi-sidebar-item.fi-active .fi-sidebar-item-icon {
        color: #38bdf8 !important;
        filter: drop-shadow(0 1px 3px rgba(56, 189, 248, 0.3));
    }

    /* Collapsed Sidebar Icon Adjustments */
    .fi-sidebar-header {
        border-bottom: 1px solid rgba(241, 245, 249, 0.8);
    }

    .dark .fi-sidebar-header {
        border-bottom: 1px solid rgba(30, 41, 59, 0.8);
    }

    /* Main Right Content Panel Width (85% of available width) */
    .fi-main {
        max-width: 85% !important;
        width: 85% !important;
    }

    /* Premium Search Box Custom Styles */
    .sidebar-search-box {
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    .sidebar-search-box:focus {
        box-shadow: 0 0 20px rgba(10, 160, 194, 0.25), 0 0 0 2px rgba(10, 160, 194, 0.18) !important;
    }

    .dark .sidebar-search-box:focus {
        box-shadow: 0 0 22px rgba(56, 189, 248, 0.3), 0 0 0 2px rgba(56, 189, 248, 0.2) !important;
    }
</style>
