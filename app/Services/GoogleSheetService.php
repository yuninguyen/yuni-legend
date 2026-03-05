<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;
use Illuminate\Support\Facades\Log;

class GoogleSheetService
{
    protected $client;
    protected $service;
    protected $spreadsheetId;

    public function __construct()
    {
        try {
            // Get configuration from config/services.php
            $this->spreadsheetId = config('services.google.spreadsheet_id');
            $authPath = config('services.google.service_account_path');

            // Validate configuration
            if (empty($this->spreadsheetId)) {
                throw new \Exception('Google Spreadsheet ID is not configured.');
            }

            if (!file_exists($authPath)) {
                throw new \Exception("Google service account file not found.");
            }

            // Initialize Google Client
            $this->client = new Client();
            $this->client->setApplicationName(config('app.name', 'RebateOps'));
            $this->client->addScope(Sheets::SPREADSHEETS);
            $this->client->setAuthConfig($authPath);
            $this->client->setAccessType('offline');
            $this->service = new Sheets($this->client);
        } catch (\Exception $e) {
            Log::error('Failed to initialize GoogleSheetService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Google Sheets service initialization failed');
        }
    }

    /**
     * Đóng băng hàng đầu tiên và định dạng in đậm tiêu đề
     */
    public function freezeAndFormatHeader(string $sheetName)
    {
        $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
        $sheetId = null;
        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() == $sheetName) {
                $sheetId = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        if ($sheetId === null) return;

        $requests = [
            // 1. Đóng băng 1 hàng đầu tiên
            new \Google\Service\Sheets\Request([
                'updateSheetProperties' => [
                    'properties' => [
                        'sheetId' => $sheetId,
                        'gridProperties' => ['frozenRowCount' => 1],
                    ],
                    'fields' => 'gridProperties.frozenRowCount',
                ],
            ]),
            // 2. In đậm hàng tiêu đề (A1:AC1)
            new \Google\Service\Sheets\Request([
                'repeatCell' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'startRowIndex' => 0,
                        'endRowIndex' => 1,
                    ],
                    'cell' => [
                        'userEnteredFormat' => [
                            'textFormat' => ['bold' => true],
                            'backgroundColor' => ['red' => 0.9, 'green' => 0.9, 'blue' => 0.9], // Màu xám nhạt
                        ]
                    ],
                    'fields' => 'userEnteredFormat(textFormat,backgroundColor)'
                ]
            ])
        ];

        $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $requests]);
        $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);
    }

    /**
     * Hàm phụ trợ: Tự động lấy tên Tab đầu tiên trong file Google Sheet
     */
    private function getFirstSheetName()
    {
        $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
        return $spreadsheet->getSheets()[0]->getProperties()->getTitle();
    }

    // ==========================================
    // CHIỀU 1: WEB -> SHEET (Thêm một dòng mới)
    // ==========================================
    public function appendRow(array $data, ?string $sheetName = null)
    {
        try {
            $targetSheet = $sheetName ?? $this->getFirstSheetName();

            $values = [$data];
            $body = new ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'RAW'];

            $result = $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                "'{$targetSheet}'!A1",
                $body,
                $params
            );

            Log::info('Successfully appended row to Google Sheets', [
                'sheet' => $targetSheet,
                'rows_updated' => $result->getUpdates()->getUpdatedRows()
            ]);

            return $result;
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Sheets API Error - Append Row', [
                'error' => $e->getMessage(),
                'data' => $data,
                'sheet' => $sheetName
            ]);
            throw new \Exception('Failed to append data to Google Sheets: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Unexpected error in appendRow', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    // ==========================================
    // TÍNH NĂNG MỚI: Thêm NHIỀU dòng cùng lúc (Dùng cho Bulk Action Filament)
    // ==========================================
    public function appendMultipleRows(array $dataRows, ?string $sheetName = null)
    {
        try {
            $targetSheet = $sheetName ?? $this->getFirstSheetName();

            $body = new ValueRange(['values' => $dataRows]);
            $params = ['valueInputOption' => 'RAW'];

            $result = $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                "'{$targetSheet}'!A1",
                $body,
                $params
            );

            Log::info('Successfully appended multiple rows to Google Sheets', [
                'sheet' => $targetSheet,
                'row_count' => count($dataRows),
                'rows_updated' => $result->getUpdates()->getUpdatedRows()
            ]);

            return $result;
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Sheets API Error - Append Multiple Rows', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'row_count' => count($dataRows),
                'sheet' => $sheetName
            ]);
            throw new \Exception('Failed to append multiple rows to Google Sheets: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Unexpected error in appendMultipleRows', [
                'error' => $e->getMessage(),
                'row_count' => count($dataRows)
            ]);
            throw $e;
        }
    }

    // ==========================================
    // CẬP NHẬT SHEET (Ghi đè lại toàn bộ)
    // ==========================================
    public function updateSheet(array $values, $range = 'A1:AC', ?string $sheetName = null)
    {
        try {
            $targetSheet = trim($sheetName ?? $this->getFirstSheetName());
            $safeSheetName = "'" . str_replace("'", "''", $targetSheet) . "'";

            $this->service->spreadsheets_values->clear(
                $this->spreadsheetId,
                "{$safeSheetName}!A1:AC1000",
                new \Google\Service\Sheets\ClearValuesRequest()
            );

            $body = new \Google\Service\Sheets\ValueRange(['values' => $values]);
            $fullRange = "{$safeSheetName}!{$range}";

            return $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $fullRange,
                $body,
                ['valueInputOption' => 'RAW']
            );
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Sheets API Error - Update Sheet', [
                'error' => $e->getMessage(),
                'range' => $range,
                'sheet' => $sheetName
            ]);
            throw new \Exception('Failed to update data in Google Sheets: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Unexpected error in updateSheet', [
                'error' => $e->getMessage(),
                'sheet' => $sheetName
            ]);
            throw $e;
        }
    }

    // ==========================================
    // TÍNH NĂNG: UPSERT (Tự động tìm ID để Update hoặc Append nếu mới)
    // ==========================================
    /**
     * FIX: Thêm tham số $headers để tự ghi hàng tiêu đề khi tab mới trống.
     *
     * Trước đây hàm chỉ nhận 2 tham số → $headers bị bỏ qua hoàn toàn khi gọi từ
     * SyncGoogleSheetJob, HasTrackerSchema, HasAccountSchema, SyncAllToGoogleSheet.
     * Hậu quả: mọi tab tạo mới đều không có hàng tiêu đề (header row).
     *
     * Logic mới:
     *  - Đọc toàn bộ dữ liệu hiện có (1 API read call, không đổi).
     *  - Nếu sheet trống VÀ $headers được cung cấp → prepend header vào appendData.
     *  - Nếu hàng đầu đã là header (cột A = 'ID' hoặc giá trị không phải số) → bỏ qua.
     *  - Tất cả rows còn lại vẫn chạy qua idMap để update-or-append như cũ.
     */
    public function upsertRows(array $dataRows, ?string $sheetName = null, array $headers = [])
    {
        try {
            $targetSheet = $sheetName ?? $this->getFirstSheetName();
            $safeSheetName = "'" . str_replace("'", "''", $targetSheet) . "'";

            // 1 API read call — không thay đổi so với trước
            $existingData = $this->readSheet('A1:AC', $targetSheet);

            $idMap = [];
            $sheetIsEmpty = empty($existingData);
            $firstRowIsHeader = false;

            if (!$sheetIsEmpty) {
                $firstRow = $existingData[0] ?? [];
                // Nhận diện header: cột A không phải số nguyên (vd: 'ID', 'Email'...)
                $firstCell = trim($firstRow[0] ?? '');
                $firstRowIsHeader = ($firstCell !== '' && !ctype_digit($firstCell));

                foreach ($existingData as $index => $row) {
                    // Bỏ qua hàng header khi xây idMap
                    if ($index === 0 && $firstRowIsHeader) continue;

                    if (isset($row[0]) && trim($row[0]) !== '') {
                        // rowNumber trong Sheet = index (0-based) + 1 (1-based)
                        // Nhưng nếu có header, data bắt đầu từ dòng 2 → +1 thêm
                        $sheetRow = $firstRowIsHeader ? $index + 1 : $index + 1;
                        $idMap[(string)$row[0]] = $sheetRow;
                    }
                }
            }

            $updateData = [];
            $appendData = [];

            // Nếu sheet trống và có headers → ghi header trước
            if ($sheetIsEmpty && !empty($headers)) {
                $appendData[] = array_values($headers);
            }

            foreach ($dataRows as $rowData) {
                $row = array_values((array)$rowData);
                $id = (string)$row[0];

                if (isset($idMap[$id])) {
                    $rowNumber = $idMap[$id];
                    $updateData[] = new \Google\Service\Sheets\ValueRange([
                        'range'  => "{$safeSheetName}!A{$rowNumber}",
                        'values' => [$row]
                    ]);
                } else {
                    $appendData[] = $row;
                }
            }

            if (!empty($updateData)) {
                $batchRequest = new \Google\Service\Sheets\BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data'             => $updateData
                ]);
                $this->service->spreadsheets_values->batchUpdate($this->spreadsheetId, $batchRequest);
            }

            if (!empty($appendData)) {
                $body = new \Google\Service\Sheets\ValueRange(['values' => $appendData]);
                $this->service->spreadsheets_values->append(
                    $this->spreadsheetId,
                    "{$safeSheetName}!A1",
                    $body,
                    ['valueInputOption' => 'RAW']
                );
            }

            Log::info('upsertRows completed', [
                'sheet'    => $targetSheet,
                'updated'  => count($updateData),
                'appended' => count($appendData),
            ]);

            return [
                'updated'  => count($updateData),
                'appended' => count($appendData),
            ];
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Sheets API Error - Upsert Rows', [
                'error'     => $e->getMessage(),
                'row_count' => count($dataRows),
                'sheet'     => $sheetName
            ]);
            throw new \Exception('Failed to upsert data to Google Sheets: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Unexpected error in upsertRows', [
                'error' => $e->getMessage(),
                'sheet' => $sheetName
            ]);
            throw $e;
        }
    }

    // ==========================================
    // TÍNH NĂNG ĐỊNH DẠNG: Ép kiểu hiển thị chữ thành "Clip" (Cắt bớt)
    // ==========================================
    public function formatColumnsAsClip(string $sheetName, int $startColIndex, int $endColIndex)
    {
        // 1. Lấy Sheet ID (ID dạng số của tab hiện tại, khác với Spreadsheet ID)
        $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
        $sheetId = null;
        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() == $sheetName) {
                $sheetId = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        if ($sheetId === null) return;

        // 2. Tạo Request ép định dạng WrapStrategy thành CLIP
        $requests = [
            new \Google\Service\Sheets\Request([
                'repeatCell' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'startColumnIndex' => $startColIndex, // Cột bắt đầu (Tính từ 0)
                        'endColumnIndex' => $endColIndex,     // Cột kết thúc (Exclusive)
                    ],
                    'cell' => [
                        'userEnteredFormat' => [
                            'wrapStrategy' => 'CLIP'
                        ]
                    ],
                    'fields' => 'userEnteredFormat.wrapStrategy'
                ]
            ])
        ];

        // 3. Thực thi lệnh Format
        $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);

        $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);
    }

    // ==========================================
    // TÍNH NĂNG ĐỊNH DẠNG: Tìm đúng dòng đó và Xóa bỏ hoàn toàn
    // ==========================================
    public function deleteRowsByIds(array $ids, ?string $sheetName = null)
    {
        if (empty($ids)) return;

        try {
            $targetSheet = $sheetName ?? $this->getFirstSheetName();

            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            $sheetId = null;
            foreach ($spreadsheet->getSheets() as $sheet) {
                if ($sheet->getProperties()->getTitle() == $targetSheet) {
                    $sheetId = $sheet->getProperties()->getSheetId();
                    break;
                }
            }

            if ($sheetId === null) return;

            $existingData = $this->readSheet('A1:AC', $targetSheet);
            $indicesToDelete = [];

            if (!empty($existingData)) {
                foreach ($existingData as $index => $row) {
                    if (isset($row[0]) && in_array((string)$row[0], $ids)) {
                        $indicesToDelete[] = $index;
                    }
                }
            }

            if (empty($indicesToDelete)) return;

            // Xếp giảm dần để khi xóa dòng dưới không làm thay đổi index của dòng trên
            rsort($indicesToDelete);

            $requests = [];
            foreach ($indicesToDelete as $rowIndex) {
                $requests[] = new \Google\Service\Sheets\Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'dimension' => 'ROWS',
                            'startIndex' => $rowIndex,
                            'endIndex' => $rowIndex + 1
                        ]
                    ]
                ]);
            }

            $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);

            $result = $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);

            Log::info('Successfully deleted rows from Google Sheets', [
                'sheet' => $targetSheet,
                'deleted_count' => count($indicesToDelete)
            ]);

            return $result;
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Sheets API Error - Delete Rows', [
                'error' => $e->getMessage(),
                'ids_to_delete' => $ids,
                'sheet' => $sheetName
            ]);
            throw new \Exception('Failed to delete rows from Google Sheets: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Unexpected error in deleteRowsByIds', [
                'error' => $e->getMessage(),
                'ids_to_delete' => $ids,
                'sheet' => $sheetName
            ]);
            throw $e;
        }
    }

    // ==========================================
    // CHIỀU 2: SHEET -> WEB (Đọc dữ liệu)
    // ==========================================
    public function readSheet($range = 'A2:AC', ?string $sheetName = null)
    {
        try {
            $targetSheet = $sheetName ?? $this->getFirstSheetName();
            $fullRange = "'{$targetSheet}'!{$range}";

            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $fullRange);
            $values = $response->getValues();

            Log::info('Successfully read data from Google Sheets', [
                'sheet' => $targetSheet,
                'range' => $range,
                'row_count' => count($values ?? [])
            ]);

            return $values ?: [];
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Sheets API Error - Read Sheet', [
                'error' => $e->getMessage(),
                'sheet' => $sheetName,
                'range' => $range
            ]);
            throw new \Exception('Failed to read data from Google Sheets: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Unexpected error in readSheet', [
                'error' => $e->getMessage(),
                'sheet' => $sheetName,
                'range' => $range
            ]);
            throw $e;
        }
    }

    // ==========================================


    // Trả về Service gốc để gọi lệnh BatchUpdate
    public function getService()
    {
        return $this->service;
    }

    // Trả về ID của Spreadsheet đang dùng
    public function getSpreadsheetId()
    {
        return $this->spreadsheetId;
    }

    // Hàm kiểm tra và tự tạo Tab nếu chưa có
    public function createSheetIfNotExist(string $sheetName)
    {
        $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
        $sheets = $spreadsheet->getSheets();

        foreach ($sheets as $sheet) {
            if ($sheet->getProperties()->getTitle() === $sheetName) {
                return; // Đã tồn tại, thoát ra
            }
        }

        // Nếu chưa có, gửi lệnh tạo mới
        $body = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
            'requests' => [
                new \Google\Service\Sheets\Request([
                    'addSheet' => [
                        'properties' => ['title' => $sheetName]
                    ]
                ])
            ]
        ]);

        $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $body);
    }

    /**
     * Tự động tô màu cả hàng dựa trên giá trị của cột Status
     */
    /**
     * Tô màu theo quy tắc linh hoạt
     * $rules: Mảng chứa [ 'Tên trạng thái' => [màu RGB] ]
     */
    public function applyFormattingWithRules(string $sheetName, int $statusColIndex, array $rules)
    {
        $sheetId = $this->getSheetIdByName($sheetName);
        $colLetter = chr(65 + $statusColIndex);

        $requests = [];
        foreach ($rules as $status => $color) {
            $requests[] = new \Google\Service\Sheets\Request([
                'addConditionalFormatRule' => [
                    'rule' => [
                        'ranges' => [['sheetId' => $sheetId, 'startRowIndex' => 1]],
                        'booleanRule' => [
                            'condition' => [
                                'type' => 'CUSTOM_FORMULA',
                                'values' => [['userEnteredValue' => "=$" . $colLetter . "2=\"$status\""]]
                            ],
                            'format' => ['backgroundColor' => $color]
                        ]
                    ],
                    'index' => 0
                ]
            ]);
        }

        $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $requests]);
        return $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);
    }

    /**
     * Hàm phụ trợ lấy ID số của Tab (SheetId) từ tên Tab
     */
    private function getSheetIdByName(string $sheetName)
    {
        $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === $sheetName) {
                return $sheet->getProperties()->getSheetId();
            }
        }
        throw new \Exception("Không tìm thấy Tab: {$sheetName}");
    }

    // --- KẾT THÚC ---

    // ==========================================
}


