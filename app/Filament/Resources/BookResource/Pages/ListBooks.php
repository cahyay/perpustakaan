<?php

namespace App\Filament\Resources\BookResource\Pages;

use App\Filament\Resources\BookResource;
use App\Models\Book;
use App\Models\Category;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListBooks extends ListRecords
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadTemplate')
                ->label('Unduh Template Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): BinaryFileResponse {
                    $fileName = 'template_koleksi_buku_' . date('Ymd_His') . '.xlsx';
                    $tempPath = storage_path('app/temp-imports/' . $fileName);

                    if (! is_dir(dirname($tempPath))) {
                        mkdir(dirname($tempPath), 0755, true);
                    }

                    $writer = new XlsxWriter();
                    $writer->openToFile($tempPath);

                    // Baris 1: Header Kolom
                    $writer->addRow(Row::fromValues([
                        'kode_buku',
                        'jenis_koleksi',
                        'judul',
                        'kategori',
                        'penulis',
                        'penerbit',
                        'tahun_terbit',
                        'isbn',
                        'stok',
                        'sinopsis',
                    ]));

                    // Baris 2: Contoh Buku Umum (dengan ISBN)
                    $writer->addRow(Row::fromValues([
                        '',
                        'umum',
                        'Akuntansi Keuangan Menengah Berbasis PSAK',
                        'Akuntansi Keuangan',
                        'Dwi Martani, dkk.',
                        'Salemba Empat',
                        2023,
                        '9789790619992',
                        10,
                        'Buku rujukan utama akuntansi keuangan berbasis standar akuntansi keuangan terkini.',
                    ]));

                    // Baris 3: Contoh Tugas Akhir / Skripsi (dengan Kode Buku)
                    $writer->addRow(Row::fromValues([
                        'TA-2024-001',
                        'ta_skripsi',
                        'Analisis Pengaruh Penerapan Sistem Akuntansi Keuangan Daerah terhadap Kualitas Laporan Keuangan',
                        'Tugas Akhir',
                        'Ahmad Fadhil',
                        'Jurusan Akuntansi Politeknik',
                        2024,
                        '',
                        2,
                        'Studi empiris pada Satuan Kerja Perangkat Daerah (SKPD) di lingkungan pemerintah daerah.',
                    ]));

                    // Baris 4: Contoh Proposal / Laporan PKL (dengan Kode Buku)
                    $writer->addRow(Row::fromValues([
                        'PKL-2024-012',
                        'proposal_laporan_pkl',
                        'Laporan Praktik Kerja Lapangan pada Kantor Akuntan Publik Drs. Sudirman & Rekan',
                        'Laporan PKL',
                        'Nareswari Putri',
                        'Jurusan Akuntansi Politeknik',
                        2024,
                        '',
                        1,
                        'Dokumentasi dan evaluasi audit operasional dan pengujian substantif atas persediaan.',
                    ]));

                    $writer->close();

                    return response()->download($tempPath, 'template_koleksi_buku.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])->deleteFileAfterSend(true);
                }),

            Actions\Action::make('importBooks')
                ->label('Impor Buku')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->form([
                    Forms\Components\FileUpload::make('import_file')
                        ->label('Berkas Excel / CSV')
                        ->helperText('Unggah berkas berformat .xlsx, .xls, atau .csv. Unduh template jika belum memiliki format kolom.')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'text/comma-separated-values',
                            'application/csv',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->disk('local')
                        ->directory('temp-imports')
                        ->preserveFilenames()
                        ->required(),
                ])
                ->modalHeading('Impor Data Buku dari Excel / CSV')
                ->modalDescription('Unggah data koleksi buku secara massal. Sistem menggunakan ISBN atau Kode Buku sebagai pengenal unik.')
                ->modalSubmitActionLabel('Mulai Impor')
                ->modalWidth('md')
                ->action(function (array $data): void {
                    $disk = Storage::disk('local');
                    $filePath = $disk->path($data['import_file']);

                    if (! file_exists($filePath)) {
                        Notification::make()
                            ->title('File Tidak Ditemukan')
                            ->body('Berkas yang diunggah tidak ditemukan di penyimpanan sementara.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

                    // Membaca baris data menggunakan OpenSpout
                    $rowsData = [];
                    try {
                        if (in_array($extension, ['csv', 'txt'])) {
                            $reader = new CsvReader();
                        } else {
                            $reader = new XlsxReader();
                        }

                        $reader->open($filePath);

                        foreach ($reader->getSheetIterator() as $sheet) {
                            foreach ($sheet->getRowIterator() as $row) {
                                $cells = $row->getCells();
                                $rowValues = [];
                                foreach ($cells as $cell) {
                                    $val = $cell->getValue();
                                    if ($val instanceof \DateTimeInterface) {
                                        $val = $val->format('Y-m-d');
                                    }
                                    $rowValues[] = trim((string) $val);
                                }
                                $rowsData[] = $rowValues;
                            }
                            // Baca sheet pertama saja
                            break;
                        }

                        $reader->close();
                    } catch (\Throwable $e) {
                        // Fallback jika format CSV klasik
                        if (in_array($extension, ['csv', 'txt'])) {
                            $rowsData = [];
                            $handle = fopen($filePath, 'r');
                            if ($handle) {
                                $firstLine = fgets($handle);
                                $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
                                rewind($handle);
                                while (($csvRow = fgetcsv($handle, 0, $delimiter)) !== false) {
                                    $rowsData[] = array_map(fn ($c) => trim((string) $c), $csvRow);
                                }
                                fclose($handle);
                            }
                        } else {
                            $disk->delete($data['import_file']);
                            Notification::make()
                                ->title('Gagal Membaca Berkas')
                                ->body('Terjadi kesalahan format: ' . $e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }
                    }

                    $disk->delete($data['import_file']);

                    if (empty($rowsData)) {
                        Notification::make()
                            ->title('Berkas Kosong')
                            ->body('Berkas yang diunggah tidak memiliki data.')
                            ->warning()
                            ->send();

                        return;
                    }

                    // Baris pertama adalah header
                    $rawHeaders = array_shift($rowsData);
                    $headerMap = [];

                    foreach ($rawHeaders as $index => $header) {
                        $clean = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', trim((string) $header));
                        $col = strtolower(str_replace([' ', '-'], '_', $clean));

                        if (in_array($col, ['kode_buku', 'book_code', 'kode', 'nomor_koleksi'])) {
                            $headerMap['kode_buku'] = $index;
                        } elseif (in_array($col, ['jenis_koleksi', 'collection_type', 'tipe_koleksi', 'jenis'])) {
                            $headerMap['jenis_koleksi'] = $index;
                        } elseif (in_array($col, ['judul', 'title', 'nama_buku', 'nama', 'book_title'])) {
                            $headerMap['judul'] = $index;
                        } elseif (in_array($col, ['kategori', 'category', 'category_id', 'kategori_id', 'kategori_buku'])) {
                            $headerMap['kategori'] = $index;
                        } elseif (in_array($col, ['penulis', 'author', 'pengarang', 'writer'])) {
                            $headerMap['penulis'] = $index;
                        } elseif (in_array($col, ['penerbit', 'publisher'])) {
                            $headerMap['penerbit'] = $index;
                        } elseif (in_array($col, ['tahun_terbit', 'tahun', 'year', 'published_year', 'thn_terbit'])) {
                            $headerMap['tahun_terbit'] = $index;
                        } elseif (in_array($col, ['isbn', 'isbn_13', 'isbn_10'])) {
                            $headerMap['isbn'] = $index;
                        } elseif (in_array($col, ['stok', 'stock', 'jumlah', 'quantity', 'qty'])) {
                            $headerMap['stok'] = $index;
                        } elseif (in_array($col, ['sinopsis', 'synopsis', 'description', 'deskripsi', 'ringkasan', 'abstrak'])) {
                            $headerMap['sinopsis'] = $index;
                        }
                    }

                    if (! isset($headerMap['judul'])) {
                        Notification::make()
                            ->title('Header Tidak Valid')
                            ->body('Kolom "judul" (atau "title") tidak ditemukan di baris pertama berkas.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $createdCount = 0;
                    $updatedCount = 0;
                    $skippedCount = 0;
                    $rowNumber = 1;

                    $categoriesCache = Category::all()->keyBy(fn (Category $cat) => strtolower(trim($cat->nama)));

                    DB::beginTransaction();
                    try {
                        foreach ($rowsData as $row) {
                            $rowNumber++;

                            // Lewati baris kosong
                            if (empty(array_filter($row, fn ($val) => filled(trim((string) $val))))) {
                                continue;
                            }

                            $getValue = function (string $key, $default = null) use ($headerMap, $row) {
                                if (isset($headerMap[$key]) && isset($row[$headerMap[$key]])) {
                                    $val = trim((string) $row[$headerMap[$key]]);

                                    return $val !== '' ? $val : $default;
                                }

                                return $default;
                            };

                            $judul = $getValue('judul');
                            if (blank($judul)) {
                                $skippedCount++;
                                continue;
                            }

                            // Normalisasi jenis_koleksi
                            $rawJenis = strtolower($getValue('jenis_koleksi', 'umum'));
                            $collectionType = match (true) {
                                str_contains($rawJenis, 'skripsi') || str_contains($rawJenis, 'ta') => 'ta_skripsi',
                                str_contains($rawJenis, 'pkl') || str_contains($rawJenis, 'proposal') || str_contains($rawJenis, 'laporan') => 'proposal_laporan_pkl',
                                default => 'umum',
                            };

                            // Kode Buku
                            $rawKode = $getValue('kode_buku');
                            $bookCode = filled($rawKode) ? strtoupper(trim($rawKode)) : null;

                            // Resolusi Kategori
                            $kategoriInput = $getValue('kategori', match ($collectionType) {
                                'ta_skripsi' => 'Tugas Akhir',
                                'proposal_laporan_pkl' => 'Laporan PKL',
                                default => 'Umum',
                            });

                            $categoryId = null;
                            if (is_numeric($kategoriInput) && Category::where('id', (int) $kategoriInput)->exists()) {
                                $categoryId = (int) $kategoriInput;
                            } else {
                                $kategoriLower = strtolower($kategoriInput);
                                if ($categoriesCache->has($kategoriLower)) {
                                    $categoryId = $categoriesCache->get($kategoriLower)->id;
                                } else {
                                    $category = Category::whereRaw('LOWER(nama) = ?', [$kategoriLower])->first();

                                    if (! $category) {
                                        $namaFormatted = ucwords(strtolower($kategoriInput));
                                        $baseSlug = Str::slug($namaFormatted) ?: 'kategori';
                                        $slug = $baseSlug;
                                        $counter = 1;
                                        while (Category::where('slug', $slug)->exists()) {
                                            $slug = "{$baseSlug}-{$counter}";
                                            $counter++;
                                        }

                                        $category = Category::create([
                                            'nama' => $namaFormatted,
                                            'slug' => $slug,
                                        ]);
                                    }

                                    $categoriesCache->put($kategoriLower, $category);
                                    $categoryId = $category->id;
                                }
                            }

                            $penulis = $getValue('penulis', 'Tidak Diketahui');
                            $penerbit = $getValue('penerbit', 'Jurusan Akuntansi');
                            $tahunTerbit = (int) $getValue('tahun_terbit', (int) date('Y'));
                            if ($tahunTerbit < 1900 || $tahunTerbit > (int) date('Y') + 1) {
                                $tahunTerbit = (int) date('Y');
                            }

                            $rawIsbn = $getValue('isbn');
                            $isbn = filled($rawIsbn) ? preg_replace('/[^0-9X-]/i', '', $rawIsbn) : null;
                            $stok = max(0, (int) $getValue('stok', 1));
                            $sinopsis = $getValue('sinopsis');

                            // Pencarian buku eksisting:
                            // 1. Berdasarkan book_code (jika ada)
                            $existingBook = null;
                            if (filled($bookCode)) {
                                $existingBook = Book::where('book_code', $bookCode)->first();
                            }

                            // 2. Berdasarkan ISBN jika belum ditemukan dan ISBN diisi
                            if (! $existingBook && filled($isbn)) {
                                $existingBook = Book::where('isbn', $isbn)->first();
                            }

                            // 3. Berdasarkan kombinasi Judul & Penulis
                            if (! $existingBook) {
                                $existingBook = Book::whereRaw('LOWER(judul) = ?', [strtolower($judul)])
                                    ->whereRaw('LOWER(penulis) = ?', [strtolower($penulis)])
                                    ->first();
                            }

                            if ($existingBook) {
                                $existingBook->update([
                                    'category_id' => $categoryId,
                                    'collection_type' => $collectionType,
                                    'book_code' => $bookCode ?: $existingBook->book_code,
                                    'judul' => ucwords(strtolower($judul)),
                                    'penulis' => ucwords(strtolower($penulis)),
                                    'penerbit' => $penerbit,
                                    'tahun_terbit' => $tahunTerbit,
                                    'stok' => $stok,
                                    'sinopsis' => $sinopsis ?: $existingBook->sinopsis,
                                    'isbn' => $isbn ?: $existingBook->isbn,
                                ]);
                                $updatedCount++;
                            } else {
                                Book::create([
                                    'category_id' => $categoryId,
                                    'collection_type' => $collectionType,
                                    'book_code' => $bookCode,
                                    'judul' => ucwords(strtolower($judul)),
                                    'penulis' => ucwords(strtolower($penulis)),
                                    'penerbit' => $penerbit,
                                    'tahun_terbit' => $tahunTerbit,
                                    'isbn' => $isbn,
                                    'stok' => $stok,
                                    'sinopsis' => $sinopsis,
                                ]);
                                $createdCount++;
                            }
                        }

                        DB::commit();
                    } catch (\Throwable $e) {
                        DB::rollBack();

                        Notification::make()
                            ->title('Gagal Mengimpor Data')
                            ->body('Terjadi kesalahan pada baris ' . $rowNumber . ': ' . $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $totalProcessed = $createdCount + $updatedCount;
                    $skippedMsg = $skippedCount > 0 ? " ({$skippedCount} baris dilewati karena judul kosong)" : '';

                    Notification::make()
                        ->title('Impor Koleksi Buku Selesai')
                        ->body("Berhasil memproses {$totalProcessed} buku ({$createdCount} baru, {$updatedCount} diperbarui){$skippedMsg}.")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('createCategory')
                ->label('Tambah Kategori')
                ->icon('heroicon-o-tag')
                ->color('warning')
                ->modalHeading('Tambah Kategori Baru')
                ->modalSubmitActionLabel('Simpan Kategori')
                ->modalWidth('md')
                ->form([
                    Forms\Components\TextInput::make('nama')
                        ->label('Nama Kategori')
                        ->required()
                        ->maxLength(255)
                        ->unique(Category::class, 'nama')
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? ucwords(strtolower(trim($state))) : null),
                ])
                ->action(function (array $data): void {
                    $nama = ucwords(strtolower(trim($data['nama'])));
                    $baseSlug = Str::slug($nama) ?: 'kategori';
                    $slug = $baseSlug;
                    $counter = 1;

                    while (Category::where('slug', $slug)->exists()) {
                        $slug = "{$baseSlug}-{$counter}";
                        $counter++;
                    }

                    $category = Category::create([
                        'nama' => $nama,
                        'slug' => $slug,
                    ]);

                    Notification::make()
                        ->title('Kategori Berhasil Ditambahkan')
                        ->body("Kategori '{$category->nama}' sudah tersimpan.")
                        ->success()
                        ->send();
                }),

            Actions\CreateAction::make()
                ->label('Tambah Buku')
                ->icon('heroicon-m-plus'),
        ];
    }
}