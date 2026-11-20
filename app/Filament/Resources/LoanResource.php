<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Models\Anggota;
use App\Models\Book;
use App\Models\Loan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Peminjaman & Pengembalian';

    protected static ?string $modelLabel = 'Peminjaman';

    protected static ?string $pluralModelLabel = 'Peminjaman & Pengembalian Buku';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::whereIn('status', ['booked', 'dipinjam', 'terlambat'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $hasOverdue = static::getModel()::with('book')
            ->whereIn('status', ['dipinjam', 'terlambat'])
            ->where('is_lost', false)
            ->get()
            ->contains(fn (Loan $loan): bool => $loan->isOverdue());

        return $hasOverdue ? 'danger' : 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        // 1. Otomatis batalkan booking dari web mahasiswa yang tidak diambil melebihi 1x24 jam
        static::getModel()::where('status', 'booked')
            ->where('created_at', '<', now()->subHours(24))
            ->update(['status' => 'dibatalkan']);

        // 2. Sinkronisasi otomatis: ubah status 'dipinjam' menjadi 'terlambat' di database jika sudah melewati jatuh tempo
        static::getModel()::with('book')
            ->where('status', 'dipinjam')
            ->where('is_lost', false)
            ->get()
            ->each(function (Loan $loan): void {
                if ($loan->isOverdue()) {
                    $loan->updateQuietly(['status' => 'terlambat']);
                }
            });

        return parent::getEloquentQuery();
    }

    protected static function getBookCategoryLabel(?Book $book): string
    {
        if (! $book) {
            return '-';
        }

        $categoryName = $book->category?->nama
            ?? $book->category?->name
            ?? $book->category?->judul;

        return filled($categoryName) ? $categoryName : $book->collection_type_label;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Section Pencarian Cepat Kode Booking dari UI User
                Forms\Components\Section::make('Cek Kode Booking Mahasiswa (Dari Web Katalog)')
                    ->description('Pilih atau ketik kode booking (AKT-XXXX) yang ditunjukkan mahasiswa (berlaku maksimal 1x24 jam sejak pemesanan) agar seluruh data terisi otomatis.')
                    ->schema([
                        Forms\Components\Select::make('lookup_booking_code')
                            ->label('Cari Kode Booking (AKT-XXXX)')
                            ->placeholder('Ketik kode booking, contoh: AKT-8X2P...')
                            ->options(function (): array {
                                return Loan::with(['anggota', 'book'])
                                    ->where('status', 'booked')
                                    ->where('created_at', '>=', now()->subHours(24))
                                    ->whereNotNull('booking_code')
                                    ->latest()
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(function (Loan $loan): array {
                                        $nama = $loan->anggota?->nama ?? $loan->member_nim;
                                        $buku = $loan->book?->judul ?? '-';
                                        $batas = $loan->booking_expires_at?->locale('id')->translatedFormat('d M H:i') ?? '-';

                                        return [$loan->booking_code => "{$loan->booking_code} — {$nama} ({$buku}) [Batas Ambil: {$batas}]"];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->visible(fn (?Loan $record): bool => $record === null)
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                                if (! $state) {
                                    return;
                                }

                                $booking = Loan::where('booking_code', trim($state))->first();
                                if (! $booking) {
                                    return;
                                }

                                $set('anggota_id', $booking->anggota_id);
                                $set('member_nim', $booking->member_nim);
                                $set('card_type', $booking->card_type ?? 'KTM');
                                $set('book_id', $booking->book_id);
                                $set('tanggal_pinjam', today()->toDateString());
                                $set('status', 'dipinjam');
                            }),

                        Forms\Components\Placeholder::make('info_booking_ditemukan')
                            ->label('Status Kode Booking')
                            ->visible(fn (Forms\Get $get, ?Loan $record): bool => $record === null && filled($get('lookup_booking_code')))
                            ->content(function (Forms\Get $get): HtmlString {
                                $code = $get('lookup_booking_code');
                                $booking = Loan::where('booking_code', $code)->first();

                                if (! $booking) {
                                    return new HtmlString('<span class="text-xs text-danger-600">Kode booking tidak ditemukan.</span>');
                                }

                                $editUrl = static::getUrl('edit', ['record' => $booking]);

                                return new HtmlString("
                                    <div class='p-3 rounded-xl bg-info-50 dark:bg-info-950/50 ring-1 ring-info-600/20 text-xs text-info-900 dark:text-info-200 space-y-1.5'>
                                        <div><strong>Data booking {$code} berhasil dimuat otomatis!</strong></div>
                                        <div>Untuk memproses langsung data booking yang sudah tersimpan ini tanpa membuat duplikat, <a href='{$editUrl}' class='underline font-bold text-primary-600 dark:text-primary-400'>klik di sini untuk membuka & konfirmasi transaksi {$code}</a>.</div>
                                    </div>
                                ");
                            }),
                    ])
                    ->columns(2)
                    ->visible(fn (?Loan $record): bool => $record === null),

                Forms\Components\Section::make('Identitas Peminjam (Anggota & Kartu)')
                    ->description('Cari anggota berdasarkan Nama atau NIM dengan kartu identitas resmi. Maksimal 2 buku aktif per NIM.')
                    ->schema([
                        Forms\Components\Select::make('anggota_id')
                            ->label('Cari Berdasarkan Nama Anggota')
                            ->relationship('anggota', 'nama')
                            ->searchable(['nama', 'nim_no_anggota'])
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                                if ($state) {
                                    $anggota = Anggota::find($state);
                                    $set('member_nim', $anggota?->nim_no_anggota);
                                } else {
                                    $set('member_nim', null);
                                }
                            })
                            ->required(),

                        Forms\Components\Select::make('member_nim')
                            ->label('Cari Berdasarkan NIM / No. Anggota')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Anggota::query()
                                ->whereNotNull('nim_no_anggota')
                                ->where(fn ($q) => $q->where('nim_no_anggota', 'like', "%{$search}%")->orWhere('nama', 'like', "%{$search}%"))
                                ->orderBy('nim_no_anggota')
                                ->limit(30)
                                ->pluck('nim_no_anggota', 'nim_no_anggota')
                                ->toArray())
                            ->getOptionLabelUsing(fn ($value): ?string => $value)
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                                if ($state) {
                                    $anggota = Anggota::where('nim_no_anggota', trim($state))->first();
                                    $set('anggota_id', $anggota?->id);
                                } else {
                                    $set('anggota_id', null);
                                }
                            })
                            ->required()
                            ->rules([
                                fn (?Loan $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record) {
                                    if (! $value) {
                                        return;
                                    }
                                    $activeCount = Loan::where('member_nim', trim($value))
                                        ->where(function ($q): void {
                                            $q->whereIn('status', ['dipinjam', 'terlambat'])
                                                ->orWhere(function ($sub): void {
                                                    $sub->where('status', 'booked')
                                                        ->where('created_at', '>=', now()->subHours(24));
                                                });
                                        })
                                        ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                        ->count();

                                    if ($activeCount >= 2) {
                                        $fail("Mahasiswa dengan NIM {$value} sudah memiliki {$activeCount} buku aktif/booking. SOP perpustakaan: maksimal 2 buku per kartu.");
                                    }
                                },
                            ]),

                        Forms\Components\Select::make('card_type')
                            ->label('Jenis Kartu Identitas yang Dijaminkan')
                            ->options([
                                'KTM' => 'KTM (Kartu Tanda Mahasiswa)',
                                'Kartu Perpustakaan' => 'Kartu Perpustakaan',
                                'Kartu Asuransi' => 'Kartu Asuransi',
                            ])
                            ->default('KTM')
                            ->required(),

                        Forms\Components\Placeholder::make('detail_anggota_terpilih')
                            ->label('Detail Informasi Anggota')
                            ->columnSpanFull()
                            ->content(function (Forms\Get $get): HtmlString {
                                $anggotaId = $get('anggota_id');
                                if (! $anggotaId) {
                                    return new HtmlString('<span class="text-xs text-gray-500 dark:text-gray-400 italic">Pilih nama, NIM, atau kode booking terlebih dahulu untuk menampilkan detail lengkap.</span>');
                                }

                                $anggota = Anggota::find($anggotaId);
                                if (! $anggota) {
                                    return new HtmlString('<span class="text-xs text-danger-600 dark:text-danger-400">Data anggota tidak ditemukan.</span>');
                                }

                                $nama = e($anggota->nama ?: '-');
                                $nim = e($anggota->nim_no_anggota ?: '-');
                                $kelas = e($anggota->kelas ?: '-');
                                $prodi = e($anggota->program_studi ?: '-');
                                $angkatan = e($anggota->tahun_angkatan ?: '-');
                                $noHp = e($anggota->no_hp ?: '-');
                                $email = e($anggota->email ?: '-');
                                $alamat = e($anggota->alamat ?: '-');

                                return new HtmlString("
                                    <div style='display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 24px;' class='p-4 rounded-xl bg-gray-50 dark:bg-white/5 ring-1 ring-gray-950/10 dark:ring-white/10 text-sm'>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>Nama Lengkap</div>
                                            <div class='font-semibold text-gray-950 dark:text-white'>{$nama}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>NIM / No. Anggota</div>
                                            <div class='font-semibold text-gray-950 dark:text-white'>{$nim}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>Kelas</div>
                                            <div class='font-semibold text-gray-950 dark:text-white'>{$kelas}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>Program Studi</div>
                                            <div class='font-semibold text-gray-950 dark:text-white'>{$prodi}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>Tahun Angkatan</div>
                                            <div class='font-semibold text-gray-950 dark:text-white'>{$angkatan}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>No. HP / WhatsApp</div>
                                            <div class='font-semibold text-gray-950 dark:text-white'>{$noHp}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>Email</div>
                                            <div class='font-semibold text-gray-950 dark:text-white break-all'>{$email}</div>
                                        </div>
                                        <div>
                                            <div class='text-xs text-gray-500 dark:text-gray-400'>Alamat</div>
                                            <div class='font-semibold text-gray-950 dark:text-white'>{$alamat}</div>
                                        </div>
                                    </div>
                                ");
                            }),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Detail Buku, Kode Booking & Masa Peminjaman')
                    ->description('Batas waktu otomatis dihitung hari kerja (Senin - Jumat): TA/Skripsi 14 hari kerja, Non-TA & PKL 7 hari kerja.')
                    ->schema([
                        Forms\Components\TextInput::make('booking_code')
                            ->label('Kode Booking')
                            ->placeholder('Otomatis (AKT-XXXX)')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?Loan $record): bool => (bool) $record?->booking_code),

                        Forms\Components\Select::make('book_id')
                            ->label('Buku yang Dipinjam')
                            ->relationship('book', 'judul', fn ($query) => $query->with('category')->orderBy('judul'))
                            ->getOptionLabelFromRecordUsing(function (Book $record): string {
                                $kode = $record->book_code ? "[{$record->book_code}] " : '';
                                $kategori = static::getBookCategoryLabel($record);

                                return "{$kode}{$record->judul} ({$kategori} • Sisa Stok: {$record->stok})";
                            })
                            ->searchable(['judul', 'isbn', 'book_code'])
                            ->preload()
                            ->live()
                            ->required()
                            ->rules([
                                fn (?Loan $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record) {
                                    if (! $value) {
                                        return;
                                    }
                                    $book = Book::find($value);
                                    if (! $book) {
                                        $fail('Buku tidak ditemukan.');

                                        return;
                                    }
                                    $isNew = ! $record;
                                    $bookChanged = $record && (int) $record->book_id !== (int) $value;
                                    if (($isNew || $bookChanged) && $book->stok <= 0) {
                                        $fail("Stok koleksi '{$book->judul}' sedang kosong (0) dan tidak dapat dipinjam.");
                                    }
                                },
                            ]),

                        Forms\Components\DatePicker::make('tanggal_pinjam')
                            ->label('Tanggal Pinjam')
                            ->default(today())
                            ->native(false)
                            ->live()
                            ->required(),

                        Forms\Components\Placeholder::make('info_jatuh_tempo')
                            ->label('Perhitungan Jatuh Tempo (Hari Kerja)')
                            ->content(function (Forms\Get $get): HtmlString {
                                $bookId = $get('book_id');
                                $tglPinjam = $get('tanggal_pinjam') ? Carbon::parse($get('tanggal_pinjam')) : today();
                                $isExtended = (bool) $get('is_extended');

                                if (! $bookId) {
                                    return new HtmlString('<span class="text-xs text-gray-500 dark:text-gray-400 italic">Pilih buku terlebih dahulu untuk melihat tenggat waktu.</span>');
                                }

                                $book = Book::with('category')->find($bookId);
                                $baseDays = ($book?->collection_type === 'ta_skripsi') ? 14 : 7;
                                $totalDays = $baseDays + ($isExtended ? 7 : 0);
                                $jatuhTempo = $tglPinjam->copy()->addWeekdays($totalDays);
                                $kategori = static::getBookCategoryLabel($book);

                                return new HtmlString("
                                    <div class='text-sm text-primary-600 dark:text-primary-400 font-medium'>
                                        Tenggat: <strong>{$jatuhTempo->locale('id')->translatedFormat('l, d F Y')}</strong>
                                        <div class='text-xs text-gray-500 dark:text-gray-400 font-normal'>Durasi: {$totalDays} hari kerja " . ($isExtended ? '(Termasuk perpanjangan +7 hari)' : '') . " &bull; Kategori: {$kategori}</div>
                                    </div>
                                ");
                            }),

                        Forms\Components\ToggleButtons::make('status')
                            ->label('Status Transaksi Peminjaman')
                            ->columnSpanFull()
                            ->inline()
                            ->options(fn (?Loan $record): array => $record === null
                                ? [
                                    'dipinjam' => 'Sudah Diambil (Dipinjam)',
                                ]
                                : [
                                    'booked' => 'Belum Diambil (Booking)',
                                    'dipinjam' => 'Sudah Diambil (Dipinjam)',
                                    'dikembalikan' => 'Dikembalikan',
                                    'terlambat' => 'Terlambat',
                                    'dibatalkan' => 'Dibatalkan',
                                ])
                            ->colors([
                                'booked' => 'info',
                                'dipinjam' => 'warning',
                                'dikembalikan' => 'success',
                                'terlambat' => 'danger',
                                'dibatalkan' => 'gray',
                            ])
                            ->icons([
                                'booked' => 'heroicon-m-ticket',
                                'dipinjam' => 'heroicon-m-check-badge',
                                'dikembalikan' => 'heroicon-m-check-circle',
                                'terlambat' => 'heroicon-m-exclamation-triangle',
                                'dibatalkan' => 'heroicon-m-x-circle',
                            ])
                            ->default('dipinjam')
                            ->required(),

                        Forms\Components\DatePicker::make('tanggal_kembali')
                            ->label('Tanggal Pengembalian (Aktual)')
                            ->helperText('Terisi otomatis tanggal hari ini ketika tombol "Kembalikan / Selesaikan" ditekan.')
                            ->nullable()
                            ->native(false)
                            ->visible(fn (?Loan $record): bool => $record !== null),

                        Forms\Components\TextInput::make('fine_amount')
                            ->label('Nominal Denda (Rp)')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0)
                            ->visible(fn (?Loan $record): bool => $record !== null),

                        Forms\Components\Toggle::make('is_extended')
                            ->label('Status Diperpanjang (+7 Hari Kerja)')
                            ->live()
                            ->helperText('Menambah masa pinjam 7 hari kerja dari tenggat awal.'),

                        Forms\Components\Toggle::make('is_lost')
                            ->label('Status Buku Hilang')
                            ->helperText('Aktifkan jika buku hilang (stok tidak akan dikembalikan ke rak).')
                            ->visible(fn (?Loan $record): bool => $record !== null),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['anggota', 'book.category']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('booking_code')
                    ->label('Kode Booking')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode booking disalin')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('anggota.nama')
                    ->label('Peminjam (NIM, Kelas & No. HP)')
                    ->description(function (Loan $record): string {
                        $nim = $record->member_nim ?? $record->anggota?->nim_no_anggota ?? '-';
                        $kelas = $record->anggota?->kelas ?? '-';
                        $noHp = $record->anggota?->no_hp ?? '-';
                        $kartu = $record->card_type ?? 'KTM';

                        return "NIM: {$nim} • Kelas: {$kelas} • HP: {$noHp} ({$kartu})";
                    })
                    ->searchable(['anggotas.nama', 'member_nim', 'anggotas.kelas', 'anggotas.no_hp'])
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('book.judul')
                    ->label('Koleksi Buku')
                    ->description(function (Loan $record): string {
                        $book = $record->book;
                        $kode = $book?->book_code ? "[{$book->book_code}] " : '';
                        $kategori = static::getBookCategoryLabel($book);

                        return $kode . $kategori;
                    })
                    ->weight('bold')
                    ->searchable(['books.judul', 'books.book_code'])
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('tanggal_pinjam')
                    ->label('Tgl Pinjam')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('jatuh_tempo')
                    ->label('Jatuh Tempo / Batas Ambil')
                    ->state(function (Loan $record): ?string {
                        if ($record->status === 'booked') {
                            return $record->booking_expires_at
                                ? 'Ambil s/d ' . $record->booking_expires_at->locale('id')->translatedFormat('d M, H:i')
                                : 'Menunggu Diambil (1x24 Jam)';
                        }
                        if ($record->status === 'dibatalkan') {
                            return 'Dibatalkan';
                        }

                        return $record->jatuh_tempo?->locale('id')->translatedFormat('d M Y');
                    })
                    ->description(function (Loan $record): ?string {
                        if ($record->status === 'booked' && $record->booking_expires_at) {
                            if ($record->isBookingExpired()) {
                                return 'Hangus (Lewat 24 jam)';
                            }

                            return 'Batas ambil maksimal 1x24 jam';
                        }

                        if (in_array($record->status, ['dikembalikan', 'booked', 'dibatalkan']) || ! $record->jatuh_tempo) {
                            return null;
                        }

                        $extLabel = $record->is_extended ? ' (Diperpanjang)' : '';
                        $overdueDays = $record->calculateOverdueWeekdays(today());
                        if ($record->isOverdue()) {
                            return $overdueDays > 0
                                ? "Lewat {$overdueDays} hari kerja{$extLabel}"
                                : "Melewati batas waktu{$extLabel}";
                        }
                        if (today()->equalTo($record->jatuh_tempo->copy()->startOfDay())) {
                            return "Hari ini jatuh tempo{$extLabel}";
                        }

                        return 'Sisa ' . today()->diffInDays($record->jatuh_tempo->copy()->startOfDay()) . " hari{$extLabel}";
                    })
                    ->color(fn (Loan $record): ?string => ($record->isOverdue() || $record->isBookingExpired()) ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function (string $state, Loan $record): string {
                        if ($record->is_lost) {
                            return 'Buku Hilang';
                        }
                        if ($state === 'booked') {
                            return 'Belum Diambil (Booking)';
                        }
                        if ($state === 'dibatalkan') {
                            return 'Dibatalkan';
                        }
                        if ($state === 'dikembalikan') {
                            return 'Dikembalikan';
                        }
                        if ($record->isOverdue() || $state === 'terlambat') {
                            return 'Terlambat';
                        }

                        return 'Sudah Diambil (Dipinjam)';
                    })
                    ->color(function (string $state, Loan $record): string {
                        if ($record->is_lost) {
                            return 'danger';
                        }
                        if ($state === 'booked') {
                            return 'info';
                        }
                        if ($state === 'dibatalkan') {
                            return 'gray';
                        }
                        if ($state === 'dikembalikan') {
                            return 'success';
                        }
                        if ($record->isOverdue() || $state === 'terlambat') {
                            return 'danger';
                        }

                        return 'warning';
                    })
                    ->icon(function (string $state, Loan $record): string {
                        if ($record->is_lost) {
                            return 'heroicon-m-x-circle';
                        }
                        if ($state === 'booked') {
                            return 'heroicon-m-ticket';
                        }
                        if ($state === 'dibatalkan') {
                            return 'heroicon-m-no-symbol';
                        }
                        if ($state === 'dikembalikan') {
                            return 'heroicon-m-check-circle';
                        }
                        if ($record->isOverdue() || $state === 'terlambat') {
                            return 'heroicon-m-exclamation-triangle';
                        }

                        return 'heroicon-m-clock';
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('fine_amount')
                    ->label('Denda')
                    ->money('IDR', locale: 'id')
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Filter Status')
                    ->options([
                        'booked' => 'Belum Diambil (Booking)',
                        'dipinjam' => 'Sudah Diambil (Dipinjam)',
                        'dikembalikan' => 'Dikembalikan',
                        'terlambat' => 'Terlambat',
                        'dibatalkan' => 'Dibatalkan',
                    ]),

                Tables\Filters\SelectFilter::make('peringatan_wa')
                    ->label('Filter Pengingat WA')
                    ->options([
                        'jatuh_tempo_hari_ini' => 'Jatuh Tempo Hari Ini (Hari-H)',
                        'sudah_terlambat' => 'Sudah Lewat Jatuh Tempo (Terlambat)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        $activeIds = Loan::with('book')
                            ->whereIn('status', ['dipinjam', 'terlambat'])
                            ->where('is_lost', false)
                            ->get()
                            ->filter(function (Loan $loan) use ($value): bool {
                                if (! $loan->jatuh_tempo) {
                                    return false;
                                }

                                return match ($value) {
                                    'jatuh_tempo_hari_ini' => $loan->jatuh_tempo->copy()->startOfDay()->isToday(),
                                    'sudah_terlambat' => $loan->isOverdue(),
                                    default => false,
                                };
                            })
                            ->pluck('id');

                        return $query->whereIn('id', $activeIds);
                    }),

                Tables\Filters\SelectFilter::make('periode_denda')
                    ->label('Laporan Keuangan Denda')
                    ->options([
                        'hari_ini' => 'Denda Hari Ini (' . now()->format('d M Y') . ')',
                        'minggu_ini' => 'Denda Minggu Ini',
                        'bulan_ini' => 'Denda Bulan Ini (' . now()->locale('id')->translatedFormat('F Y') . ')',
                        'tahun_ini' => 'Denda Tahun Ini (' . now()->year . ')',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'hari_ini' => $query->where('fine_amount', '>', 0)
                                ->whereDate('tanggal_kembali', today()),
                            'minggu_ini' => $query->where('fine_amount', '>', 0)
                                ->whereBetween('tanggal_kembali', [now()->startOfWeek(), now()->endOfWeek()]),
                            'bulan_ini' => $query->where('fine_amount', '>', 0)
                                ->whereMonth('tanggal_kembali', now()->month)
                                ->whereYear('tanggal_kembali', now()->year),
                            'tahun_ini' => $query->where('fine_amount', '>', 0)
                                ->whereYear('tanggal_kembali', now()->year),
                            default => $query,
                        };
                    }),

                Tables\Filters\TernaryFilter::make('is_lost')
                    ->label('Status Kehilangan')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya Buku Hilang')
                    ->falseLabel('Buku Tidak Hilang'),
            ])
            ->actions([
                // 1. Tombol Konfirmasi Ambil (Untuk Kode Booking AKT-XXXX dari Web)
                Tables\Actions\Action::make('konfirmasiAmbil')
                    ->label('Konfirmasi Ambil')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->visible(fn (Loan $record): bool => $record->status === 'booked' && ! $record->isBookingExpired())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Loan $record): string => "Konfirmasi Pengambilan ({$record->booking_code})")
                    ->modalDescription(fn (Loan $record): string => "Pastikan kartu identitas ({$record->card_type}) milik {$record->anggota?->nama} (NIM: {$record->member_nim} • Kelas: " . ($record->anggota?->kelas ?? '-') . ") sudah diserahkan. Stok buku akan otomatis berkurang 1.")
                    ->modalSubmitActionLabel('Ya, Sudah Diambil')
                    ->action(function (Loan $record): void {
                        if (($record->book?->stok ?? 0) <= 0) {
                            Notification::make()
                                ->title('Stok Buku Habis')
                                ->body('Tidak dapat memproses pengambilan karena stok buku saat ini 0.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update([
                            'status' => 'dipinjam',
                            'tanggal_pinjam' => today()->toDateString(),
                        ]);

                        Notification::make()
                            ->title('Buku Berhasil Diserahkan')
                            ->body("Kode {$record->booking_code} statusnya berubah menjadi Sudah Diambil (Dipinjam). Jatuh tempo: {$record->jatuh_tempo?->locale('id')->translatedFormat('d M Y')}.")
                            ->success()
                            ->send();
                    }),

                // 2. Tombol Batalkan Booking (Jika Mahasiswa Tidak Jadi Mengambil Buku)
                Tables\Actions\Action::make('batalkanBooking')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Loan $record): bool => $record->status === 'booked')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Loan $record): string => "Batalkan Booking ({$record->booking_code})?")
                    ->modalDescription(fn (Loan $record): string => "Booking buku '{$record->book?->judul}' atas nama {$record->anggota?->nama} (NIM: {$record->member_nim}) akan dibatalkan dan stok buku tetap utuh.")
                    ->modalSubmitActionLabel('Ya, Batalkan Booking')
                    ->action(function (Loan $record): void {
                        $record->update([
                            'status' => 'dibatalkan',
                        ]);

                        Notification::make()
                            ->title('Booking Dibatalkan')
                            ->body("Kode booking {$record->booking_code} telah dibatalkan.")
                            ->warning()
                            ->send();
                    }),

                // 3. Tombol Notifikasi WhatsApp (Rapi per baris & akurat cek Hari-H / Terlambat)
                Tables\Actions\Action::make('ingatkanWa')
                    ->label(function (Loan $record): string {
                        $jatuhTempo = $record->jatuh_tempo?->copy()->startOfDay();
                        $isTerlambat = $jatuhTempo && today()->gt($jatuhTempo) && $record->calculateOverdueWeekdays(today()) > 0;

                        if ($isTerlambat) {
                            return 'WA Terlambat';
                        }
                        if ($jatuhTempo && $jatuhTempo->isToday()) {
                            return 'WA Hari-H';
                        }

                        return 'Ingatkan WA';
                    })
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color(function (Loan $record): string {
                        $jatuhTempo = $record->jatuh_tempo?->copy()->startOfDay();
                        $isTerlambat = $jatuhTempo && today()->gt($jatuhTempo) && $record->calculateOverdueWeekdays(today()) > 0;

                        if ($isTerlambat) {
                            return 'danger';
                        }
                        if ($jatuhTempo && $jatuhTempo->isToday()) {
                            return 'warning';
                        }

                        return 'success';
                    })
                    ->visible(fn (Loan $record): bool => in_array($record->status, ['dipinjam', 'terlambat']) && ! $record->is_lost && filled($record->anggota?->no_hp))
                    ->url(function (Loan $record): string {
                        $phone = preg_replace('/[^0-9]/', '', $record->anggota->no_hp);
                        if (str_starts_with($phone, '0')) {
                            $phone = '62' . substr($phone, 1);
                        }

                        $nama = $record->anggota->nama;
                        $kodeBooking = $record->booking_code ?? '-';
                        $judulBuku = $record->book?->judul ?? 'Buku Perpustakaan';
                        $jatuhTempoCarbon = $record->jatuh_tempo?->copy()->startOfDay();
                        $jatuhTempoTeks = $jatuhTempoCarbon
                            ? $jatuhTempoCarbon->locale('id')->translatedFormat('l, d F Y')
                            : '-';

                        $hariTelat = $jatuhTempoCarbon ? $record->calculateOverdueWeekdays(today()) : 0;
                        $isTerlambat = $jatuhTempoCarbon && today()->gt($jatuhTempoCarbon) && $hariTelat > 0;
                        $isHariH = $jatuhTempoCarbon && $jatuhTempoCarbon->isToday();

                        if ($isTerlambat) {
                            $estimasiDenda = number_format($record->calculateLateFine(today()), 0, ',', '.');
                            $statusBaris = "*TERLAMBAT ({$hariTelat} hari kerja)*";
                            $catatan = "Estimasi denda sementara: *Rp {$estimasiDenda}*.\nMohon segera mengembalikan buku ke perpustakaan agar denda tidak terus bertambah.";
                        } elseif ($isHariH) {
                            $statusBaris = "*JATUH TEMPO HARI INI*";
                            $catatan = "Batas pengembalian berakhir *hari ini* sebelum jam tutup layanan. Mohon segera dikembalikan agar tidak terkena denda keterlambatan.";
                        } else {
                            $sisaHari = $jatuhTempoCarbon ? today()->diffInDays($jatuhTempoCarbon) : 0;
                            $statusBaris = "Aktif (Sisa {$sisaHari} hari)";
                            $catatan = "Mohon mengembalikan buku tepat waktu sebelum tanggal jatuh tempo.";
                        }

                        $pesan = "Halo *{$nama}*,\n\n"
                            . "Pengingat Peminjaman Buku — *Perpustakaan Jurusan Akuntansi*\n\n"
                            . "• Kode Booking : *{$kodeBooking}*\n"
                            . "• Judul Buku : *{$judulBuku}*\n"
                            . "• Jatuh Tempo : *{$jatuhTempoTeks}*\n"
                            . "• Status : {$statusBaris}\n\n"
                            . "{$catatan}\n\n"
                            . "Terima kasih.";

                        return 'https://wa.me/' . $phone . '?text=' . urlencode($pesan);
                    })
                    ->openUrlInNewTab(),

                // 4. Tombol Perpanjang (+7 Hari Kerja)
                Tables\Actions\Action::make('perpanjangPinjam')
                    ->label('Perpanjang')
                    ->icon('heroicon-o-calendar-days')
                    ->color('warning')
                    ->visible(fn (Loan $record): bool => $record->status === 'dipinjam' && ! $record->isOverdue() && ! $record->is_extended && ! $record->is_lost)
                    ->requiresConfirmation()
                    ->modalHeading('Perpanjang Masa Peminjaman (+7 Hari Kerja)')
                    ->modalDescription(fn (Loan $record): string => "Perpanjang peminjaman buku '{$record->book?->judul}' untuk {$record->anggota?->nama} selama 7 hari kerja tambahan?")
                    ->modalSubmitActionLabel('Perpanjang Sekarang')
                    ->action(function (Loan $record): void {
                        $record->update([
                            'is_extended' => true,
                        ]);

                        Notification::make()
                            ->title('Masa Pinjam Diperpanjang')
                            ->body("Tenggat waktu baru: {$record->fresh()->jatuh_tempo?->locale('id')->translatedFormat('l, d F Y')}.")
                            ->success()
                            ->send();
                    }),

                // 5. Tombol Kembalikan / Selesaikan
                Tables\Actions\Action::make('kembalikanBuku')
                    ->label('Kembalikan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (Loan $record): bool => in_array($record->status, ['dipinjam', 'terlambat']) && ! $record->is_lost)
                    ->form([
                        Forms\Components\Placeholder::make('detail_transaksi')
                            ->label('Detail Peminjaman')
                            ->content(function (Loan $record): HtmlString {
                                $book = $record->book;
                                $due = $record->jatuh_tempo;
                                $overdueDays = $record->calculateOverdueWeekdays(today());
                                $lateFine = $record->calculateLateFine(today());
                                $kelas = e($record->anggota?->kelas ?? '-');
                                $noHp = e($record->anggota?->no_hp ?? '-');
                                $kategori = e(static::getBookCategoryLabel($book));

                                $overdueHtml = $overdueDays > 0
                                    ? "<span class='text-danger-600 dark:text-danger-400 font-bold'>Terlambat {$overdueDays} hari kerja (Denda: Rp " . number_format($lateFine, 0, ',', '.') . ')</span>'
                                    : "<span class='text-success-600 dark:text-success-400 font-semibold'>Tepat Waktu (Tidak ada denda terlambat)</span>";

                                return new HtmlString("
                                    <div class='p-3.5 bg-gray-50 dark:bg-white/5 rounded-xl text-sm space-y-1.5 ring-1 ring-gray-950/10 dark:ring-white/10 text-gray-900 dark:text-gray-100'>
                                        <div><strong class='text-gray-600 dark:text-gray-400'>Kode Booking:</strong> {$record->booking_code}</div>
                                        <div><strong class='text-gray-600 dark:text-gray-400'>Peminjam:</strong> {$record->anggota?->nama} (NIM: {$record->member_nim})</div>
                                        <div><strong class='text-gray-600 dark:text-gray-400'>Kelas & No. HP:</strong> {$kelas} &bull; {$noHp}</div>
                                        <div><strong class='text-gray-600 dark:text-gray-400'>Jaminan Kartu:</strong> {$record->card_type}</div>
                                        <div><strong class='text-gray-600 dark:text-gray-400'>Koleksi:</strong> {$book?->judul} <span class='text-xs px-2 py-0.5 rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300 ring-1 ring-primary-600/20'>{$kategori}</span></div>
                                        <div><strong class='text-gray-600 dark:text-gray-400'>Tgl Pinjam:</strong> " . ($record->tanggal_pinjam?->format('d M Y') ?? '-') . " &bull; <strong class='text-gray-600 dark:text-gray-400'>Jatuh Tempo:</strong> " . ($due?->format('d M Y') ?? '-') . "</div>
                                        <div><strong class='text-gray-600 dark:text-gray-400'>Status Keterlambatan:</strong> {$overdueHtml}</div>
                                    </div>
                                ");
                            }),

                        Forms\Components\Checkbox::make('is_lost')
                            ->label('Buku Hilang / Dihilangkan oleh Peminjam')
                            ->helperText('Jika dicentang, denda kehilangan akan diberlakukan dan stok buku TIDAK akan bertambah ke rak.')
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, bool $state, Loan $record) {
                                $lateFine = $record->calculateLateFine(today());
                                if ($state) {
                                    $lostFine = $record->calculateLostFine();
                                    $set('fine_amount', $lostFine > 0 ? ($lostFine + $lateFine) : $lateFine);
                                } else {
                                    $set('fine_amount', $lateFine);
                                }
                            }),

                        Forms\Components\TextInput::make('fine_amount')
                            ->label('Total Nominal Denda (Rp)')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(fn (Loan $record): int => $record->calculateLateFine(today()))
                            ->helperText(function (Forms\Get $get, Loan $record): string {
                                if ($get('is_lost')) {
                                    if (in_array($record->book?->collection_type, ['ta_skripsi', 'proposal_laporan_pkl'])) {
                                        return 'Denda kehilangan TA/Skripsi/PKL ditetapkan flat Rp 200.000.';
                                    }

                                    return 'Buku Umum: Peminjam wajib mengganti buku yang sama persis atau membayar nominal seharga buku.';
                                }
                                $days = $record->calculateOverdueWeekdays(today());
                                $rate = ($record->book?->collection_type === 'ta_skripsi') ? 300 : 200;

                                return "Denda keterlambatan: {$days} hari kerja x Rp {$rate}/hari.";
                            })
                            ->required(),

                        Forms\Components\Placeholder::make('info_kehilangan_umum')
                            ->visible(fn (Forms\Get $get, Loan $record): bool => (bool) $get('is_lost') && $record->book?->collection_type === 'umum')
                            ->content(new HtmlString("
                                <div class='p-3 rounded-xl bg-warning-50 dark:bg-warning-950/50 ring-1 ring-warning-600/20 dark:ring-warning-500/30 text-xs text-warning-900 dark:text-warning-200'>
                                    <strong>Ketentuan Kehilangan Buku Umum:</strong> Peminjam wajib mengganti buku yang sama atau seharga buku yang dihilangkan. Jika diganti dengan uang tunai, masukkan harga buku pada input Total Denda di atas.
                                </div>
                            ")),
                    ])
                    ->modalHeading('Pengembalian & Penyelesaian Peminjaman')
                    ->modalSubmitActionLabel('Selesaikan Pengembalian')
                    ->modalWidth('lg')
                    ->action(function (Loan $record, array $data): void {
                        $isLost = (bool) ($data['is_lost'] ?? false);
                        $fineAmount = (int) ($data['fine_amount'] ?? 0);

                        $record->update([
                            'status' => 'dikembalikan',
                            'tanggal_kembali' => now()->toDateString(),
                            'fine_amount' => $fineAmount,
                            'is_lost' => $isLost,
                        ]);

                        $statusMsg = $isLost
                            ? 'Buku ditandai hilang. Denda sebesar Rp ' . number_format($fineAmount, 0, ',', '.') . ' telah dicatat (stok tidak ditambah).'
                            : 'Buku berhasil dikembalikan ke rak (stok bertambah 1). Denda: Rp ' . number_format($fineAmount, 0, ',', '.') . '.';

                        Notification::make()
                            ->title('Pengembalian Berhasil Diproses')
                            ->body($statusMsg)
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }
}