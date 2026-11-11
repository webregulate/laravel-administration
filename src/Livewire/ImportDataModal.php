<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use LivewireUI\Modal\ModalComponent;
use WebRegulate\LaravelAdministration\Classes\CSVHelper;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use Throwable;

/**
 * Class ImportDataModal
 * Handles the import of CSV data into a manageable model.
 */
class ImportDataModal extends ModalComponent
{
    use WithFileUploads;

    /**
     * The class name of the manageable model.
     *
     * @var string
     */
    public $manageableModelClass;

    /**
     * The uploaded file. Validation rules are defined in rules() so they can be
     * sourced from the wrla config (csv_imports.upload_rules).
     *
     * @var mixed
     */
    public $file = null;

    /**
     * Validation rules. Pulled from config so projects can override per-install.
     */
    protected function rules(): array
    {
        return [
            'file' => config('wrla.csv_imports.upload_rules', [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/octet-stream',
                'max:61440',
            ]),
        ];
    }

    /**
     * Headers mapped to columns
     */
    public array $headersMappedToColumns = [];

    /**
     * Data related to the CSV file.
     */
    public array $data = [
        'wrlaTmpFilePath' => '',
        'previewRowsMax' => 8,
        'currentStep' => 1, // int or 'completed'
        'origionalHeaders' => [],
        'headers' => [],
        'origionalRows' => [], // preview-sized only
        'rows' => [],          // preview-sized only
        'tableColumns' => [],
        'tableColumnsDisplay' => [],
        'totalRows' => 0,
        'successfullImports' => 0,
        'failedImports' => 0,
        'failedReasons' => [],
        'totalImports' => 0,
        'totalImported' => 0,
        // Chunked import state
        'chunkSize' => 500, // overridden from config('wrla.csv_imports.chunk_size') on import
        'chunkBaseName' => '',   // e.g. "users-import-2025-01-01-12-00-00"
        'chunkDir' => '',        // directory (relative to local disk) where chunk files live
        'totalChunks' => 0,
        'currentChunkIndex' => 0,
        // Single-row test-import state
        'testedRows' => 0,            // how many leading data rows have been consumed by "Import 1"
        'singleImportLog' => [],      // history of single-row attempts shown to the user
        'maxSingleImportLog' => 25,   // cap the on-screen log length
        // Column metadata for the target table, keyed by column name. Populated in mount().
        // Shape: [colName => ['nullable' => bool, 'type' => string]]
        'columnMeta' => [],
    ];

    /**
     * Debug information.
     */
    public array $debugInfo;

    /**
     * Listen for the process-next-batch event.
     *
     * @return void
     */
    protected $listeners = ['process-next-batch' => 'processBatch'];

    // Modal config
    public static function modalMaxWidth(): string
    {
        return '7xl';
    }

    /**
     * Hook that runs after the file attribute is validated.
     *
     * @return void
     */
    public function updatedFile()
    {
        // If there are validation errors, delete the uploaded file and reset the file attribute
        if (! $this->getErrorBag()->isEmpty()) {
            if ($this->file) {
                Storage::disk('local')->delete('livewire-tmp/'.$this->file->getFilename());
            }
            $this->file = null;

            return;
        }

        // Store file (that we will progressivly read and remove rows from later), and remove tmp file
        // File name should be tablename-import-Y-m-d-H-i-s.csv
        $baseName = (new ($this->manageableModelClass::getBaseModelClass()))->getTable().'-import-'.now()->format('Y-m-d-H-i-s');
        $fileName = $baseName.'.csv';
        $this->data['wrlaTmpFilePath'] = Storage::disk('local')->putFileAs('livewire-tmp', $this->file, $fileName);
        $this->data['chunkBaseName'] = $baseName;
        $this->data['chunkDir'] = 'livewire-tmp';
        Storage::disk('local')->delete('livewire-tmp/'.$this->file->getFilename());

        // Stream the file: read headers + preview rows only, then count remaining rows.
        // Sets origionalHeaders/rows, tableColumns, auto-maps and advances to step 2.
        [$headers, , $totalRows] = $this->readHeadersPreviewAndCount(0);
        if ($headers === null) {
            $this->file = null;
            return;
        }

        $this->data['totalRows'] = $totalRows;
        $this->data['testedRows'] = 0;
        $this->data['singleImportLog'] = [];
        $this->data['successfullImports'] = 0;
        $this->data['failedImports'] = 0;
        $this->data['failedReasons'] = [];
    }

    /**
     * Hook that runs after mapping header to a columns field is updated.
     *
     * @return void
     */
    public function updatedHeadersMappedToColumns()
    {
        $this->alignHeadersAndRowsWithMappedColumns();
    }

    /**
     * Align rows with mapped columns
     *
     * @return void
     */
    public function alignHeadersAndRowsWithMappedColumns()
    {
        // Shift headers and rows based on mapping column index to column name
        $this->data['headers'] = [];
        $this->data['rows'] = [];

        // Headers
        foreach ($this->data['origionalHeaders'] as $index => $header) {
            // If the header is not mapped to a column, skip the header
            if (empty($this->headersMappedToColumns["$index"])) {
                continue;
            }

            $this->data['headers'][] = $header;
        }

        // Rows
        foreach ($this->data['origionalRows'] as $row) {
            $newRow = [];

            foreach ($row as $index => $value) {
                // If the header is not mapped to a column, skip the value
                if (empty($this->headersMappedToColumns["$index"])) {
                    continue;
                }

                $newRow[$index] = $value;
            }

            $this->data['rows'][] = $newRow;
        }
    }

    /**
     * Initializes the component with the given manageable model class.
     *
     * @return void
     */
    public function mount(string $manageableModelClass)
    {
        // Dispatch an event indicating that the modal has been opened
        $this->dispatch('import-data-modal.opened');
        $this->manageableModelClass = $manageableModelClass;

        // Get all the table fields for this model
        $modelInstance = (new $this->manageableModelClass)->model();
        $tableColumns = WRLAHelper::getTableColumns($modelInstance->getTable(), $modelInstance->getConnectionName());
        $tableColumns = array_diff($tableColumns, (array) config('wrla.csv_imports.ignore_columns', ['id']));
        $this->data['tableColumnsDisplay'] = array_combine($tableColumns, $tableColumns);

        // Cache column metadata (nullable / type) so we can correctly cast values per column
        // during import (e.g. '' -> null for nullable datetime / int / etc. columns).
        $this->data['columnMeta'] = $this->loadColumnMeta(
            $modelInstance->getTable(),
            $modelInstance->getConnectionName()
        );
    }

    /**
     * Build a [column => ['nullable' => bool, 'type' => string]] map for the target table.
     * Uses Schema::getColumns() (Laravel 10.37+) and silently returns [] if unavailable.
     */
    protected function loadColumnMeta(string $table, ?string $connection): array
    {
        try {
            $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();
            $columns = $schema->getColumns($table);
        } catch (Throwable $e) {
            return [];
        }

        $meta = [];
        foreach ($columns as $column) {
            $name = $column['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $meta[$name] = [
                'nullable' => (bool) ($column['nullable'] ?? false),
                'type' => strtolower((string) ($column['type_name'] ?? $column['type'] ?? '')),
            ];
        }

        return $meta;
    }

    /**
     * Normalise a raw CSV value for a given target column. Trims whitespace + BOM,
     * and converts empty strings to null when the column is nullable so MySQL
     * doesn't reject '' for datetime / int / etc. columns under strict mode.
     */
    protected function castValueForColumn(string $column, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = preg_replace('/^\x{FEFF}/u', '', trim($value));
        }

        if ($value === '' || $value === null) {
            $meta = $this->data['columnMeta'][$column] ?? null;
            // Default to nullable=true when metadata is unavailable, so we keep the
            // old behaviour-of-least-surprise for projects on older Laravel versions.
            $nullable = $meta === null ? true : (bool) $meta['nullable'];
            return $nullable ? null : '';
        }

        return $value;
    }

    /**
     * Renders the view for the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        $previewRows = array_slice($this->data['rows'], 0, $this->data['previewRowsMax']);

        return view(WRLAHelper::getViewPath('livewire.import-data-modal'), [
            'previewRows' => $previewRows,
            'uploadLimits' => $this->getUploadLimitsForDisplay(),
        ]);
    }

    /**
     * Collect the upload size limits currently in effect for display in the modal.
     * Returns each source with its raw value plus a normalised KB figure, and
     * flags the lowest (i.e. binding) limit so the view can highlight it.
     *
     * @return array{items: array<int, array{source: string, display: string, kb: int, isLowest: bool}>, lowest: ?array{source: string, display: string, kb: int}}
     */
    protected function getUploadLimitsForDisplay(): array
    {
        $items = [];

        $uploadMax = (string) ini_get('upload_max_filesize');
        $items[] = [
            'source' => 'php.ini upload_max_filesize',
            'display' => $uploadMax !== '' ? $uploadMax : '(unset)',
            'kb' => (int) ceil($this->parseIniShorthandBytes($uploadMax) / 1024),
        ];

        $postMax = (string) ini_get('post_max_size');
        $items[] = [
            'source' => 'php.ini post_max_size',
            'display' => $postMax !== '' ? $postMax : '(unset)',
            'kb' => (int) ceil($this->parseIniShorthandBytes($postMax) / 1024),
        ];

        $livewireKb = $this->extractMaxKbFromRules((array) config('livewire.temporary_file_upload.rules', []));
        if ($livewireKb !== null) {
            $items[] = [
                'source' => 'Livewire temporary_file_upload max',
                'display' => number_format($livewireKb).' KB',
                'kb' => $livewireKb,
            ];
        }

        $componentKb = $this->extractMaxKbFromRules((array) config('wrla.csv_imports.upload_rules', []));
        if ($componentKb !== null) {
            $items[] = [
                'source' => 'WRLA csv_imports.upload_rules max',
                'display' => number_format($componentKb).' KB',
                'kb' => $componentKb,
            ];
        }

        // Only consider positive limits when picking the lowest binding one.
        $positive = array_values(array_filter($items, fn ($i) => $i['kb'] > 0));
        usort($positive, fn ($a, $b) => $a['kb'] <=> $b['kb']);
        $lowest = $positive[0] ?? null;

        foreach ($items as &$item) {
            $item['isLowest'] = $lowest !== null && $item['source'] === $lowest['source'];
        }

        return [
            'items' => $items,
            'lowest' => $lowest,
        ];
    }

    /**
     * Parse a php.ini shorthand byte string (e.g. "8M", "512K", "1G") into bytes.
     */
    protected function parseIniShorthandBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    /**
     * Find the first `max:N` rule (KB) in a Laravel validation rules array.
     */
    protected function extractMaxKbFromRules(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                return (int) substr($rule, 4);
            }
        }

        return null;
    }

    /**
     * Go to step X
     *
     * @return void
     */
    public function goToStep(int $step)
    {
        $this->data['currentStep'] = $step;
    }

    /**
     * Read the CSV header, the next preview rows, and count the remaining data rows,
     * skipping the first $skipDataRows data rows (used after "Import 1" consumes rows).
     *
     * Returns [headers|null, previewRows[], totalRemainingRows].
     */
    protected function readHeadersPreviewAndCount(int $skipDataRows): array
    {
        $absolutePath = Storage::disk('local')->path($this->data['wrlaTmpFilePath']);
        $handle = @fopen($absolutePath, 'r');
        if ($handle === false) {
            return [null, [], 0];
        }

        $headers = fgetcsv($handle) ?: [];
        $skipped = 0;
        $previewRows = [];
        $previewLimit = (int) $this->data['previewRowsMax'];
        $totalRows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            if ($skipped < $skipDataRows) {
                $skipped++;
                continue;
            }
            $totalRows++;
            if (count($previewRows) < $previewLimit) {
                $previewRows[] = $row;
            }
        }
        fclose($handle);

        [$cleanedHeaders, $cleanedPreviewRows] = $this->cleanAllFileData(array_merge([$headers], $previewRows));

        $this->data['origionalHeaders'] = $cleanedHeaders;
        $this->data['origionalRows'] = $cleanedPreviewRows;
        $this->data['headers'] = $cleanedHeaders;
        $this->data['rows'] = $cleanedPreviewRows;

        // Refresh tableColumns / mapping only when first loading the file (no skip).
        if ($skipDataRows === 0) {
            $manageableModel = (new $this->manageableModelClass)->model();
            $manageableModelColumns = Schema::getColumnListing($manageableModel->getTable());
            $manageableModelColumns = array_diff($manageableModelColumns, (array) config('wrla.csv_imports.ignore_columns', ['id']));
            $this->data['tableColumns'] = array_combine($manageableModelColumns, $manageableModelColumns);
            $this->data['tableColumns'] = ['' => ' - no column selected - '] + $this->data['tableColumns'];
            $this->autoMapHeadersToColumns();
            $this->data['currentStep'] = 2;
        } else {
            // Keep existing mapping but realign preview rows.
            $this->alignHeadersAndRowsWithMappedColumns();
        }

        return [$cleanedHeaders, $cleanedPreviewRows, $totalRows];
    }

    /**
     * Test-import a single row: take the first remaining data row, attempt to insert it,
     * record the outcome, and "remove" it from the queue (it will be skipped on next reads
     * and on the full import). Lets the user verify mapping row-by-row before committing.
     */
    public function importSingleRow(): void
    {
        $remaining = (int) $this->data['totalRows'];
        if ($remaining <= 0) {
            return;
        }

        $absolutePath = Storage::disk('local')->path($this->data['wrlaTmpFilePath']);
        $handle = @fopen($absolutePath, 'r');
        if ($handle === false) {
            $this->appendSingleImportLog('error', 'Could not open source file.');
            return;
        }

        // Skip header + any previously tested rows.
        fgetcsv($handle);
        $skip = (int) $this->data['testedRows'];
        $skipped = 0;
        $targetRow = null;
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            if ($skipped < $skip) {
                $skipped++;
                continue;
            }
            $targetRow = $row;
            break;
        }
        fclose($handle);

        if ($targetRow === null) {
            $this->appendSingleImportLog('error', 'No more rows to test.');
            return;
        }

        // Build mapped row data (mirrors processBatch()).
        $rowData = [];
        foreach ($this->headersMappedToColumns as $index => $column) {
            if (! empty($column)) {
                $rowData[$column] = $this->castValueForColumn($column, $targetRow[$index] ?? null);
            }
        }

        // Global CSV row number (1-based, excludes header).
        $globalRowNumber = $skip + 1;

        if (empty($rowData)) {
            $this->appendSingleImportLog(
                'error',
                'Row '.$globalRowNumber.': no columns mapped, nothing to insert.'
            );
            return;
        }

        $modelClass = (new $this->manageableModelClass)->getBaseModelClass();
        try {
            $modelClass::insert($rowData);
            $this->data['successfullImports']++;
            $this->appendSingleImportLog(
                'success',
                'Row '.$globalRowNumber.': imported successfully.',
                $rowData
            );
        } catch (Throwable $e) {
            $this->data['failedImports']++;
            $this->appendSingleImportLog(
                'error',
                'Row '.$globalRowNumber.': '.$e->getMessage(),
                $rowData
            );
        }

        // Consume this row so subsequent test imports / full import skip it.
        $this->data['testedRows']++;
        $this->data['totalRows'] = max(0, $remaining - 1);

        // Refresh preview (and realign with current mapping) without re-running auto-map.
        $this->readHeadersPreviewAndCount($this->data['testedRows']);
    }

    /**
     * Push an entry onto the on-screen single-import log, capped to maxSingleImportLog entries.
     */
    protected function appendSingleImportLog(string $status, string $message, array $rowData = []): void
    {
        $log = $this->data['singleImportLog'];
        array_unshift($log, [
            'status' => $status, // 'success' | 'error'
            'message' => $message,
            'rowData' => $rowData,
            'at' => now()->format('H:i:s'),
        ]);
        $max = (int) $this->data['maxSingleImportLog'];
        if ($max > 0 && count($log) > $max) {
            $log = array_slice($log, 0, $max);
        }
        $this->data['singleImportLog'] = $log;
    }

    /**
     * Import data: split source CSV into chunk files, then process them one at a time.
     */
    public function importData(): void
    {
        $this->data['totalImported'] = 0;
        // Preserve any successes / failures already recorded by "Import 1" attempts so the
        // final completed-screen totals reflect everything the user has done in this session.
        $this->data['currentChunkIndex'] = 0;
        $this->data['totalChunks'] = 0;
        $this->data['chunkSize'] = (int) config('wrla.csv_imports.chunk_size', 500);

        $this->data['currentStep'] = 'processing';

        // Split the source CSV file into chunk files (data rows only, no header)
        $this->splitSourceIntoChunks();

        // Kick off chunked processing
        $this->dispatch('process-next-batch');
    }

    /**
     * Stream the source CSV and write data rows (no header) into chunk files.
     * Naming: {chunkBaseName}_import_{i}.csv
     */
    protected function splitSourceIntoChunks(): void
    {
        $sourcePath = Storage::disk('local')->path($this->data['wrlaTmpFilePath']);
        $handle = @fopen($sourcePath, 'r');
        if ($handle === false) {
            $this->data['currentStep'] = 'completed';
            return;
        }

        // Skip the header row
        fgetcsv($handle);

        // Skip any rows already consumed by single-row test imports.
        $skipDataRows = (int) $this->data['testedRows'];
        $skipped = 0;
        while ($skipped < $skipDataRows && ($skipRow = fgetcsv($handle)) !== false) {
            if ($skipRow === [null] || (count($skipRow) === 1 && trim((string) $skipRow[0]) === '')) {
                continue;
            }
            $skipped++;
        }

        $chunkSize = max(1, (int) $this->data['chunkSize']);
        $chunkIndex = 0;
        $rowsInCurrentChunk = 0;
        $outHandle = null;

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            if ($outHandle === null) {
                $chunkRelativePath = $this->chunkFilePath($chunkIndex);
                $chunkAbsolutePath = Storage::disk('local')->path($chunkRelativePath);
                // Ensure directory exists
                @mkdir(dirname($chunkAbsolutePath), 0775, true);
                $outHandle = fopen($chunkAbsolutePath, 'w');
                if ($outHandle === false) {
                    break;
                }
            }

            fputcsv($outHandle, $row);
            $rowsInCurrentChunk++;

            if ($rowsInCurrentChunk >= $chunkSize) {
                fclose($outHandle);
                $outHandle = null;
                $chunkIndex++;
                $rowsInCurrentChunk = 0;
            }
        }

        if ($outHandle !== null) {
            fclose($outHandle);
            $chunkIndex++;
        }

        fclose($handle);

        $this->data['totalChunks'] = $chunkIndex;

        // Remove the original (now-split) source file to save disk space
        Storage::disk('local')->delete($this->data['wrlaTmpFilePath']);
        $this->data['wrlaTmpFilePath'] = '';
    }

    /**
     * Build the relative storage path for a chunk file.
     */
    protected function chunkFilePath(int $index): string
    {
        return rtrim($this->data['chunkDir'], '/').'/'.$this->data['chunkBaseName'].'_import_'.$index.'.csv';
    }

    /**
     * Process a single chunk file (one batch at a time, then dispatch next).
     */
    public function processBatch(): void
    {
        if ($this->data['currentChunkIndex'] >= $this->data['totalChunks']) {
            $this->data['currentStep'] = 'completed';
            return;
        }

        $modelClass = (new $this->manageableModelClass)->getBaseModelClass();
        $chunkRelativePath = $this->chunkFilePath($this->data['currentChunkIndex']);
        $chunkAbsolutePath = Storage::disk('local')->path($chunkRelativePath);

        $formattedBatchData = [];
        $rowsRead = 0;

        if (is_file($chunkAbsolutePath)) {
            $handle = fopen($chunkAbsolutePath, 'r');
            if ($handle !== false) {
                while (($row = fgetcsv($handle)) !== false) {
                    if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                        continue;
                    }

                    $rowData = [];
                    foreach ($this->headersMappedToColumns as $index => $column) {
                        if (! empty($column)) {
                            $rowData[$column] = $this->castValueForColumn($column, $row[$index] ?? null);
                        }
                    }

                    if (! empty($rowData)) {
                        $formattedBatchData[] = $rowData;
                        $rowsRead++;
                    }
                }
                fclose($handle);
            }

            // Delete chunk file once read
            Storage::disk('local')->delete($chunkRelativePath);
        }

        if (! empty($formattedBatchData)) {
            $this->insertRows($modelClass, $formattedBatchData);
            $this->data['totalImported'] += $rowsRead;
        }

        $this->data['currentChunkIndex']++;

        if ($this->data['currentChunkIndex'] >= $this->data['totalChunks']) {
            $this->data['currentStep'] = 'completed';
            return;
        }

        $this->dispatch('process-next-batch');
    }

    /**
     * Insert rows one at a time so a single bad row does not fail the entire chunk.
     * Tracks per-row success/failure counts and retains a configurable number of failure reasons.
     */
    private function insertRows($modelClass, array $rows): void
    {
        $maxReasons = (int) config('wrla.csv_imports.max_failed_reasons', 20);

        foreach ($rows as $rowIndex => $rowData) {
            try {
                $modelClass::insert($rowData);
                $this->data['successfullImports']++;
            } catch (Throwable $e) {
                $this->data['failedImports']++;

                if (count($this->data['failedReasons']) < $maxReasons) {
                    // Reference the global CSV row number (1-based, excluding header).
                    // Add testedRows offset since chunks start after any single-row test imports.
                    $globalRowNumber = (int) $this->data['testedRows']
                        + ($this->data['currentChunkIndex'] * (int) $this->data['chunkSize'])
                        + $rowIndex + 1;
                    $this->data['failedReasons'][] = 'Row '.$globalRowNumber.': '.$e->getMessage();
                }
            }
        }
    }

    /**
     * Close and refresh
     *
     * @return void
     */
    public function closeAndRefresh()
    {
        // Best-effort cleanup of any remaining chunk files
        $this->cleanupChunkFiles();

        // Close the modal
        $this->closeModal();

        // Refresh current URL
        $this->js('window.location.reload()');
    }

    /**
     * Remove any leftover chunk files and the original uploaded source (if still present).
     * Safe to call multiple times.
     */
    protected function cleanupChunkFiles(): void
    {
        $disk = Storage::disk('local');

        // Remove remaining chunk files
        if (! empty($this->data['chunkBaseName']) && ! empty($this->data['chunkDir'])) {
            $totalChunks = (int) $this->data['totalChunks'];
            $startIndex = (int) $this->data['currentChunkIndex'];

            // If a stale split was produced, totalChunks may not yet be set; sweep a reasonable range too.
            $end = max($totalChunks, $startIndex + 1);
            for ($i = $startIndex; $i < $end; $i++) {
                $path = $this->chunkFilePath($i);
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        }

        // Remove the original uploaded file if it still exists (eg. modal closed pre-split)
        if (! empty($this->data['wrlaTmpFilePath']) && $disk->exists($this->data['wrlaTmpFilePath'])) {
            $disk->delete($this->data['wrlaTmpFilePath']);
            $this->data['wrlaTmpFilePath'] = '';
        }
    }

    /**
     * Cleans all data (headers and rows) from the provided file data.
     *
     * @return void
     */
    public function cleanAllFileData(array $fileData): array
    {
        // Extract headers and rows from the file data
        $headers = array_shift($fileData);
        $rows = $fileData;

        // Clean headers by trimming whitespace and removing unwanted characters
        foreach ($headers as $key => $value) {
            $value = trim((string) $value);
            $value = preg_replace('/^\x{FEFF}/u', '', $value);
            $headers[$key] = $value;
            if ($headers[$key] == 'id') {
                unset($headers[$key]);
            }
        }

        // Clean rows by trimming whitespace and removing unwanted characters
        foreach ($rows as $rowKey => $row) {
            foreach ($row as $key => $value) {
                $value = trim((string) $value);
                $value = preg_replace('/^\x{FEFF}/u', '', $value);
                $rows[$rowKey][$key] = $value;
            }
        }

        return [
            $headers,
            $rows,
        ];
    }

    /**
     * Automatically maps headers to columns.
     *
     * @return void
     */
    public function autoMapHeadersToColumns()
    {
        // Already mapped
        $alreadyMappedColoumns = [];

        // Initialize the mapping of headers to columns
        foreach ($this->data['headers'] as $headerIndex => $header) {
            $this->headersMappedToColumns["$headerIndex"] = null;

            // Attempt to map each header to a corresponding column
            foreach ($this->data['tableColumns'] as $actualColumn) {
                $header = str($header)->lower()->replace(' ', '_')->__toString();

                if (! in_array($actualColumn, $alreadyMappedColoumns) && str($actualColumn) == $header) {
                    $this->headersMappedToColumns["$headerIndex"] = $actualColumn;
                    $alreadyMappedColoumns[] = $actualColumn;
                }
            }
        }

        // Align rows with mapped columns
        $this->alignHeadersAndRowsWithMappedColumns();
    }

    /**
     * Builds and downloads a template CSV file for the manageable model with the table columns as headings.
     */
    public function actionDownloadTemplateCsv()
    {
        $modelInstance = (new $this->manageableModelClass)->model();
        $tableColumns = WRLAHelper::getTableColumns($modelInstance->getTable(), $modelInstance->getConnectionName());
        $tableColumns = array_diff($tableColumns, (array) config('wrla.csv_imports.ignore_columns', ['id']));

        $fileName = (new ($this->manageableModelClass::getBaseModelClass()))->getTable().'-import-template.csv';

        return CSVHelper::build(
            $fileName,
            $tableColumns,
            []
        );
    }
}
