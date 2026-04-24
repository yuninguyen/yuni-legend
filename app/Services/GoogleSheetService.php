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
            // Lấy cấu hình ID
            $this->spreadsheetId = config('services.google.spreadsheet_id');

            // ðŸŸ¢ FIX: DÃ¹ng storage_path Ä‘á»ƒ luÃ´n táº¡o ra Ä‘Æ°á»ng dáº«n tuyá»‡t Ä‘á»‘i (Absolute Path)
            // DÃ¹ cáº¥u hÃ¬nh lÃ  gÃ¬, ta Ã©p nÃ³ chá»c tháº³ng vÃ o thÆ° má»¥c storage/app/
            $authPath = storage_path('app/google-auth.json');

            // Validate configuration
            if (empty($this->spreadsheetId)) {
                throw new \Exception('Google Spreadsheet ID is not configured.');
            }

            if (!file_exists($authPath)) {
                throw new \Exception("Google service account file not found at: " . $authPath);
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
            throw new \Exception('Google Sheets service initialization failed', 0, $e);
        }
    }

    /**
     * ÄÃ³ng bÄƒng hÃ ng Ä‘áº§u tiÃªn vÃ  Ä‘á»‹nh dáº¡ng in Ä‘áº­m tiÃªu Ä‘á»
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

        if ($sheetId === null)
            return;

        $requests = [
            // 1. ÄÃ³ng bÄƒng 1 hÃ ng Ä‘áº§u tiÃªn
            new \Google\Service\Sheets\Request([
                'updateSheetProperties' => [
                    'properties' => [
                        'sheetId' => $sheetId,
                        'gridProperties' => ['frozenRowCount' => 1],
                    ],
                    'fields' => 'gridProperties.frozenRowCount',
                ],
            ]),
            // 2. In Ä‘áº­m hÃ ng tiÃªu Ä‘á» (A1:AC)
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
        $sheets = $this->getCachedSheetInfo();
        return array_key_first($sheets);
    }

    // ==========================================
    //  CHIỀU 1: WEB -> SHEET (Thêm một dòng mới)
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
    // TÃNH NÄ‚NG Má»šI: ThÃªm NHIá»€U dÃ²ng cÃ¹ng lÃºc (DÃ¹ng cho Bulk Action Filament)
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
                "{$safeSheetName}!A1:AC",
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
    // TÃNH NÄ‚NG: UPSERT (Tá»± Ä‘á»™ng tÃ¬m ID Ä‘á»ƒ Update hoáº·c Append náº¿u má»›i)
    // ==========================================
    public function upsertRows(array $dataRows, ?string $sheetName = null, ?array $headers = null)
    {
        try {
            $targetSheet = $sheetName ?? $this->getFirstSheetName();
            $safeSheetName = "'" . str_replace("'", "''", $targetSheet) . "'";

            $existingIds = $this->readSheet('A2:A', $targetSheet);

            $idMap = [];
            if (!empty($existingIds)) {
                foreach ($existingIds as $index => $row) {
                    if (isset($row[0]) && trim($row[0]) !== '') {
                        // 🟢 FIX BUG #10: Bắt đầu từ hàng 2, nên index 0 của bảng dữ liệu là hàng 2 trên Sheet
                        $idMap[(string) $row[0]] = $index + 2;
                    }
                }
            }

            $updateData = [];
            $appendData = [];

            foreach ($dataRows as $rowData) {
                // Đảm bảo mỗi dòng là một mảng phẳng, chỉ chứa chuỗi (sequential string array)
                $rawRow = is_array($rowData) ? $rowData : (array) $rowData;
                $row = [];
                foreach ($rawRow as $val) {
                    $row[] = ($val === null) ? '' : (string) $val;
                }

                $id = isset($row[0]) ? (string) $row[0] : '';

                if ($id !== '' && isset($idMap[$id])) {
                    $rowNumber = $idMap[$id];

                    $vr = new \Google\Service\Sheets\ValueRange();
                    $vr->setRange("{$safeSheetName}!A{$rowNumber}");
                    $vr->setValues([$row]);
                    $updateData[] = $vr;
                } else {
                    $appendData[] = $row;
                }
            }

            if (!empty($updateData)) {
                $batchRequest = new \Google\Service\Sheets\BatchUpdateValuesRequest();
                $batchRequest->setValueInputOption('RAW');
                $batchRequest->setData($updateData);

                $this->service->spreadsheets_values->batchUpdate($this->spreadsheetId, $batchRequest);
            }



            // 🟢 FIX BUG THIẾU HEADER: 
            // Nếu tab mới hoàn toàn (idMap rỗng), chèn Header lên đầu mảng dữ liệu chuẩn bị đẩy lên
            if (!empty($headers) && empty($idMap)) {
                array_unshift($appendData, $headers);
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

            return [
                'updated' => count($updateData),
                'appended' => count($appendData)
            ];
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Sheets API Error - Upsert Rows', [
                'error' => $e->getMessage(),
                'row_count' => count($dataRows),
                'sheet' => $sheetName
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
    // TÃNH NÄ‚NG Äá»ŠNH Dáº NG: Ã‰p kiá»ƒu hiá»ƒn thá»‹ chá»¯ thÃ nh "Clip" (Cáº¯t bá»›t)
    // ==========================================
    public function formatColumnsAsClip(string $sheetName, int $startColIndex, int $endColIndex)
    {
        // 🟢 FIX N+1: Lấy ID từ Cache cực nhanh
        $sheetId = $this->getSheetIdByName($sheetName);
        if ($sheetId === null)
            return;

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
    // TÃNH NÄ‚NG Äá»ŠNH Dáº NG: TÃ¬m Ä‘Ãºng dÃ²ng Ä‘Ã³ vÃ  XÃ³a bá» hoÃ n toÃ n
    // ==========================================
    public function deleteRowsByIds(array $ids, ?string $sheetName = null)
    {
        if (empty($ids))
            return;

        try {
            $targetSheet = $sheetName ?? $this->getFirstSheetName();

            // 🟢 FIX N+1: Lấy ID từ Cache cực nhanh
            $sheetId = $this->getSheetIdByName($targetSheet);
            if ($sheetId === null)
                return;

            $existingData = $this->readSheet('A1:A', $targetSheet);
            $indicesToDelete = [];

            if (!empty($existingData)) {
                foreach ($existingData as $index => $row) {
                    if (isset($row[0]) && in_array((string) $row[0], $ids)) {
                        $indicesToDelete[] = $index;
                    }
                }
            }

            if (empty($indicesToDelete))
                return;

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
    // CHIá»€U 2: SHEET -> WEB (Äá»c dá»¯ liá»‡u)
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


    // Tráº£ vá» Service gá»‘c Ä‘á»ƒ gá»i lá»‡nh BatchUpdate
    public function getService()
    {
        return $this->service;
    }

    // Tráº£ vá» ID cá»§a Spreadsheet Ä‘ang dÃ¹ng
    public function getSpreadsheetId()
    {
        return $this->spreadsheetId;
    }

    // Hàm kiểm tra và tự tạo Tab nếu chưa có
    public function createSheetIfNotExist(string $sheetName)
    {
        // ðŸŸ¢ Äá»c tá»« Cache thay vÃ¬ gá»i API
        $sheets = $this->getCachedSheetInfo();

        if (array_key_exists($sheetName, $sheets)) {
            return; // ÄÃ£ tá»“n táº¡i, thoÃ¡t ra
        }

        // Nếu chưa có, gửi lệnh tạo mới
        $body = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
            'requests' => [
                new \Google\Service\Sheets\Request([
                    'addSheet' => ['properties' => ['title' => $sheetName]]
                ])
            ]
        ]);

        $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $body);

        // ðŸŸ¢ QUAN TRá»ŒNG: Vá»«a táº¡o Tab má»›i xong thÃ¬ pháº£i Ä‘áº­p bá» Cache cÅ© Ä‘á»ƒ nÃ³ láº¥y láº¡i danh sÃ¡ch má»›i
        \Illuminate\Support\Facades\Cache::forget('sheet_info_' . $this->spreadsheetId);
    }

    /**
     * Tự động tô màu cả hàng dựa trên giá trị của cột Status
     * Tô màu theo quy tắc linh hoạt
     * $rules: Mảng chứa [ 'Tên trạng thái' => [màu RGB] ]
     */
    public function applyFormattingWithRules(string $sheetName, int $statusColIndex, array $rules)
    {
        $sheetId = $this->getSheetIdByName($sheetName);
        if ($sheetId === null) {
            return;
        }

        $colLetter = chr(65 + $statusColIndex);

        // 1. Lấy danh sách các lệnh XÓA Rule cũ (để tránh tích lũy vô hạn - BUG #2)
        $requests = $this->getDeleteConditionalFormatRulesRequests($sheetId);

        // 2. Thêm các lệnh THÊM Rule mới
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
     * Lấy danh sách Request để xóa toàn bộ Conditional Format Rules hiện tại của một Sheet
     */
    private function getDeleteConditionalFormatRulesRequests(int $sheetId): array
    {
        $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
        $requests = [];

        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getSheetId() !== $sheetId) {
                continue;
            }

            $rules = $sheet->getConditionalFormats() ?? [];
            $ruleCount = count($rules);

            // Xóa từ cuối lên đầu để index không bị thay đổi trong quá trình xóa (nếu gửi lẻ)
            // Trong BatchUpdate thì gửi index nào cũng được nhưng làm ngược lại cho an toàn
            for ($i = $ruleCount - 1; $i >= 0; $i--) {
                $requests[] = new \Google\Service\Sheets\Request([
                    'deleteConditionalFormatRule' => [
                        'index' => $i,
                        'sheetId' => $sheetId,
                    ]
                ]);
            }
            break;
        }

        return $requests;
    }

    /**
     * 🟢 HÀM MỚI: Lấy danh sách Sheet lưu vào Cache 60 phút
     */
    private function getCachedSheetInfo()
    {
        $cacheKey = 'sheet_info_' . $this->spreadsheetId;

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            $info = [];
            foreach ($spreadsheet->getSheets() as $sheet) {
                // Lưu thành mảng: ['Tên Tab' => ID_Của_Tab]
                $info[$sheet->getProperties()->getTitle()] = $sheet->getProperties()->getSheetId();
            }
            return $info;
        });
    }

    /**
     * ðŸŸ¢ Láº¥y ID tá»« Cache thay vÃ¬ gá»i API liÃªn tá»¥c
     */
    private function getSheetIdByName(string $sheetName): ?int
    {
        $sheets = $this->getCachedSheetInfo();

        return $sheets[$sheetName] ?? null;
    }

    // --- KẾT THÚC ---

    // ==========================================
}
