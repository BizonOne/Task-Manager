<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Read by the AJAX actions on reminders and notes. Without it their
         scripts throw before ever sending the request. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title> @yield('title') | {{ \App\Support\Brand::name() }} </title>
    <link rel="shortcut icon" href="{{ \App\Support\Brand::faviconUrl() ?? asset('assets/img/logo-circle.png') }}" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    @stack('styles')

    <style>
        :root {
            --primary-50: #f0f4ff;
            --primary-100: #e0edff;
            --primary-500: #6366f1;
            --primary-600: #4f46e5;
            --primary-700: #4338ca;
            --primary-900: #312e81;

            --gray-25: #fcfcfd;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            --success-500: #10b981;
            --success-600: #059669;
            --warning-500: #f59e0b;
            --warning-600: #d97706;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);

            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
        }

        * {
            box-sizing: border-box;
        }

        body {
            display: flex;
            height: 100vh;
            margin: 0;
            overflow: hidden;
            background-color: var(--gray-25);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--gray-700);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .btn {
            padding: 0.3rem 0.7rem;
            font-size: 0.8125rem;
            font-weight: 500;
            border-radius: var(--radius-md);
            border: 1px solid transparent;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary-600);
            color: white;
            border-color: var(--primary-600);
        }

        .btn-primary:hover {
            background-color: var(--primary-700);
            border-color: var(--primary-700);
            color: white;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background-color: var(--success-600);
            color: white;
            border-color: var(--success-600);
        }

        .btn-success:hover {
            background-color: var(--success-600);
            border-color: var(--success-600);
            color: white;
        }

        .btn-warning {
            background-color: var(--warning-500);
            color: white;
            border-color: var(--warning-500);
        }

        .btn-warning:hover {
            background-color: var(--warning-600);
            border-color: var(--warning-600);
            color: white;
        }

        .btn-danger {
            background-color: var(--error-500);
            color: white;
            border-color: var(--error-500);
        }

        .btn-danger:hover {
            background-color: var(--error-600);
            border-color: var(--error-600);
            color: white;
        }

        .btn-outline {
            background-color: white;
            color: var(--gray-700);
            border-color: var(--gray-200);
        }

        .btn-outline:hover {
            background-color: var(--gray-50);
            border-color: var(--gray-300);
            color: var(--gray-800);
        }

        .sidebar {
            width: 240px;
            background-color: white;
            color: var(--gray-700);
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--gray-200);
            position: relative;
        }

        .sidebar-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-200);
            background-color: white;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--gray-900);
            font-weight: 600;
            font-size: 1rem;
        }

        .sidebar-brand img {
            height: 32px;
            width: auto;
        }

        .sidebar-nav {
            flex: 1;
            padding: 0.5rem 0;
            overflow-y: auto;
        }

        .nav-section {
            padding: 0 0.875rem;
            margin-bottom: 0.75rem;
        }

        .nav-section-title {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-500);
            margin-bottom: 0.25rem;
        }

        .nav-item {
            margin-bottom: 0.25rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4375rem 0.75rem;
            margin: 0 0.875rem;
            color: var(--gray-600);
            text-decoration: none;
            border-radius: var(--radius-md);
            font-weight: 500;
            transition: all 0.15s ease;
            position: relative;
        }

        .nav-link:hover {
            background-color: var(--gray-100);
            color: var(--gray-800);
        }

        .nav-link.active {
            background-color: var(--primary-50);
            color: var(--primary-700);
            font-weight: 600;
        }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: -0.875rem;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 16px;
            background-color: var(--primary-600);
            border-radius: 0 2px 2px 0;
        }

        .nav-link i {
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        .nav-badge {
            margin-left: auto;
            background-color: var(--gray-200);
            color: var(--gray-600);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            min-width: 20px;
            text-align: center;
        }

        .nav-link.active .nav-badge {
            background-color: var(--primary-100);
            color: var(--primary-700);
        }

        .sidebar-footer {
            padding: 0.625rem 0.875rem;
            border-top: 1px solid var(--gray-200);
            background-color: white;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: var(--radius-md);
            transition: background-color 0.15s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background-color: var(--gray-50);
        }

        .user-avatar {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-weight: 500;
            font-size: 0.8125rem;
            color: var(--gray-900);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-email {
            font-size: 0.6875rem;
            color: var(--gray-500);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background-color: var(--gray-25);
        }

        .topnav {
            flex-shrink: 0;
            background-color: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 0.5rem 1.25rem;
        }

        .topnav-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            max-width: 100%;
            min-width: 0;
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .topnav-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            min-width: 0;
        }

        .current-time {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
            white-space: nowrap;
        }

        .dropdown-menu {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 0.25rem;
            min-width: 180px;
        }

        .dropdown-item {
            padding: 0.375rem 0.625rem;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
            color: var(--gray-700);
            transition: background-color 0.15s ease;
        }

        .dropdown-item:hover {
            background-color: var(--gray-100);
            color: var(--gray-800);
        }

        /* Notifications bell */
        .notif-bell { position: relative; }
        .notif-dot {
            position: absolute; top: -5px; right: -4px;
            background: #ef4444; color: #fff; font-size: 10px; font-weight: 700;
            min-width: 17px; height: 17px; line-height: 17px; text-align: center;
            border-radius: 999px; padding: 0 4px;
        }
        /* Wider than the phone it drops onto, otherwise. */
        .notif-menu {
            min-width: min(320px, calc(100vw - 1.5rem));
            max-width: min(340px, calc(100vw - 1.5rem));
            max-height: 420px; overflow-y: auto; padding: 0;
        }
        .notif-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 14px; border-bottom: 1px solid var(--gray-200);
        }
        .notif-head strong { font-size: 13px; color: var(--gray-800); }
        .notif-readall {
            background: none; border: none; color: #7c3aed; font-size: 11px;
            font-weight: 600; cursor: pointer; padding: 0;
        }
        .notif-item { display: block; padding: 10px 14px !important; border-bottom: 1px solid var(--gray-100); white-space: normal; }
        .notif-msg { font-size: 13px; font-weight: 500; color: var(--gray-800); line-height: 1.35; }
        .notif-time { font-size: 11px; color: var(--gray-500); margin-top: 2px; }
        .notif-empty { padding: 18px 14px; text-align: center; color: var(--gray-500); font-size: 12px; }
        .notif-viewall { text-align: center; color: #7c3aed !important; font-size: 12px; font-weight: 600; padding: 10px !important; }

        main {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1rem;
            /* A phone scrolls sideways because of one word, not because of the
               layout: a pasted link, an address, a merchant domain. `anywhere`
               rather than `break-word` because only `anywhere` shrinks an
               element's min-content width, and it is that width a grid or flex
               track refuses to go below — which is what drags the page wide. */
            overflow-wrap: anywhere;
        }

        .card {
            background-color: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.15s ease;
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-body {
            padding: 1rem;
        }

        .card-header {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--gray-200);
            background-color: var(--gray-50);
            font-weight: 600;
            color: var(--gray-900);
        }

        footer {
            background-color: white;
            border-top: 1px solid var(--gray-200);
            flex-shrink: 0;
            padding: 0.5rem 1.25rem;
            text-align: center;
        }

        .footer-text {
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .footer-text a {
            color: var(--primary-600);
            text-decoration: none;
        }

        .footer-text a:hover {
            color: var(--primary-700);
        }

        /* Form Controls */
        .form-control {
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            padding: 0.4375rem 0.75rem;
            font-size: 0.875rem;
            transition: all 0.15s ease;
        }

        .form-control:focus {
            border-color: var(--primary-500);
            box-shadow: 0 0 0 3px rgb(99 102 241 / 0.1);
            outline: none;
        }

        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        /* Alert Styles */
        .alert {
            border-radius: var(--radius-md);
            border: 1px solid;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .alert-success {
            background-color: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .alert-danger {
            background-color: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        .alert-warning {
            background-color: #fffbeb;
            border-color: #fed7aa;
            color: #d97706;
        }

        /* ── Hamburger button (mobile only) ── */
        .sidebar-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 36px; height: 36px;
            background: transparent;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            color: var(--gray-600);
            font-size: 1.125rem;
            cursor: pointer;
            flex-shrink: 0;
            transition: background-color 0.15s;
        }
        .sidebar-toggle:hover { background: var(--gray-100); color: var(--gray-800); }

        /* ── Sidebar overlay (mobile backdrop) ── */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 999;
            backdrop-filter: blur(1px);
            -webkit-backdrop-filter: blur(1px);
        }
        .sidebar-overlay.active { display: block; }

        /* ── Responsive Design ── */
        @media (max-width: 768px) {
            .sidebar-toggle { display: flex; }

            .sidebar {
                position: fixed;
                left: -260px;
                top: 0;
                height: 100vh;
                width: 260px;
                z-index: 1000;
                transition: left 0.28s cubic-bezier(0.4,0,0.2,1);
                box-shadow: none;
            }
            .sidebar.open {
                left: 0;
                box-shadow: 4px 0 24px rgba(0,0,0,0.12);
            }

            .content { margin-left: 0; }

            main { padding: 0.75rem; }

            .topnav { padding: 0.5rem 0.75rem; }
        }

        @media (max-width: 576px) {
            /* The clock ticks seconds and spells the weekday out, and on a
               phone it is the widest thing in the bar — it pushes the bell and
               Quick Add off the edge. Nobody opened this app to read the time. */
            .current-time { display: none; }

            main { padding: 0.625rem; }

            /* Bootstrap's grid gutters are negative margins; inside our padded
               main they hang over the right edge. */
            .row { --bs-gutter-x: 1rem; }
        }

        /* A chart sizes itself from its container, but a canvas with nothing
           driving it keeps its intrinsic 300px and pushes the page out. */
        main canvas { max-width: 100%; }

        /* ── The editor's link popup ───────────────────────────────
           Quill drops its "Enter link" box inside the editor and positions it
           from the caret, so near an edge it lands half outside — and every
           editor here sits in a rounded wrapper with overflow:hidden, which
           then clips whatever escaped. Pin it to the left edge and let the
           wrappers show what overflows. */
        .cu-editor-wrap,
        .cu-quill-wrap,
        .editor-container,
        .rt-editor { overflow: visible; }

        /* The wrappers used overflow:hidden to round the toolbar's corners,
           so hand the radius to the parts themselves. */
        .cu-editor-wrap .ql-toolbar,
        .cu-quill-wrap .ql-toolbar,
        .editor-container .ql-toolbar { border-radius: 6px 6px 0 0; }
        .cu-editor-wrap .ql-container,
        .cu-quill-wrap .ql-container,
        .editor-container .ql-container { border-radius: 0 0 6px 6px; }

        .ql-snow .ql-tooltip {
            left: 8px !important;
            max-width: calc(100% - 16px);
            z-index: 30;
            white-space: normal;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .12);
            padding: 6px 10px;
        }

        .ql-snow .ql-tooltip input[type=text] {
            width: 210px;
            max-width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 13px;
        }

        /* Below the toolbar rather than over it, when the caret is on line one. */
        .ql-snow .ql-tooltip:not(.ql-flip) { margin-top: 4px; }

        /* ── Stored rich text ──────────────────────────────────────
           Descriptions, notes and comments are written in the editor and
           rendered as HTML. The styles live here rather than in each view
           so anything that prints them looks the same. */
        .rich-text > *:first-child { margin-top: 0; }
        .rich-text > *:last-child { margin-bottom: 0; }
        .rich-text p { margin: 0 0 8px; }
        .rich-text ul,
        .rich-text ol { margin: 0 0 8px; padding-left: 20px; }
        .rich-text li { margin-bottom: 2px; }
        .rich-text h1 { font-size: 1.4em; }
        .rich-text h2 { font-size: 1.25em; }
        .rich-text h3 { font-size: 1.1em; }
        .rich-text h1,
        .rich-text h2,
        .rich-text h3 { margin: 12px 0 6px; font-weight: 600; }
        .rich-text a { color: var(--primary-600); text-decoration: underline; }
        .rich-text blockquote {
            border-left: 3px solid var(--primary-100);
            margin: 0 0 8px;
            padding: 2px 0 2px 10px;
            color: #4b5563;
        }
        .rich-text pre,
        .rich-text .ql-syntax {
            background: #f3f4f6;
            border-radius: 6px;
            padding: 8px 10px;
            margin: 0 0 8px;
            font-size: 0.9em;
            white-space: pre-wrap;
            overflow-x: auto;
        }
        .rich-text code {
            background: #f3f4f6;
            border-radius: 4px;
            padding: 1px 4px;
            font-size: 0.9em;
        }
        .rich-text img { max-width: 100%; height: auto; }
        .rich-text table { width: 100%; margin: 0 0 8px; border-collapse: collapse; }
        .rich-text th,
        .rich-text td { border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; }
    </style>
    {{-- Brand primary colour override (later declaration wins over :root above) --}}
    @php $__brandPrimary = \App\Support\Brand::primaryColor(); @endphp
    <style>
        :root {
            --primary-500: {{ $__brandPrimary }};
            --primary-600: {{ \App\Support\Brand::darken($__brandPrimary, 0.08) }};
            --primary-700: {{ \App\Support\Brand::darken($__brandPrimary, 0.16) }};
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <div class="sidebar" id="appSidebar">
        <div class="sidebar-header">
            <a href="{{ route('dashboard') }}" class="sidebar-brand">
                <img src="{{ \App\Support\Brand::logoUrl() ?? asset('assets/img/logo-circle.png') }}" alt="{{ \App\Support\Brand::name() }}">
                {{ \App\Support\Brand::name() }}
            </a>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Main</div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('/') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                            <i class="bi bi-house-door-fill"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('projects*') ? 'active' : '' }}"
                            href="{{ route('projects.index') }}">
                            <i class="bi bi-folder-fill"></i>
                            <span>Projects</span>
                            <span
                                class="nav-badge">{{ \App\Models\Project::where('user_id', auth()->id())->count() }}</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        @php
                            $hasProjects = \App\Models\Project::where('user_id', auth()->id())->exists();
                            $taskCount = $hasProjects ? \App\Models\Task::where('user_id', auth()->id())->whereHas('project', function ($q) {$q->where('status', '!=', 'completed');})->notCompleted()->count() : 0;
                        @endphp
                        <a class="nav-link {{ request()->is('tasks*') ? 'active' : '' }}"
                            href="{{ $hasProjects ? route('tasks.index') : route('projects.index') . '?message=create_project_first' }}">
                            <i class="bi bi-check-square-fill"></i>
                            <span>Tasks</span>
                            @if($hasProjects)
                                <span class="nav-badge">{{ $taskCount }}</span>
                            @endif
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Organize</div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('routines*') ? 'active' : '' }}"
                            href="{{ route('routines.index') }}">
                            <i class="bi bi-arrow-repeat"></i>
                            <span>Routines</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('notes*') ? 'active' : '' }}"
                            href="{{ route('notes.index') }}">
                            <i class="bi bi-journal-text"></i>
                            <span>Notes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('reminders*') ? 'active' : '' }}"
                            href="{{ route('reminders.index') }}">
                            <i class="bi bi-bell-fill"></i>
                            <span>Reminders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('files*') ? 'active' : '' }}"
                            href="{{ route('files.index') }}">
                            <i class="bi bi-file-earmark-fill"></i>
                            <span>Files</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('reports*') ? 'active' : '' }}"
                            href="{{ route('reports.index') }}">
                            <i class="bi bi-graph-up"></i>
                            <span>Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('archive*') ? 'active' : '' }}"
                            href="{{ route('archive.index') }}">
                            <i class="bi bi-archive"></i>
                            <span>Archive</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Intelligence</div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('ai*') ? 'active' : '' }}" href="{{ route('ai.index') }}">
                            <i class="bi bi-stars"></i>
                            <span>Lina AI</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="sidebar-footer">
            <div class="user-profile" data-bs-toggle="dropdown" aria-expanded="false">
                @if(Auth::user()->avatar)
                    <img src="{{ route('avatar.show', Auth::user()) }}" alt="{{ Auth::user()->name }}" class="user-avatar" style="object-fit: cover;">
                @else
                    <div class="user-avatar">
                        {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                    </div>
                @endif
                <div class="user-info">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-email">{{ Auth::user()->email }}</div>
                </div>
                <i class="bi bi-three-dots"></i>
            </div>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="{{ route('profile.show') }}"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><a class="dropdown-item" href="{{ route('profile.password') }}"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <form method="POST" action="{{ route('logout') }}" id="logout-form" class="d-inline">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
    <div class="content">
        <header class="topnav">
            <div class="topnav-container">
                <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle menu">
                    <i class="bi bi-list"></i>
                </button>
                <div class="topnav-actions">
                    <span class="current-time" id="currentDateTime"></span>
                    @auth
                    @php $notifUnread = auth()->user()->unreadNotifications; @endphp
                    <div class="dropdown">
                        <button class="btn btn-outline dropdown-toggle notif-bell" type="button" data-bs-toggle="dropdown"
                            aria-expanded="false" title="Notifications">
                            <i class="bi bi-bell"></i>
                            @if($notifUnread->count())
                                <span class="notif-dot">{{ $notifUnread->count() > 9 ? '9+' : $notifUnread->count() }}</span>
                            @endif
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notif-menu">
                            <li class="notif-head">
                                <strong>Notifications</strong>
                                @if($notifUnread->count())
                                <form method="POST" action="{{ route('notifications.readAll') }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="notif-readall">Mark all read</button>
                                </form>
                                @endif
                            </li>
                            @forelse($notifUnread->take(8) as $n)
                                <li>
                                    <a class="dropdown-item notif-item" href="{{ route('notifications.read', $n->id) }}">
                                        <div class="notif-msg">{{ $n->data['message'] ?? 'Notification' }}</div>
                                        <div class="notif-time" title="{{ \App\Support\Dates::dateTime($n->created_at) }}">{{ $n->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @empty
                                <li class="notif-empty">You're all caught up</li>
                            @endforelse
                            <li><a class="dropdown-item notif-viewall" href="{{ route('notifications.index') }}">View all notifications</a></li>
                        </ul>
                    </div>
                    @endauth
                    <div class="dropdown">
                        <button class="btn btn-outline dropdown-toggle" type="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="bi bi-plus-lg"></i>
                            <span class="d-none d-md-inline">Quick Add</span>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="{{ route('projects.create') }}"><i
                                        class="bi bi-folder-plus me-2"></i>New Project</a></li>
                            <li><a class="dropdown-item" href="{{ route('notes.create') }}"><i
                                        class="bi bi-journal-plus me-2"></i>New Note</a></li>
                            <li><a class="dropdown-item" href="{{ route('reminders.create') }}"><i
                                        class="bi bi-bell me-2"></i>New Reminder</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash Messages -->
        @foreach(['success' => ['bi-check-circle','#166534','#f0fdf4','#86efac'], 'error' => ['bi-exclamation-circle','#991b1b','#fef2f2','#fca5a5'], 'warning' => ['bi-exclamation-triangle','#92400e','#fffbeb','#fcd34d'], 'info' => ['bi-info-circle','#1e40af','#eff6ff','#93c5fd']] as $type => [$icon,$textColor,$bgColor,$borderColor])
            @if(session($type))
            <div class="px-4 pt-3" style="max-width:100%;">
                <div role="alert" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;border:1px solid {{ $borderColor }};background:{{ $bgColor }};color:{{ $textColor }};font-size:13px;font-weight:500;line-height:1.4;">
                    <i class="bi {{ $icon }}" style="font-size:15px;flex-shrink:0;"></i>
                    <span style="flex:1;">{{ session($type) }}</span>
                    <button type="button" data-bs-dismiss="alert" aria-label="Close"
                            style="display:flex;align-items:center;justify-content:center;width:22px;height:22px;border:none;background:none;cursor:pointer;color:{{ $textColor }};opacity:.6;padding:0;flex-shrink:0;font-size:14px;line-height:1;"
                            onclick="this.closest('[role=alert]').parentElement.remove()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
            @endif
        @endforeach

        <main>
            @yield('content')
        </main>
        <footer>
            <div class="footer-text">
                &copy; {{ date('Y') }} {{ \App\Support\Brand::name() }}
            </div>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update current time
        function updateDateTime() {
            const now = new Date();
            const options = {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            document.getElementById('currentDateTime').innerText = now.toLocaleDateString('en-US', options);
        }

        updateDateTime();
        setInterval(updateDateTime, 1000); // Update every second

        // ── Sidebar helpers ──
        const appSidebar = document.getElementById('appSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function openSidebar() {
            appSidebar.classList.add('open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            appSidebar.classList.remove('open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        function toggleSidebar() {
            appSidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        }

        // Close sidebar when a nav link is tapped on mobile
        appSidebar.querySelectorAll('.nav-link').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });

        // A tab left open past the session's lifetime starts failing every
        // request with 419, and what the page's handlers show for that —
        // "CSRF token mismatch", "Failed to update status" — reads like a
        // broken app. Watch every fetch, and when the session is gone, say
        // so in words and offer the one thing that fixes it.
        (function () {
            const realFetch = window.fetch;
            let told = false;

            window.fetch = function (...args) {
                return realFetch.apply(this, args).then(function (resp) {
                    if ((resp.status === 419 || resp.status === 401) && !told) {
                        told = true;
                        const bar = document.createElement('div');
                        bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;' +
                            'display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;' +
                            'padding:12px 16px;background:#1e293b;color:#fff;font-size:13px;font-weight:500;' +
                            'box-shadow:0 4px 14px rgba(0,0,0,.3);';
                        bar.innerHTML = '<span>Your session has expired, so this action did not go through.' +
                            ' Copy any unsaved text, then reload to sign back in.</span>' +
                            '<button style="border:none;border-radius:6px;padding:6px 14px;font-size:13px;' +
                            'font-weight:600;background:#7c3aed;color:#fff;cursor:pointer;">Reload</button>';
                        bar.querySelector('button').addEventListener('click', () => location.reload());
                        document.body.appendChild(bar);
                    }
                    return resp;
                });
            };
        })();

        // Anything carrying data-open behaves like a card you can click:
        // hunting for a small eye icon to open a task is a poor way to spend
        // a day. The title inside is still a real link, so keyboard and
        // middle-click work the way people expect.
        (function () {
            let downAt = null;

            document.addEventListener('mousedown', function (e) {
                downAt = { x: e.clientX, y: e.clientY };
            });

            document.addEventListener('click', function (e) {
                const host = e.target.closest('[data-open]');
                if (!host) return;

                // Whatever already does something on click keeps doing it.
                if (e.target.closest('a, button, input, select, textarea, label, form')) return;

                // Dragging a card across the board is not a click on it.
                if (downAt && Math.hypot(e.clientX - downAt.x, e.clientY - downAt.y) > 5) return;

                // Selecting text should not navigate away from what you selected.
                if ((window.getSelection() || '').toString().length) return;

                const url = host.dataset.open;
                if (e.metaKey || e.ctrlKey || e.shiftKey) {
                    window.open(url, '_blank', 'noopener');
                } else {
                    window.location.href = url;
                }
            });

            // Middle click opens a new tab, like a link would.
            document.addEventListener('auxclick', function (e) {
                if (e.button !== 1) return;
                const host = e.target.closest('[data-open]');
                if (!host || e.target.closest('a, button')) return;
                e.preventDefault();
                window.open(host.dataset.open, '_blank', 'noopener');
            });
        })();
    </script>
    @stack('scripts')
</body>

</html>
