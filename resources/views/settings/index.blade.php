@extends('layouts.app')

@section('title', 'تنظیمات سیستم')

@section('content')
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="w-full px-2 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">تنظیمات سیستم</h1>
                    <p class="mt-1 text-sm text-gray-500">مدیریت تنظیمات و پیکربندی سیستم</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="w-full px-2 py-8">
        <!-- Flash Messages -->
        @if(session('success'))
        <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-center">
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            {{ session('success') }}
        </div>
        @endif

        @if(session('error'))
        <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-center">
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            {{ session('error') }}
        </div>
        @endif

        <!-- Settings Cards -->
        <div class="space-y-6">
            @foreach($settings as $key => $setting)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <!-- Setting Info -->
                        <div class="flex-1">
                            <div class="flex items-center mb-2">
                                <div class="p-2 bg-blue-100 rounded-lg ml-3">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900">{{ $setting['label'] }}</h3>
                                    <p class="text-sm text-gray-500">{{ $setting['description'] }}</p>
                                </div>
                            </div>
                            <div class="mt-3 mr-11">
                                <span class="inline-flex items-center text-xs text-gray-400">
                                    <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                    </svg>
                                    کلید: {{ $setting['name'] }}
                                </span>
                            </div>
                        </div>

                        <!-- Setting Control -->
                        <div class="md:w-72">
                            <form action="{{ route('settings.update') }}" method="POST" class="flex items-center gap-3">
                                @csrf
                                <input type="hidden" name="setting_name" value="{{ $setting['name'] }}">

                                <div class="flex-1">
                                    <select
                                        name="setting_value"
                                        onchange="this.form.submit()"
                                        class="w-full px-4 py-3 text-sm bg-gray-50 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors cursor-pointer"
                                    >
                                        @foreach($setting['options'] as $optionValue => $optionLabel)
                                            <option value="{{ $optionValue }}" {{ $setting['value'] == $optionValue ? 'selected' : '' }}>
                                                {{ $optionLabel }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <!-- Current Value Badge -->
                                <div class="hidden md:block">
                                    @if($setting['value'] == 'code')
                                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            </svg>
                                            کد
                                        </span>
                                    @elseif($setting['value'] == 'image')
                                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            تصویر
                                        </span>
                                    @endif
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Card Footer -->
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-100">
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span class="flex items-center">
                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            تغییرات به صورت خودکار ذخیره می‌شوند
                        </span>
                        <span class="flex items-center">
                            <svg class="w-4 h-4 ml-1 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            فعال
                        </span>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <!-- Info Box -->
        <div class="mt-8 p-6 bg-blue-50 border border-blue-200 rounded-xl">
            <div class="flex items-start">
                <div class="p-2 bg-blue-100 rounded-lg ml-4">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-blue-800 mb-1">راهنمای تنظیمات</h4>
                    <p class="text-sm text-blue-700">
                        با تغییر هر تنظیم، مقدار جدید به صورت خودکار ذخیره می‌شود. تغییرات بلافاصله در سیستم اعمال خواهند شد.
                    </p>
                </div>
            </div>
        </div>
    </main>
</div>
@endsection
