@extends('layouts.app')

@section('title', 'در انتظار تایید')

@section('content')
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="w-full px-2 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">در انتظار تایید</h1>
                    <p class="mt-1 text-sm text-gray-500">کاربران تایید شده با اسناد</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Success Message -->
    @if(session('success'))
    <div class="w-full px-2 py-4">
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <span>{{ session('success') }}</span>
        </div>
    </div>
    @endif

    <!-- Error Message -->
    @if(session('error'))
    <div class="w-full px-2 py-4">
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            <span>{{ session('error') }}</span>
        </div>
    </div>
    @endif

    <!-- Main Content -->
    <main class="w-full px-2 py-8">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
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
                                        onclick="openApprovalModal({{ $member->id }}, {{ json_encode($member->name ?? 'کاربر') }})"
                                        class="p-2 rounded-lg @if($member->is_verified) bg-gray-100 text-gray-400 cursor-not-allowed @else bg-green-100 text-green-600 hover:bg-green-200 @endif transition-colors"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </button>
                                    
                                    <!-- رد کردن حساب کاربری -->
                                    <button 
                                        type="button"
                                        title="رد کردن"
                                        @if($member->is_verified) disabled @endif
                                        onclick="openRejectionModal({{ $member->id }}, {{ json_encode($member->name ?? 'کاربر') }})"
                                        class="p-2 rounded-lg @if($member->is_verified) bg-gray-100 text-gray-400 cursor-not-allowed @else bg-red-100 text-red-600 hover:bg-red-200 @endif transition-colors"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
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
                                    
                                    <!-- درخواست های در انتظار -->
                                    <button 
                                        type="button"
                                        title="درخواست های در انتظار"
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
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
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

<!-- Approval Modal -->
<div id="approvalModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <!-- Background overlay with blur -->
    <div class="fixed inset-0 transition-opacity modal-backdrop" style="background-color: rgba(0, 0, 0, 0.2);" onclick="closeApprovalModal()"></div>

    <!-- Modal container -->
    <div class="flex min-h-full items-center justify-center p-4">
        <!-- Modal panel -->
        <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-3xl transform transition-all scale-95" id="modal-panel">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900" id="modal-title">
                    تایید حساب کاربری <span id="modal-member-name" class="text-blue-600"></span>
                </h3>
                <button
                    type="button"
                    onclick="closeApprovalModal()"
                    class="text-gray-400 hover:text-gray-600 transition-colors"
                >
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Content -->
            <div class="px-6 py-4 max-h-96 overflow-y-auto">
                <div id="modal-documents" class="space-y-4">
                    <div class="flex items-center justify-center py-8">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                        <span class="mr-3 text-gray-600">در حال بارگذاری...</span>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50">
                <button
                    type="button"
                    onclick="closeApprovalModal()"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                >
                    <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    بستن
                </button>
                <button
                    type="button"
                    id="approve-button"
                    onclick="approveMember()"
                    class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors"
                >
                    <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    تایید حساب کاربری
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div id="rejectionModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="rejection-modal-title" role="dialog" aria-modal="true">
    <!-- Background overlay with blur -->
    <div class="fixed inset-0 transition-opacity modal-backdrop" style="background-color: rgba(0, 0, 0, 0.2);" onclick="closeRejectionModal()"></div>

    <!-- Modal container -->
    <div class="flex min-h-full items-center justify-center p-4">
        <!-- Modal panel -->
        <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-2xl transform transition-all scale-95" id="rejection-modal-panel">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900" id="rejection-modal-title">
                    رد کردن درخواست تایید حساب <span id="rejection-modal-member-name" class="text-red-600"></span>
                </h3>
                <button
                    type="button"
                    onclick="closeRejectionModal()"
                    class="text-gray-400 hover:text-gray-600 transition-colors"
                >
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Content -->
            <div class="px-6 py-4">
                <div class="mb-4">
                    <label for="rejection-reason" class="block text-sm font-medium text-gray-700 mb-2">
                        دلیل رد شدن
                    </label>
                    <textarea
                        id="rejection-reason"
                        name="rejection_reason"
                        rows="4"
                        placeholder="مثال : تصویر ارسالی واضح نیست لطفا مجدد درسال فرمایید ."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                    ></textarea>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50">
                <button
                    type="button"
                    onclick="closeRejectionModal()"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                >
                    <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    بستن
                </button>
                <button
                    type="button"
                    id="reject-button"
                    onclick="rejectMember()"
                    class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors"
                >
                    <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    رد کردن حساب
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentMemberId = null;

function openApprovalModal(memberId, memberName) {
    currentMemberId = memberId;
    document.getElementById('modal-member-name').textContent = memberName;
    const modal = document.getElementById('approvalModal');
    const panel = document.getElementById('modal-panel');
    
    // Show modal
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    
    // Reset panel state
    panel.classList.remove('scale-100', 'scale-95');
    panel.classList.add('scale-95');
    
    // Trigger animation
    setTimeout(() => {
        panel.classList.remove('scale-95');
        panel.classList.add('scale-100');
    }, 10);
    
    // Load documents
    loadDocuments(memberId);
}

function closeApprovalModal() {
    const modal = document.getElementById('approvalModal');
    const panel = document.getElementById('modal-panel');
    
    // Trigger close animation
    panel.classList.remove('scale-100');
    panel.classList.add('scale-95');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        currentMemberId = null;
        document.getElementById('modal-documents').innerHTML = '';
        // Reset panel for next time
        panel.classList.remove('scale-100', 'scale-95');
    }, 200);
}

function loadDocuments(memberId) {
    fetch(`/members/${memberId}/documents`)
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('modal-documents');
            if (data.documents && data.documents.length > 0) {
                container.innerHTML = data.documents.map(doc => `
                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4 flex-1">
                                <div class="flex-shrink-0">
                                    <img src="${doc.file_url}" alt="${doc.name}" class="h-20 w-20 object-cover rounded-lg border border-gray-200">
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-sm font-medium text-gray-900">${doc.name}</h4>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <a href="${doc.file_url}" download class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                    </svg>
                                    دانلود فایل
                                </a>
                            </div>
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<p class="text-center text-gray-500 py-4">هیچ سندی یافت نشد</p>';
            }
        })
        .catch(error => {
            console.error('Error loading documents:', error);
            document.getElementById('modal-documents').innerHTML = '<p class="text-center text-red-500 py-4">خطا در بارگذاری اسناد</p>';
        });
}

function approveMember() {
    if (!currentMemberId) return;
    
    const button = document.getElementById('approve-button');
    button.disabled = true;
    button.innerHTML = '<span>در حال تایید...</span>';
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `/members/${currentMemberId}/approve`;
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_token';
    csrfInput.value = csrfToken;
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Rejection Modal Functions
let currentRejectionMemberId = null;

function openRejectionModal(memberId, memberName) {
    currentRejectionMemberId = memberId;
    document.getElementById('rejection-modal-member-name').textContent = memberName;
    const modal = document.getElementById('rejectionModal');
    const panel = document.getElementById('rejection-modal-panel');
    
    // Reset textarea
    document.getElementById('rejection-reason').value = '';
    
    // Show modal
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    
    // Reset panel state
    panel.classList.remove('scale-100', 'scale-95');
    panel.classList.add('scale-95');
    
    // Trigger animation
    setTimeout(() => {
        panel.classList.remove('scale-95');
        panel.classList.add('scale-100');
    }, 10);
}

function closeRejectionModal() {
    const modal = document.getElementById('rejectionModal');
    const panel = document.getElementById('rejection-modal-panel');
    
    // Trigger close animation
    panel.classList.remove('scale-100');
    panel.classList.add('scale-95');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        currentRejectionMemberId = null;
        document.getElementById('rejection-reason').value = '';
        // Reset panel for next time
        panel.classList.remove('scale-100', 'scale-95');
    }, 200);
}

function rejectMember() {
    if (!currentRejectionMemberId) return;
    
    const button = document.getElementById('reject-button');
    const reason = document.getElementById('rejection-reason').value.trim();
    
    button.disabled = true;
    button.innerHTML = '<span>در حال رد کردن...</span>';
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `/members/${currentRejectionMemberId}/reject`;
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_token';
    csrfInput.value = csrfToken;
    form.appendChild(csrfInput);
    
    // Add reason if provided
    if (reason) {
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'rejection_reason';
        reasonInput.value = reason;
        form.appendChild(reasonInput);
    }
    
    document.body.appendChild(form);
    form.submit();
}

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeApprovalModal();
        closeRejectionModal();
    }
});
</script>

<style>
/* Ensure backdrop blur works */
.modal-backdrop {
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

/* Modal animation */
#modal-panel, #rejection-modal-panel {
    transition: transform 0.2s ease-out, opacity 0.2s ease-out;
    opacity: 0;
}

#modal-panel.scale-100, #rejection-modal-panel.scale-100 {
    transform: scale(1);
    opacity: 1;
}

#modal-panel.scale-95, #rejection-modal-panel.scale-95 {
    transform: scale(0.95);
    opacity: 0;
}
</style>
@endsection

