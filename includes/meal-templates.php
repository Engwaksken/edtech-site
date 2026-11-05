<?php
declare(strict_types=1);

/*
 * M&E bulk-upload templates - the single source of truth for the column
 * layout of every M&E data type. Used by:
 *   - meal-data.php                (Download Template buttons)
 *   - includes/portal-process.php  (upload_meal_csv importer)
 *
 * A downloaded template is BLANK: a header row plus Excel dropdowns on the
 * "Template" sheet, and examples / allowed values on a separate
 * "Instructions" sheet. It never contains example or real data rows, so
 * nothing fake is imported if a venture uploads it as-is.
 */

if (!function_exists('meal_template_field_specs')) {
    function meal_template_field_specs(string $type): array
    {
        $yes_no = ['Yes', 'No'];

        $specs = [
            'participants' => [
                ['label' => 'Date (DD/MM/YYYY)',                      'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Learner Number (Unique ID)',            'options' => null, 'example' => 'UG-EDT-001'],
                ['label' => 'Full Name (First Name, Surname)',       'options' => null, 'example' => 'Jane Namukasa'],
                ['label' => 'Phone',                                 'options' => null, 'example' => '+256700000000'],
                ['label' => 'Email',                                 'options' => null, 'example' => 'jane@example.com'],
                ['label' => 'Enrollment Category',                   'options' => ['New', 'Returning'], 'example' => 'New'],
                ['label' => 'Gender',                                'options' => ['Male', 'Female'], 'example' => 'Female'],
                ['label' => 'Specific Location (District/Village)',  'options' => null, 'example' => 'Kampala / Makindye'],
                ['label' => 'Urban/Rural',                           'options' => ['Urban', 'Peri-Urban', 'Rural'], 'example' => 'Urban'],
                ['label' => 'Age of Learner',                        'options' => null, 'example' => '24'],
                ['label' => 'Displaced/Refugee Status',              'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Refugee Settlement',                    'options' => [
                    'Bidi bidi Refugee Settlement',
                    'Nakivale Refugee Settlement',
                    'Rhino Camp Refugee Settlement',
                    'Palorinya Refugee Settlement',
                    'Kyangwali Refugee Settlement',
                    'Adjumani Settlements',
                    'Kyaka II Refugee Settlement',
                    'Other (please specify)'
                ], 'example' => 'Nakivale Refugee Settlement'],
                ['label' => 'Other Refugee Settlement (please specify)', 'options' => null, 'example' => ''],
                ['label' => 'PWD Status',                            'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Type of Impairment',                    'options' => null, 'example' => ''],
                ['label' => 'Education Level',                       'options' => ['Primary', 'Secondary', 'TVET', 'Tertiary'], 'example' => 'TVET'],
                ['label' => 'Learner Status',                        'options' => ['Active', 'On hold', 'Dropped', 'Completed', 'Certified'], 'example' => 'Active'],
                ['label' => 'Verified Learner Outcomes',              'options' => ['Increased pass rates', 'Improved grades in STEM', 'Increased Agency and Voice', 'Others please specify'], 'example' => 'Increased pass rates'],
                ['label' => 'Other Verified Learner Outcome',         'options' => null, 'example' => ''],
                ['label' => 'YIW (Youth In Work) Before',            'options' => ['Self-employment', 'Wage employment', 'No'], 'example' => 'No'],
                ['label' => 'Transformation Objective',              'options' => null, 'example' => 'Education & Skilling'],
                ['label' => 'After-work Status',                     'options' => ['New', 'Additional', 'Improved'], 'example' => 'New'],
                ['label' => 'After-work Pathway',                    'options' => ['Self-employment', 'Wage employment'], 'example' => 'Self-employment'],
            ],
            'teachers' => [
                ['label' => 'Date (DD/MM/YYYY)',           'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Teacher/Educator Name',       'options' => null, 'example' => 'Grace Achieng'],
                ['label' => 'Gender',                      'options' => ['Male', 'Female', 'Other'], 'example' => 'Female'],
                ['label' => 'Age Category',                'options' => ['13-20', '21-35', 'Above 35'], 'example' => '21-35'],
                ['label' => 'Refugee/Displaced Status',    'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Host Community',              'options' => $yes_no, 'example' => 'Yes'],
                ['label' => 'Urban/Rural',               'options' => ['Rural', 'Urban', 'Peri-Urban'], 'example' => 'Rural'],
                ['label' => 'PWD Status',                  'options' => $yes_no, 'example' => 'No'],
            ],
            'schools' => [
                ['label' => 'Date (DD/MM/YYYY)',           'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'School / Institution Name',  'options' => null, 'example' => "St. Mary's Secondary School"],
                ['label' => 'Geographical Location',       'options' => ['Rural', 'Urban', 'Peri-Urban'], 'example' => 'Rural'],
                ['label' => 'Ownership Type',              'options' => ['Government-aided', 'Private'], 'example' => 'Government-aided'],
                ['label' => 'Level of School',             'options' => ['Secondary', 'Tertiary', 'BTVET'], 'example' => 'Secondary'],
            ],
            'other_users' => [
                ['label' => 'Date (DD/MM/YYYY)',                       'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Gender',                                  'options' => ['Male', 'Female', 'Other'], 'example' => 'Male'],
                ['label' => 'Host Community',                          'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Urban/Rural',                           'options' => ['Rural', 'Urban', 'Peri-Urban'], 'example' => 'Urban'],
                ['label' => 'PWD Status',                              'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Education Level',                         'options' => ['Primary', 'Secondary', 'Tertiary'], 'example' => 'Tertiary'],
            ],
            'employment' => [
                ['label' => 'Placement Date (DD/MM/YYYY)',             'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Full Name (First Name, Surname)',         'options' => null, 'example' => 'David Ochieng'],
                ['label' => 'Gender',                                  'options' => ['Male', 'Female'], 'example' => 'Male'],
                ['label' => 'Age Category',                            'options' => ['13-20', '21-35', 'Above 35'], 'example' => '21-35'],
                ['label' => 'PWD Status',                              'options' => $yes_no, 'example' => 'No'],
                ['label' => 'Employment Type',                        'options' => ['Full-Time', 'Part-Time', 'Contract', 'Volunteer'], 'example' => 'Full-Time'],
                ['label' => 'Job Title / Role',                       'options' => null, 'example' => 'Software Developer'],
                ['label' => 'Status',                                 'options' => ['Active', 'Suspended', 'Terminated', 'On Leave'], 'example' => 'Active'],
            ],
            'finance' => [
                ['label' => 'Date Mobilised (DD/MM/YYYY)', 'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Amount in USD',               'options' => null, 'example' => '50000'],
                ['label' => 'Funding Form',                'options' => ['Debt / Loan', 'Grant', 'Equity', 'Convertible Note', 'Blended Finance', 'Other'], 'example' => 'Grant'],
                ['label' => 'Funding Source',              'options' => null, 'example' => 'Village Capital'],
            ],
            'partnerships' => [
                ['label' => 'Partnership Date (DD/MM/YYYY)', 'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Key Partner Name',              'options' => null, 'example' => 'Google.org'],
                ['label' => 'Type of Partnership',           'options' => ['Financial', 'Technical', 'Distribution', 'Academic', 'Government', 'Other'], 'example' => 'Financial'],
                ['label' => 'Partnership Status',            'options' => ['Active', 'Pending', 'Inactive', 'Completed'], 'example' => 'Active'],
            ],
            'revenue' => [
                ['label' => 'Revenue Month-end Date (DD/MM/YYYY)', 'options' => null, 'example' => date('d/m/Y')],
                ['label' => 'Gross Revenue Generated (UGX)',       'options' => null, 'example' => '12500000'],
                ['label' => 'Primary Revenue Stream',              'options' => null, 'example' => 'Subscription fees'],
            ],
        ];

        return $specs[$type] ?? [];
    }
}

if (!function_exists('meal_template_normalize_label')) {
    function meal_template_normalize_label($label): string
    {
        return (string)preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$label)));
    }
}

if (!function_exists('meal_template_map_columns')) {
    /**
     * Maps the uploaded header row onto the template's column order.
     * Returns ['map' => [templateIndex => uploadedIndex], 'missing' => [labels]].
     * Matching is by header name, so reordered columns still import correctly.
     */
    function meal_template_map_columns(string $type, array $headerRow): array
    {
        $uploaded = [];
        foreach (array_values($headerRow) as $j => $label) {
            $key = meal_template_normalize_label($label);
            if ($key !== '' && !isset($uploaded[$key])) {
                $uploaded[$key] = $j;
            }
        }

        $map = [];
        $missing = [];
        foreach (meal_template_field_specs($type) as $i => $field) {
            $key = meal_template_normalize_label($field['label']);
            if (isset($uploaded[$key])) {
                $map[$i] = $uploaded[$key];
            } else {
                $missing[] = $field['label'];
            }
        }

        return ['map' => $map, 'missing' => $missing];
    }
}

if (!function_exists('meal_template_reorder_row')) {
    /** Returns $row re-indexed into template column order (missing cells = ''). */
    function meal_template_reorder_row(array $row, array $map, int $columnCount): array
    {
        $row = array_values($row);
        $out = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $out[$i] = isset($map[$i]) ? trim((string)($row[$map[$i]] ?? '')) : '';
        }
        return $out;
    }
}

if (!function_exists('meal_template_is_example_row')) {
    /**
     * True when a (template-ordered) row is the sample row that older
     * templates shipped with in row 2, so it is never imported as data.
     * The date column is ignored because its example was "today".
     */
    function meal_template_is_example_row(string $type, array $row): bool
    {
        // Only a match on a free-text example (a name, ID or amount) proves it
        // is the sample; rows made only of dropdown values could be real
        // (e.g. an Other Users record of Male / No / Urban / No / Tertiary).
        $freeTextMatched = 0;
        foreach (meal_template_field_specs($type) as $i => $field) {
            $example = trim((string)($field['example'] ?? ''));
            if ($i === 0 || $example === '') {
                continue;
            }
            if (strcasecmp(trim((string)($row[$i] ?? '')), $example) !== 0) {
                return false;
            }
            if (empty($field['options'])) {
                $freeTextMatched++;
            }
        }
        return $freeTextMatched > 0;
    }
}

if (!function_exists('meal_stream_xlsx_template')) {
    /**
     * Streams a blank .xlsx template: bold header row and dropdown (data
     * validation) lists on the "Template" sheet, plus an "Instructions"
     * sheet listing each column's allowed values and an example.
     * Falls back to a header-only CSV if PhpSpreadsheet isn't installed.
     */
    function meal_stream_xlsx_template(array $fields, string $baseFilename): void
    {
        $autoload_candidates = [
            __DIR__ . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];
        $loaded = false;
        foreach ($autoload_candidates as $autoload) {
            if (file_exists($autoload)) {
                require_once $autoload;
                $loaded = true;
                break;
            }
        }

        if (!$loaded || !class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            meal_stream_csv_template_fallback($fields, $baseFilename);
            return;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template');

        $lastValidationRow = 1000; // rows pre-loaded with the dropdown

        foreach ($fields as $i => $field) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);

            $sheet->setCellValue("{$col}1", $field['label']);
            $sheet->getStyle("{$col}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}1")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FC7F10');
            $sheet->getColumnDimension($col)->setWidth(max(20, (int)(strlen((string)$field['label']) * 0.95)));

            if (!empty($field['options'])) {
                $validation = $sheet->getCell("{$col}2")->getDataValidation();
                $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
                $validation->setAllowBlank(true);
                $validation->setShowInputMessage(true);
                $validation->setShowErrorMessage(true);
                $validation->setShowDropDown(true);
                $validation->setErrorTitle('Invalid entry');
                $validation->setError('Please choose one of the listed options.');
                $validation->setPromptTitle('Pick from the list');
                $validation->setPrompt('Click the cell, then use the dropdown arrow to choose a value.');
                $validation->setFormula1('"' . implode(',', $field['options']) . '"');
                // Clone the validation onto every row of the column (rows stay empty).
                for ($row = 3; $row <= $lastValidationRow; $row++) {
                    $sheet->getCell("{$col}{$row}")->setDataValidation(clone $validation);
                }
            }
        }

        $sheet->freezePane('A2');

        $help = $spreadsheet->createSheet();
        $help->setTitle('Instructions');
        $help->setCellValue('A1', 'Fill in the "Template" sheet from row 2 (one record per row). Do not rename or delete the header row. This sheet is ignored on upload.');
        $help->mergeCells('A1:D1');
        $help->getStyle('A1')->getFont()->setBold(true);
        foreach (['A3' => 'Column', 'B3' => 'Allowed values', 'C3' => 'Example', 'D3' => 'Format'] as $cell => $text) {
            $help->setCellValue($cell, $text);
            $help->getStyle($cell)->getFont()->setBold(true);
        }
        foreach ($fields as $i => $field) {
            $r = $i + 4;
            $label = (string)$field['label'];
            $help->setCellValue("A{$r}", $label);
            $help->setCellValue("B{$r}", !empty($field['options']) ? implode(', ', $field['options']) : 'Free text');
            $help->setCellValueExplicit("C{$r}", (string)($field['example'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $help->setCellValue("D{$r}", stripos($label, 'DD/MM/YYYY') !== false ? 'Date as DD/MM/YYYY' : (!empty($field['options']) ? 'Pick from dropdown' : ''));
        }
        foreach (['A' => 40, 'B' => 70, 'C' => 30, 'D' => 22] as $c => $w) {
            $help->getColumnDimension($c)->setWidth($w);
        }
        $help->getStyle('B4:B' . (count($fields) + 3))->getAlignment()->setWrapText(true);

        $spreadsheet->setActiveSheetIndex(0);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $baseFilename . '.xlsx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

if (!function_exists('meal_stream_csv_template_fallback')) {
    /** Header-only CSV template, used only when PhpSpreadsheet is missing. */
    function meal_stream_csv_template_fallback(array $fields, string $baseFilename): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseFilename . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        $fh = fopen('php://output', 'w');
        fputcsv($fh, array_column($fields, 'label'));
        fclose($fh);
        exit;
    }
}

if (!function_exists('meal_download_csv_template')) {
    function meal_download_csv_template(string $type): void
    {
        $fields = meal_template_field_specs($type);

        if (!$fields) {
            http_response_code(400);
            echo 'Invalid template type.';
            exit;
        }

        meal_stream_xlsx_template($fields, $type . '_template');
    }
}
