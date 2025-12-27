@extends('layouts.app')

@section('title', 'داشبورد')

@section('content')
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="w-full px-2 py-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">داشبورد</h1>
                <p class="mt-1 text-sm text-gray-500">خوش آمدید، {{ Auth::user()->name }}</p>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="w-full px-2 py-8">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <div class="text-center py-12">
                <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-gray-900">داشبورد خالی</h3>
                <p class="mt-2 text-sm text-gray-500">
                    محتوای داشبورد در اینجا نمایش داده خواهد شد.
                </p>
            </div>
        </div>
    </main>
</div>
@endsection

