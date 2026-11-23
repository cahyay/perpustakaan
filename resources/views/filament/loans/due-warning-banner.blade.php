@php
    $activeBookings = \App\Models\Loan::with(['anggota', 'book'])
        ->where('status', 'booked')
        ->where('created_at', '>=', now()->subHours(24))
        ->latest()
        ->get();
@endphp

@if ($activeBookings->isNotEmpty())
    <div class="mb-4 rounded-xl border border-sky-300 bg-sky-50 p-4 text-sky-950 shadow-sm dark:border-sky-500/30 dark:bg-sky-950/40 dark:text-sky-100">
        <div class="flex items-center justify-between gap-2 mb-2">
            <div class="flex items-center gap-2 font-bold text-sm text-sky-800 dark:text-sky-300">
                <span>📌 Rekap Booking Buku dari Mahasiswa ({{ $activeBookings->count() }} Buku Menunggu Diambil)</span>
            </div>
            <span class="text-xs text-sky-700 dark:text-sky-400">Otomatis hangus jika tidak diambil dalam 1x24 jam</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
            @foreach ($activeBookings as $item)
                <div class="flex items-center justify-between rounded-lg bg-white/80 px-3 py-2 ring-1 ring-sky-900/10 dark:bg-white/5 dark:ring-white/10">
                    <div>
                        <span class="font-bold text-sky-700 dark:text-sky-300">[{{ $item->booking_code }}]</span>
                        <span class="font-semibold">{{ $item->book?->judul ?? '-' }}</span>
                        <div class="text-gray-600 dark:text-gray-400">
                            Pemesan: {{ $item->anggota?->nama ?? $item->member_nim }} ({{ $item->member_nim }})
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="inline-block rounded bg-sky-100 px-2 py-0.5 font-medium text-sky-800 dark:bg-sky-900/60 dark:text-sky-200">
                            Batas: {{ $item->created_at->copy()->addHours(24)->locale('id')->translatedFormat('d M, H:i') }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif