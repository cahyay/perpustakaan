<?php

namespace App\Models;

use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_code',
        'book_id',
        'anggota_id',
        'member_id',
        'member_nim',
        'card_type',
        'tanggal_pinjam',
        'tanggal_kembali',
        'status',
        'is_extended',
        'fine_amount',
        'is_lost',
    ];

    protected $casts = [
        'tanggal_pinjam' => 'date',
        'tanggal_kembali' => 'date',
        'is_extended' => 'boolean',
        'fine_amount' => 'integer',
        'is_lost' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Generate kode booking AKT-XXXX & sinkronkan NIM dari tabel anggotas
        static::creating(function (Loan $loan): void {
            if (empty($loan->booking_code)) {
                do {
                    $code = 'AKT-' . strtoupper(Str::random(4));
                } while (static::where('booking_code', $code)->exists());

                $loan->booking_code = $code;
            }
        });

        static::saving(function (Loan $loan): void {
            if ($loan->anggota_id) {
                $anggota = $loan->anggota ?? Anggota::find($loan->anggota_id);
                if ($anggota && empty($loan->member_nim)) {
                    $loan->member_nim = $anggota->nim_no_anggota;
                }
            }
        });

        // 1. Saat transaksi baru dibuat: stok berkurang HANYA jika langsung dipinjam/terlambat (bukan booked/dibatalkan)
        static::created(function (Loan $loan): void {
            if (in_array($loan->status, ['dipinjam', 'terlambat'])) {
                if ($loan->book && $loan->book->stok > 0) {
                    $loan->book->decrement('stok');
                }
            }
        });

        // 2. Saat status peminjaman diubah atau buku diganti
        static::updating(function (Loan $loan): void {
            $originalStatus = $loan->getOriginal('status');
            $newStatus = $loan->status;

            $wasBorrowed = in_array($originalStatus, ['dipinjam', 'terlambat']);
            $nowReturned = $newStatus === 'dikembalikan';
            $nowBorrowed = in_array($newStatus, ['dipinjam', 'terlambat']);

            // Dari dipinjam -> dikembalikan: tambah stok jika tidak hilang
            if ($wasBorrowed && $nowReturned) {
                if (! $loan->is_lost) {
                    $loan->book?->increment('stok');
                }

                if (empty($loan->tanggal_kembali)) {
                    $loan->tanggal_kembali = now()->toDateString();
                }
            }
            // Dari booked / dikembalikan -> dipinjam: kurangi stok
            elseif (! $wasBorrowed && $nowBorrowed) {
                if (! $loan->getOriginal('is_lost') && $loan->book && $loan->book->stok > 0) {
                    $loan->book->decrement('stok');
                }
            }

            // Jika buku diganti saat transaksi masih berstatus dipinjam
            if ($loan->isDirty('book_id') && $wasBorrowed && $nowBorrowed) {
                $oldBookId = $loan->getOriginal('book_id');
                if ($oldBookId) {
                    Book::find($oldBookId)?->increment('stok');
                }
                if ($loan->book && $loan->book->stok > 0) {
                    $loan->book->decrement('stok');
                }
            }
        });

        // 3. Jika data peminjaman aktif dihapus: kembalikan stok buku jika tidak hilang
        static::deleted(function (Loan $loan): void {
            if (in_array($loan->status, ['dipinjam', 'terlambat']) && ! $loan->is_lost) {
                $loan->book?->increment('stok');
            }
        });
    }

    /**
     * Hitung tanggal jatuh tempo otomatis (hari kerja + tambahan 7 hari kerja jika diperpanjang).
     */
    public function getJatuhTempoAttribute(): ?Carbon
    {
        if (! $this->tanggal_pinjam) {
            return null;
        }

        $baseDuration = $this->book?->getLoanDurationWeekdays() ?? 7;
        $extraDuration = $this->is_extended ? 7 : 0;

        return $this->tanggal_pinjam->copy()->addWeekdays($baseDuration + $extraDuration);
    }

    public function calculateOverdueWeekdays(?Carbon $returnDate = null): int
    {
        if (in_array($this->status, ['booked', 'dibatalkan'])) {
            return 0;
        }

        $due = $this->jatuh_tempo;
        if (! $due) {
            return 0;
        }

        $end = ($returnDate ?? $this->tanggal_kembali ?? today())->copy()->startOfDay();
        $dueStart = $due->copy()->startOfDay();

        if ($end->lte($dueStart)) {
            return 0;
        }

        $overdueWeekdays = 0;
        $current = $dueStart->copy()->addDay();
        while ($current->lte($end)) {
            if ($current->isWeekday()) {
                $overdueWeekdays++;
            }
            $current->addDay();
        }

        return $overdueWeekdays;
    }

    public function calculateLateFine(?Carbon $returnDate = null): int
    {
        $weekdays = $this->calculateOverdueWeekdays($returnDate);
        if ($weekdays <= 0) {
            return 0;
        }

        $rate = ($this->book?->collection_type === 'ta_skripsi') ? 300 : 200;

        return $weekdays * $rate;
    }

    public function calculateLostFine(): int
    {
        if (in_array($this->book?->collection_type, ['ta_skripsi', 'proposal_laporan_pkl'])) {
            return 200000;
        }

        return 0;
    }

    public function isOverdue(): bool
    {
        if (in_array($this->status, ['dikembalikan', 'booked', 'dibatalkan'])) {
            return false;
        }

        if ($this->status === 'terlambat') {
            return true;
        }

        $due = $this->jatuh_tempo;

        return $due ? today()->gt($due->copy()->startOfDay()) : false;
    }

    /**
     * Batas waktu pengambilan booking maksimal 1x24 jam sejak booking dibuat.
     */
    public function getBookingExpiresAtAttribute(): ?Carbon
    {
        if ($this->status !== 'booked' || ! $this->created_at) {
            return null;
        }

        return $this->created_at->copy()->addHours(24);
    }

    /**
     * Cek apakah booking sudah hangus melewati batas 1x24 jam.
     */
    public function isBookingExpired(): bool
    {
        if ($this->status !== 'booked' || ! $this->created_at) {
            return false;
        }

        return now()->gt($this->created_at->copy()->addHours(24));
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }
}