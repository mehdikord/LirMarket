<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'پنل مدیریت')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50" style="font-family: 'Vazirmatn', sans-serif;">
    @auth
    <div class="flex min-h-screen">
        <!-- Mobile Menu Toggle Button -->
        <button id="sidebar-toggle" class="md:hidden fixed top-4 right-4 z-50 p-2 rounded-lg bg-blue-600 text-white shadow-lg hover:bg-blue-700 transition">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>

        <!-- Mobile Overlay -->
        <div id="sidebar-overlay" class="md:hidden fixed inset-0 bg-black bg-opacity-50 z-30 hidden"></div>

        <!-- Sidebar -->
        <aside id="sidebar" class="fixed right-0 top-0 h-full w-64 shadow-xl z-40" style="background-color: #212121;">
            <!-- Sidebar Header -->
            <div class="flex items-center justify-between h-16 px-6 border-b border-white border-opacity-5">
                <span class="text-xl font-bold text-white">پنل مدیریت</span>
            </div>

            <!-- Sidebar Menu -->
            <nav class="mt-6 px-4 space-y-8">
                <!-- Dashboard -->
                <a href="{{ route('dashboard') }}" class="sidebar-item {{ request()->is('/') ? 'active' : '' }}">
                    <svg class="w-5 h-5 ml-3 shrink-0 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span class="text-white">داشبورد</span>
                </a>

                <!-- Pending Approval -->
                <a href="{{ route('members.pending-approval') }}" class="sidebar-item {{ request()->is('pending-approval*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 ml-3 shrink-0 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-white">در انتظار تایید</span>
                </a>

                <!-- Members -->
                <a href="{{ route('members.index') }}" class="sidebar-item {{ request()->is('members*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 ml-3 shrink-0 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <span class="text-white">کاربران</span>
                </a>

                <!-- Requests -->
                <a href="{{ route('requests.index') }}" class="sidebar-item {{ request()->is('requests*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 ml-3 shrink-0 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span class="text-white">درخواست‌ها</span>
                </a>
            </nav>

            <!-- Sidebar Footer -->
            <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-white border-opacity-5">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-white">{{ Auth::user()->name }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-150 ease-in-out"
                    >
                        <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        <span>خروج</span>
                    </button>
                </form>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="flex-1 mr-64">
            @yield('content')
        </div>
    </div>
    @else
    <!-- Content for non-authenticated users -->
    <div class="min-h-screen">
        @yield('content')
    </div>
    @endauth

    <style>
        .sidebar-item {
            @apply flex items-center px-4 py-3 text-sm font-medium rounded-lg text-white hover:bg-white hover:bg-opacity-10 transition-all duration-200 ease-in-out;
            display: flex;
            align-items: center;
        }
        .sidebar-item.active {
            @apply bg-white bg-opacity-35 text-white shadow-lg border-r-4 border-yellow-300;
            font-weight: 600;
        }
        .sidebar-item.active svg {
            @apply text-yellow-200;
        }
        .sidebar-item:hover {
            @apply bg-white bg-opacity-15;
        }
        .sidebar-item svg {
            @apply text-white;
        }

        /* Responsive: Hide sidebar on mobile, show toggle button */
        @media (max-width: 768px) {
            #sidebar {
                transform: translateX(100%);
                transition: transform 0.3s ease-in-out;
            }
            #sidebar.open {
                transform: translateX(0);
            }
            .flex-1 {
                margin-right: 0 !important;
            }
        }
    </style>

    @auth
    <script>
        // Sidebar toggle for mobile
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        if (sidebarToggle && sidebar && overlay) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('hidden');
            });

            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.add('hidden');
            });
        }
    </script>
    @endauth
</body>
</html>

