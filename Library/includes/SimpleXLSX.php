<?php
/**
 * SimpleXLSX - Universal Excel and CSV reader
 * Handles both CSV and Excel files automatically
 * No extensions required for CSV files
 */

class SimpleXLSX {
    private $sheets = [];
    private $sheetNames = [];
    private static $error = '';

    public static function parse($filename) {
        if (!file_exists($filename)) {
            self::$error = "File not found: $filename";
            return false;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Handle CSV files (no extensions needed)
        if ($extension === 'csv') {
            return self::parseCSV($filename);
        }

        // Handle Excel files
        if ($extension === 'xlsx' || $extension === 'xls') {
            // Try Excel parsing, fall back to CSV if it fails
            $result = self::parseExcel($filename);
            if ($result === false && self::$error) {
                // If Excel parsing failed, suggest CSV
                self::$error .= " Please convert to CSV format for guaranteed compatibility.";
            }
            return $result;
        }

        // Auto-detect file type by content
        $handle = fopen($filename, 'r');
        if ($handle) {
            $firstBytes = fread($handle, 4);
            fclose($handle);

            // Check if it's a ZIP file (Excel .xlsx)
            if ($firstBytes === "PK\x03\x04") {
                return self::parseExcel($filename);
            }
        }

        // Default to CSV parsing
        return self::parseCSV($filename);
    }

    private static function parseCSV($filename) {
        $rows = [];
        
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
            
            $xlsx = new self();
            $xlsx->sheets[0] = $rows;
            return $xlsx;
        } else {
            self::$error = "Cannot open CSV file";
            return false;
        }
    }

    private static function parseExcel($filename) {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            self::$error = "PHP ZIP extension is not installed. Please enable it in php.ini or use CSV format instead.";
            return false;
        }

        $xlsx = new self();
        
        try {
            $zip = new ZipArchive;
            if ($zip->open($filename) !== true) {
                self::$error = "Cannot open Excel file";
                return false;
            }

            // Read shared strings
            $sharedStrings = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xmlObject = @simplexml_load_string($xml);
                if ($xmlObject !== false) {
                    foreach ($xmlObject->si as $si) {
                        $sharedStrings[] = (string)$si->t;
                    }
                }
            }

            // Read worksheet
            $worksheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            if ($worksheet === false) {
                self::$error = "Cannot read worksheet";
                return false;
            }

            $xmlObject = @simplexml_load_string($worksheet);
            if ($xmlObject === false) {
                self::$error = "Cannot parse worksheet XML";
                return false;
            }

            $rows = [];
            foreach ($xmlObject->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $cell) {
                    $value = '';
                    
                    // Check cell type
                    $type = (string)$cell['t'];
                    
                    if ($type == 's') {
                        // Shared string
                        $index = (int)$cell->v;
                        $value = isset($sharedStrings[$index]) ? $sharedStrings[$index] : '';
                    } else {
                        // Direct value
                        $value = (string)$cell->v;
                    }
                    
                    $rowData[] = $value;
                }
                $rows[] = $rowData;
            }

            $xlsx->sheets[0] = $rows;
            return $xlsx;

        } catch (Exception $e) {
            self::$error = "Error: " . $e->getMessage();
            return false;
        }
    }

    public function rows($sheetIndex = 0) {
        return isset($this->sheets[$sheetIndex]) ? $this->sheets[$sheetIndex] : [];
    }

    public static function parseError() {
        return self::$error;
    }
}
?>