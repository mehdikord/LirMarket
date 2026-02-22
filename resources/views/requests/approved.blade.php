@extends('layouts.app')

@section('title', 'درخواست های تایید شده')

@section('content')
<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="w-full px-2 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">درخواست های تایید شده</h1>
                    <p class="mt-1 text-sm text-gray-500">مدیریت درخواست‌های تایید شده کاربران</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="w-full px-2 py-8">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">ردیف</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">کاربر</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">از</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">به</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">مبلغ</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">نام حساب</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">کارت / شبا</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">فاکتور</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">تاریخ</th>
                            <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-700 uppercase tracking-wider">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($requests as $req)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-center">
                                {{ $requests->firstItem() + $loop->index }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700 text-center">
                                <div>{{ $req->member->name ?? '-' }}</div>
                                @if($req->member && $req->member->telegram_username)
                                    <div class="text-gray-500 text-xs">{{ '@' . $req->member->telegram_username }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 text-center">
                                {{ $req->from === 'lira' ? 'لیر 🇹🇷' : ($req->from === 'rials' ? 'ریال 🇮🇷' : $req->from) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 text-center">
                                {{ $req->to === 'lira' ? 'لیر 🇹🇷' : ($req->to === 'rials' ? 'ریال 🇮🇷' : $req->to) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                <span class="font-bold text-blue-600">{{ number_format((float) $req->amount) }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 text-center">
                                {{ $req->recieve_name ?? '-' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 text-center">
                                {{ $req->receive_code ?? '-' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                @if(!empty($req->file_url))
                                    <button type="button" onclick="openInvoiceModal(this.dataset.url)" data-url="{{ $req->file_url }}" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-blue-100 text-blue-800 hover:bg-blue-200 transition-colors cursor-pointer">
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        مشاهده فاکتور
                                    </button>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center">
                                {{ $req->created_at?->format('Y/m/d H:i') ?? '-' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                <button type="button" class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors">
                                    <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                    </svg>
                                    پرینت
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center text-gray-500">
                                درخواست تایید شده‌ای وجود ندارد.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($requests->hasPages())
            <div class="px-4 py-3 border-t border-gray-200 bg-gray-50">
                {{ $requests->links() }}
            </div>
            @endif
        </div>
    </main>
</div>

<!-- مودال نمایش تصویر فاکتور -->
<div id="invoice-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="fixed inset-0 bg-black bg-opacity-60" onclick="closeInvoiceModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-4xl max-h-[90vh] w-full flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">تصویر فاکتور</h3>
                <button type="button" onclick="closeInvoiceModal()" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-4 overflow-auto flex-1 flex items-center justify-center min-h-0 bg-gray-100 rounded-b-xl">
                <img id="invoice-modal-img" src="" alt="فاکتور" class="max-w-full max-h-[70vh] w-auto h-auto object-contain rounded-lg shadow-lg">
            </div>
        </div>
    </div>
</div>

<script>
function openInvoiceModal(url) {
    document.getElementById('invoice-modal-img').src = url;
    document.getElementById('invoice-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeInvoiceModal() {
    document.getElementById('invoice-modal').classList.add('hidden');
    document.getElementById('invoice-modal-img').src = '';
    document.body.style.overflow = '';
}
document.getElementById('invoice-modal').addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeInvoiceModal();
});
</script>
@endsection
