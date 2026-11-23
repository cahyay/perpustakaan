<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use App\Filament\Resources\LoanResource\Widgets\LoanStatsOverview;
use App\Models\Loan;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            LoanStatsOverview::class,
        ];
    }

    public function getTabs(): array
    {
        $activeBookingCount = Loan::where('status', 'booked')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $borrowedCount = Loan::where('status', 'dipinjam')->count();
        $overdueCount = Loan::where('status', 'terlambat')->count();

        return [
            'semua' => Tab::make('Semua Transaksi'),

            'booking' => Tab::make('Rekap Booking User')
                ->icon('heroicon-m-ticket')
                ->badge($activeBookingCount > 0 ? (string) $activeBookingCount : null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', 'booked')
                    ->where('created_at', '>=', now()->subHours(24))),

            'dipinjam' => Tab::make('Sedang Dipinjam')
                ->icon('heroicon-m-book-open')
                ->badge($borrowedCount > 0 ? (string) $borrowedCount : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'dipinjam')),

            'terlambat' => Tab::make('Terlambat')
                ->icon('heroicon-m-exclamation-triangle')
                ->badge($overdueCount > 0 ? (string) $overdueCount : null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'terlambat')),

            'selesai' => Tab::make('Selesai & Batal')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ['dikembalikan', 'dibatalkan'])),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            // 1. Export Laporan Keuangan Denda (Excel rapi dengan border & format kolom)
            Actions\Action::make('exportLaporanDenda')
                ->label('Export Laporan Denda (Excel)')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->form([
                    Select::make('periode')
                        ->label('Pilih Periode Laporan Denda')
                        ->options([
                            'hari_ini' => 'Per Hari (Hari Ini - ' . now()->format('d M Y') . ')',
                            'minggu_ini' => 'Per Minggu (Minggu Ini)',
                            'bulan_ini' => 'Per Bulan (' . now()->translatedFormat('F Y') . ')',
                            'tahun_ini' => 'Per Tahun (' . now()->year . ')',
                            'semua' => 'Semua Riwayat Denda',
                        ])
                        ->default('bulan_ini')
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $periode = $data['periode'];
                    $labelPeriode = match ($periode) {
                        'hari_ini' => 'harian-' . now()->format('Y-m-d'),
                        'minggu_ini' => 'mingguan-' . now()->startOfWeek()->format('Y-m-d'),
                        'bulan_ini' => 'bulanan-' . now()->format('Y-m'),
                        'tahun_ini' => 'tahunan-' . now()->year,
                        default => 'semua-periode',
                    };

                    $fileName = "laporan-keuangan-denda-{$labelPeriode}.xls";

                    $query = Loan::query()
                        ->with(['anggota', 'book'])
                        ->where('fine_amount', '>', 0);

                    match ($periode) {
                        'hari_ini' => $query->whereDate('tanggal_kembali', today()),
                        'minggu_ini' => $query->whereBetween('tanggal_kembali', [now()->startOfWeek(), now()->endOfWeek()]),
                        'bulan_ini' => $query->whereMonth('tanggal_kembali', now()->month)
                            ->whereYear('tanggal_kembali', now()->year),
                        'tahun_ini' => $query->whereYear('tanggal_kembali', now()->year),
                        default => null,
                    };

                    $loans = $query->latest('tanggal_kembali')->get();

                    return response()->streamDownload(function () use ($loans, $labelPeriode) {
                        $totalDenda = 0;
                        $rowsHtml = '';

                        foreach ($loans as $index => $loan) {
                            /** @var Loan $loan */
                            $denda = (int) ($loan->fine_amount ?? 0);
                            $totalDenda += $denda;

                            $keterangan = $loan->is_lost
                                ? 'Ganti Rugi Buku Hilang'
                                : 'Denda Keterlambatan Pengembalian';

                            $tglTransaksi = e($loan->tanggal_kembali?->format('d/m/Y') ?? $loan->updated_at?->format('d/m/Y') ?? '-');
                            $kodeBooking = e($loan->booking_code ?? '-');
                            $nim = e($loan->member_nim ?? '-');
                            $nama = e($loan->anggota?->nama ?? '-');
                            $kelas = e($loan->anggota?->kelas ?? '-');
                            $kodeBuku = e($loan->book?->book_code ?? '-');
                            $judulBuku = e($loan->book?->judul ?? '-');
                            $tglPinjam = e($loan->tanggal_pinjam?->format('d/m/Y') ?? '-');
                            $jatuhTempo = e($loan->jatuh_tempo?->format('d/m/Y') ?? '-');
                            $tglKembali = e($loan->tanggal_kembali?->format('d/m/Y') ?? '-');
                            $dendaFormat = 'Rp ' . number_format($denda, 0, ',', '.');

                            $rowsHtml .= "
                                <tr>
                                    <td style='border:1px solid #999; text-align:center;'>" . ($index + 1) . "</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$tglTransaksi}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$kodeBooking}</td>
                                    <td style='border:1px solid #999; mso-number-format:\"\@\";'>{$nim}</td>
                                    <td style='border:1px solid #999;'>{$nama}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$kelas}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$kodeBuku}</td>
                                    <td style='border:1px solid #999;'>{$judulBuku}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$tglPinjam}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$jatuhTempo}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$tglKembali}</td>
                                    <td style='border:1px solid #999;'>{$keterangan}</td>
                                    <td style='border:1px solid #999; text-align:right; font-weight:bold;'>{$dendaFormat}</td>
                                </tr>
                            ";
                        }

                        $totalFormat = 'Rp ' . number_format($totalDenda, 0, ',', '.');
                        $tanggalCetak = now()->format('d/m/Y H:i');
                        $periodeUpper = strtoupper($labelPeriode);

                        echo "
                        <html xmlns:x='urn:schemas-microsoft-com:office:excel'>
                        <head><meta charset='UTF-8'></head>
                        <body>
                            <table>
                                <tr>
                                    <td colspan='13' style='font-size:14pt; font-weight:bold;'>LAPORAN KEUANGAN DENDA PERPUSTAKAAN PUSTAKA AKUNTANSI</td>
                                </tr>
                                <tr>
                                    <td colspan='2'><strong>Periode Laporan:</strong></td>
                                    <td colspan='11'>{$periodeUpper}</td>
                                </tr>
                                <tr>
                                    <td colspan='2'><strong>Tanggal Cetak:</strong></td>
                                    <td colspan='11'>{$tanggalCetak}</td>
                                </tr>
                                <tr><td colspan='13'></td></tr>
                                <thead>
                                    <tr style='background-color:#1E3A8A; color:#FFFFFF; font-weight:bold; text-align:center;'>
                                        <th style='border:1px solid #000; padding:6px;'>No</th>
                                        <th style='border:1px solid #000; padding:6px;'>Tanggal Transaksi</th>
                                        <th style='border:1px solid #000; padding:6px;'>Kode Booking</th>
                                        <th style='border:1px solid #000; padding:6px;'>NIM / No. Anggota</th>
                                        <th style='border:1px solid #000; padding:6px;'>Nama Anggota</th>
                                        <th style='border:1px solid #000; padding:6px;'>Kelas</th>
                                        <th style='border:1px solid #000; padding:6px;'>Kode Buku</th>
                                        <th style='border:1px solid #000; padding:6px;'>Judul Buku</th>
                                        <th style='border:1px solid #000; padding:6px;'>Tanggal Pinjam</th>
                                        <th style='border:1px solid #000; padding:6px;'>Jatuh Tempo</th>
                                        <th style='border:1px solid #000; padding:6px;'>Tanggal Kembali</th>
                                        <th style='border:1px solid #000; padding:6px;'>Keterangan Denda</th>
                                        <th style='border:1px solid #000; padding:6px;'>Nominal Denda</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$rowsHtml}
                                    <tr style='background-color:#FEF3C7; font-weight:bold;'>
                                        <td colspan='12' style='border:1px solid #000; text-align:right; padding:6px;'>TOTAL PEMASUKAN DENDA</td>
                                        <td style='border:1px solid #000; text-align:right; padding:6px;'>{$totalFormat}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </body>
                        </html>
                        ";
                    }, $fileName, [
                        'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                    ]);
                }),

            // 2. Export Laporan Sirkulasi Keseluruhan (Excel rapi)
            Actions\Action::make('exportLaporan')
                ->label('Export Sirkulasi (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function (): StreamedResponse {
                    $fileName = 'laporan-sirkulasi-pustaka-akuntansi-' . now()->format('Y-m-d_His') . '.xls';

                    $loans = $this->getFilteredTableQuery()
                        ->with(['anggota', 'book'])
                        ->latest()
                        ->get();

                    return response()->streamDownload(function () use ($loans) {
                        $rowsHtml = '';

                        foreach ($loans as $index => $loan) {
                            /** @var Loan $loan */
                            $statusLabel = match (true) {
                                $loan->is_lost => 'Buku Hilang',
                                $loan->status === 'booked' => 'Belum Diambil (Booking)',
                                $loan->status === 'dibatalkan' => 'Dibatalkan',
                                $loan->status === 'dikembalikan' => 'Dikembalikan',
                                $loan->isOverdue() => 'Terlambat',
                                default => 'Sudah Diambil (Dipinjam)',
                            };

                            $kodeBooking = e($loan->booking_code ?? '-');
                            $nama = e($loan->anggota?->nama ?? '-');
                            $nim = e($loan->member_nim ?? '-');
                            $kelas = e($loan->anggota?->kelas ?? '-');
                            $noHp = e($loan->anggota?->no_hp ?? '-');
                            $kartu = e($loan->card_type ?? '-');
                            $kodeBuku = e($loan->book?->book_code ?? '-');
                            $judulBuku = e($loan->book?->judul ?? '-');
                            $jenisKoleksi = e($loan->book?->collection_type_label ?? '-');
                            $tglPinjam = e($loan->tanggal_pinjam?->format('d/m/Y') ?? '-');
                            $jatuhTempo = e(in_array($loan->status, ['booked', 'dibatalkan']) ? '-' : ($loan->jatuh_tempo?->format('d/m/Y') ?? '-'));
                            $tglKembali = e($loan->tanggal_kembali?->format('d/m/Y') ?? '-');
                            $hilang = $loan->is_lost ? 'Ya' : 'Tidak';
                            $denda = 'Rp ' . number_format((int) ($loan->fine_amount ?? 0), 0, ',', '.');

                            $rowsHtml .= "
                                <tr>
                                    <td style='border:1px solid #999; text-align:center;'>" . ($index + 1) . "</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$kodeBooking}</td>
                                    <td style='border:1px solid #999;'>{$nama}</td>
                                    <td style='border:1px solid #999; mso-number-format:\"\@\";'>{$nim}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$kelas}</td>
                                    <td style='border:1px solid #999; mso-number-format:\"\@\";'>{$noHp}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$kartu}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$kodeBuku}</td>
                                    <td style='border:1px solid #999;'>{$judulBuku}</td>
                                    <td style='border:1px solid #999;'>{$jenisKoleksi}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$tglPinjam}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$jatuhTempo}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$tglKembali}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$statusLabel}</td>
                                    <td style='border:1px solid #999; text-align:center;'>{$hilang}</td>
                                    <td style='border:1px solid #999; text-align:right;'>{$denda}</td>
                                </tr>
                            ";
                        }

                        $tanggalCetak = now()->format('d/m/Y H:i');

                        echo "
                        <html xmlns:x='urn:schemas-microsoft-com:office:excel'>
                        <head><meta charset='UTF-8'></head>
                        <body>
                            <table>
                                <tr>
                                    <td colspan='16' style='font-size:14pt; font-weight:bold;'>LAPORAN SIRKULASI PEMINJAMAN & PENGEMBALIAN PUSTAKA AKUNTANSI</td>
                                </tr>
                                <tr>
                                    <td colspan='2'><strong>Tanggal Cetak:</strong></td>
                                    <td colspan='14'>{$tanggalCetak}</td>
                                </tr>
                                <tr><td colspan='16'></td></tr>
                                <thead>
                                    <tr style='background-color:#065F46; color:#FFFFFF; font-weight:bold; text-align:center;'>
                                        <th style='border:1px solid #000; padding:6px;'>No</th>
                                        <th style='border:1px solid #000; padding:6px;'>Kode Booking</th>
                                        <th style='border:1px solid #000; padding:6px;'>Nama Anggota</th>
                                        <th style='border:1px solid #000; padding:6px;'>NIM / No. Anggota</th>
                                        <th style='border:1px solid #000; padding:6px;'>Kelas</th>
                                        <th style='border:1px solid #000; padding:6px;'>No. HP</th>
                                        <th style='border:1px solid #000; padding:6px;'>Kartu Jaminan</th>
                                        <th style='border:1px solid #000; padding:6px;'>Kode Buku</th>
                                        <th style='border:1px solid #000; padding:6px;'>Judul Buku</th>
                                        <th style='border:1px solid #000; padding:6px;'>Jenis Koleksi</th>
                                        <th style='border:1px solid #000; padding:6px;'>Tanggal Pinjam</th>
                                        <th style='border:1px solid #000; padding:6px;'>Jatuh Tempo</th>
                                        <th style='border:1px solid #000; padding:6px;'>Tanggal Kembali</th>
                                        <th style='border:1px solid #000; padding:6px;'>Status</th>
                                        <th style='border:1px solid #000; padding:6px;'>Buku Hilang</th>
                                        <th style='border:1px solid #000; padding:6px;'>Nominal Denda</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$rowsHtml}
                                </tbody>
                            </table>
                        </body>
                        </html>
                        ";
                    }, $fileName, [
                        'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                    ]);
                }),

            Actions\CreateAction::make()
                ->label('Catat Peminjaman Baru')
                ->icon('heroicon-m-plus'),
        ];
    }
}