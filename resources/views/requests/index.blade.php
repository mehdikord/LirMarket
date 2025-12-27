@extends('layouts.app')

@section('title', 'درخواست‌ها')

@section('content')
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="w-full px-2 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">درخواست‌ها</h1>
                    <p class="mt-1 text-sm text-gray-500">مدیریت درخواست‌های کاربران</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="w-full px-2 py-8">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <div class="text-center py-12">
                <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-gray-900">صفحه درخواست‌ها</h3>
                <p class="mt-2 text-sm text-gray-500">
                    محتوای صفحه درخواست‌ها در اینجا نمایش داده خواهد شد.
                </p>
            </div>
        </div>
    </main>
</div>
@endsection


