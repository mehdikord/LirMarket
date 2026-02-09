@extends('layouts.app')

@section('title', 'کاربران')

@section('content')
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="w-full px-2 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">کاربران</h1>
                    <p class="mt-1 text-sm text-gray-500">مدیریت کاربران سیستم</p>
                </div>
                <button
                    type="button"
                    onclick="openAddMemberModal()"
                    class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg shadow-sm transition-colors duration-200"
                >
                    <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    افزودن کاربر جدید
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="w-full px-2 py-8">
        <!-- Flash Messages -->
        @if(session('success'))
        <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-center">
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            {{ session('success') }}
        </div>
        @endif

        @if(session('error'))
        <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-center">
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            {{ session('error') }}
        </div>
        @endif

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <!-- راهنمای کپی کد فعالسازی -->
            <div class="px-6 py-3 bg-gray-50 border-b border-gray-200">
                <div class="flex items-center text-sm text-gray-600">
                    <svg class="w-4 h-4 ml-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                    </svg>
                    برای کپی کد فعالسازی کاربر روی آن کلیک کنید
                </div>
            </div>
            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                نام
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                شماره موبایل
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                نام کاربری
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                Telegram ID
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                کد فعالسازی
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                تایید شده
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                وضعیت حساب
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                تاریخ ثبت
                            </th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">
                                عملیات
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($members as $member)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-center">
                                {{ $member->name ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                {{ $member->phone ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                {{ $member->telegram_username ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                {{ $member->telegram_id ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                @if($member->verify_code)
                                    <span
                                        onclick="copyVerifyCode('{{ $member->verify_code }}')"
                                        class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-mono font-medium bg-blue-100 text-blue-800 cursor-pointer hover:bg-blue-200 transition-colors"
                                        title="کلیک برای کپی"
                                    >
                                      
                                        {{ $member->verify_code }}
                                    </span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                @if($member->is_verified)
                                    <svg class="w-6 h-6 text-green-500 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                @else
                                    <svg class="w-6 h-6 text-red-500 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                @if($member->is_block == 0)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        فعال
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        غیرفعال
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                @if($member->created_at)
                                    {{ \Morilog\Jalali\Jalalian::fromCarbon($member->created_at)->format('d-m-Y') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <!-- تایید حساب کاربری -->
                                    <button
                                        type="button"
                                        title="تایید حساب کاربری"
                                        @if($member->is_verified) disabled @endif
                                        class="p-2 rounded-lg @if($member->is_verified) bg-gray-100 text-gray-400 cursor-not-allowed @else bg-green-100 text-green-600 hover:bg-green-200 @endif transition-colors"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </button>

                                    <!-- ویرایش اطلاعات -->
                                    <button
                                        type="button"
                                        title="ویرایش اطلاعات"
                                        class="p-2 rounded-lg bg-blue-100 text-blue-600 hover:bg-blue-200 transition-colors"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </button>

                                    <!-- تغییر وضعیت حساب -->
                                    <button
                                        type="button"
                                        title="تغییر وضعیت حساب"
                                        class="p-2 rounded-lg bg-yellow-100 text-yellow-600 hover:bg-yellow-200 transition-colors"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                    </button>

                                    <!-- درخواست‌ها -->
                                    <button
                                        type="button"
                                        title="درخواست‌ها"
                                        class="p-2 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">
                                <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <p>هیچ کاربری یافت نشد</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($members->hasPages())
            <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                <div class="flex items-center justify-between">
                    <div class="flex-1 flex justify-between sm:hidden">
                        @if($members->onFirstPage())
                            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-400 bg-white cursor-not-allowed">
                                قبلی
                            </span>
                        @else
                            <a href="{{ $members->previousPageUrl() }}" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                قبلی
                            </a>
                        @endif

                        @if($members->hasMorePages())
                            <a href="{{ $members->nextPageUrl() }}" class="mr-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                بعدی
                            </a>
                        @else
                            <span class="mr-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-400 bg-white cursor-not-allowed">
                                بعدی
                            </span>
                        @endif
                    </div>
                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700">
                                نمایش
                                <span class="font-medium">{{ $members->firstItem() }}</span>
                                تا
                                <span class="font-medium">{{ $members->lastItem() }}</span>
                                از
                                <span class="font-medium">{{ $members->total() }}</span>
                                نتیجه
                            </p>
                        </div>
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                @if($members->onFirstPage())
                                    <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-400 cursor-not-allowed">
                                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                @else
                                    <a href="{{ $members->previousPageUrl() }}" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </a>
                                @endif

                                @php
                                    $currentPage = $members->currentPage();
                                    $lastPage = $members->lastPage();
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($lastPage, $currentPage + 2);
                                @endphp

                                @if($startPage > 1)
                                    <a href="{{ $members->url(1) }}" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                        1
                                    </a>
                                    @if($startPage > 2)
                                        <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                            ...
                                        </span>
                                    @endif
                                @endif

                                @for($page = $startPage; $page <= $endPage; $page++)
                                    @if($page == $currentPage)
                                        <span aria-current="page" class="relative inline-flex items-center px-4 py-2 border border-blue-500 bg-blue-50 text-sm font-medium text-blue-600">
                                            {{ $page }}
                                        </span>
                                    @else
                                        <a href="{{ $members->url($page) }}" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            {{ $page }}
                                        </a>
                                    @endif
                                @endfor

                                @if($endPage < $lastPage)
                                    @if($endPage < $lastPage - 1)
                                        <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                            ...
                                        </span>
                                    @endif
                                    <a href="{{ $members->url($lastPage) }}" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                        {{ $lastPage }}
                                    </a>
                                @endif

                                @if($members->hasMorePages())
                                    <a href="{{ $members->nextPageUrl() }}" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </a>
                                @else
                                    <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-400 cursor-not-allowed">
                                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                @endif
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </main>
</div>

<!-- Modal افزودن کاربر جدید -->
<div id="addMemberModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <!-- Backdrop -->
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeAddMemberModal()"></div>

        <!-- Modal Content -->
        <div class="relative inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-right align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-900 flex items-center" id="modal-title">
                    <svg class="w-6 h-6 ml-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                    </svg>
                    افزودن کاربر جدید
                </h3>
                <button type="button" onclick="closeAddMemberModal()" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="mt-4">
                <!-- راهنما -->
                <div class="p-4 mb-6 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 ml-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-sm text-blue-700">کد فعالسازی مربوط به کاربر به صورت خودکار ایجاد میشود</p>
                    </div>
                </div>

                <!-- Form -->
                <form action="{{ route('members.store') }}" method="POST" id="addMemberForm">
                    @csrf

                    <!-- نام کامل -->
                    <div class="mb-5">
                        <label for="name" class="block mb-2 text-sm font-medium text-gray-700">
                            نام کامل کاربر <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="{{ old('name') }}"
                            class="w-full px-4 py-3 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-colors @error('name') border-red-500 @enderror"
                            placeholder="نام و نام خانوادگی"
                            required
                        >
                        @error('name')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- شماره موبایل -->
                    <div class="mb-5">
                        <label for="phone" class="block mb-2 text-sm font-medium text-gray-700">
                            شماره موبایل <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            value="{{ old('phone') }}"
                            class="w-full px-4 py-3 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-colors @error('phone') border-red-500 @enderror"
                            placeholder="شماره موبایل همراه با کد کشور مثال : 989121234567"
                            dir="ltr"
                            required
                        >
                        @error('phone')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- نام کاربری تلگرام -->
                    <div class="mb-5">
                        <label for="telegram_username" class="block mb-2 text-sm font-medium text-gray-700">
                            نام کاربری تلگرام
                        </label>
                        <input
                            type="text"
                            id="telegram_username"
                            name="telegram_username"
                            value="{{ old('telegram_username') }}"
                            class="w-full px-4 py-3 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-colors @error('telegram_username') border-red-500 @enderror"
                            placeholder="@username"
                            dir="ltr"
                        >
                        @error('telegram_username')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </form>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-end gap-3 pt-4 mt-6 border-t border-gray-200">
                <button
                    type="button"
                    onclick="closeAddMemberModal()"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
                >
                    <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    بستن
                </button>
                <button
                    type="submit"
                    form="addMemberForm"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors"
                >
                    <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    افزودن کاربر
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function openAddMemberModal() {
        document.getElementById('addMemberModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeAddMemberModal() {
        document.getElementById('addMemberModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
    }

    // Copy verify code to clipboard
    function copyVerifyCode(code) {
        navigator.clipboard.writeText(code).then(function() {
            // Show toast notification
            showToast('کد فعالسازی کپی شد: ' + code);
        }).catch(function(err) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = code;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showToast('کد فعالسازی کپی شد: ' + code);
        });
    }

    // Toast notification
    function showToast(message) {
        // Remove existing toast if any
        const existingToast = document.getElementById('copyToast');
        if (existingToast) {
            existingToast.remove();
        }

        // Create toast element
        const toast = document.createElement('div');
        toast.id = 'copyToast';
        toast.className = 'fixed bottom-4 left-4 bg-green-600 text-white px-4 py-3 rounded-lg shadow-lg flex items-center z-50 animate-fade-in';
        toast.innerHTML = `
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            ${message}
        `;
        document.body.appendChild(toast);

        // Remove toast after 3 seconds
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease';
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 3000);
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddMemberModal();
        }
    });

    // Auto open modal if there are validation errors
    @if($errors->any())
        openAddMemberModal();
    @endif
</script>
@endsection
