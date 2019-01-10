<?php
function vaffaschool_match($needles, $haystack) {
    foreach($needles as $needle){
        if (stripos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function vaffaschool_match_get_score($needles, $haystack) {
    $num_matches = 0;

    foreach($needles as $needle) {
        if (stripos($haystack, $needle) !== false) {
            $num_matches++;
        }
    }
    
    return $num_matches / count($needles);
}

function vaffaschool_match_all($needles, $haystack) {
    if (empty($needles)){
        return false;
    }

    foreach($needles as $needle) {
        if (stripos($haystack, $needle) == false) {
            return false;
        }
    }
    return true;
}

// looks for files in a folder, gets the data, and turns it into an array
function vaffaschool_get_data_from_folder($folder_path, $file_types, $json_root_prop='') {
    $CSV_SEPARATOR = ';';

    $records = [];

    // find files to process
    $files = [];
    $processed_files = [];

    $dir = new \RecursiveDirectoryIterator($folder_path);
    $iterator = new \RecursiveIteratorIterator($dir);
    while ($iterator->valid()) {
        if (!$iterator ->isDot()) {
            $full_path = $iterator->getPath() . '/' . $iterator->getFilename();
            if (!in_array($full_path, $processed_files)) {
                $ext = strtolower(pathinfo($iterator->getFilename(), PATHINFO_EXTENSION));
                if (in_array($ext, $file_types)) {
                    $files[] = [
                        'path' => $iterator->getPath(),
                        'filename' => $iterator->getFilename(),
                        'full_path' => $full_path,

                        'fields' => [],
                        'rows' => []
                    ];
                }
            }
            $processed_files[] = $full_path;
        }
        $iterator->next();
    }
    unset($dir);
    unset($iterator);

    foreach ($files as $i => $file_data) {
        try {
            $curr_file_path = $file_data['full_path'];    

            if (!is_readable($curr_file_path)) {
                throw new Exception('file not readable: ' . $curr_file_path);
            }        

            // turn file contents into array
            $fields = [];
            $rows = [];
            $ext = strtolower(pathinfo($curr_file_path, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'xls':
                case 'xlsx':
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader("Xlsx");
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($curr_file_path);
                    $worksheet = $spreadsheet->getActiveSheet();

                    foreach ($worksheet->getRowIterator() as $rows_i => $row) {
                        $cellIterator = $row->getCellIterator();
                        $cellIterator->setIterateOnlyExistingCells(true);

                        if ($rows_i == 1) {
                            // header with fields
                            $fields_i = 0;
                            foreach ($cellIterator as $cell) {
                                $fields[] = $cell->getValue();

                                if ($fields_i >= MAX_NUM_COLS) {
                                    break;
                                }                        

                                $fields_i++;
                            }                    

                            // NOTE: validate format before continuing (?)
                        } else {
                            // regular row
                            $cells = [];
                            $cells_i = 0;
                            foreach ($cellIterator as $cell) {
                                $key = @$fields[$cells_i];
                                $cells[$key] = $cell->getValue();
            
                                if ($cells_i >= MAX_NUM_COLS) {
                                    break;
                                }
            
                                $cells_i++;
                            }

                            // there are many extra rows. Let's say that we don't support null rows: if there's a row with no values, we move on right away
                            $is_empty_row = true;
                            $empty_row_i = 0;
                            foreach ($cells as $value) {
                                if (($empty_row_i + 1) >= MAX_NUM_COLS) {
                                    break;
                                }

                                if ($value) {
                                    $is_empty_row = false;

                                    break;
                                }

                                $empty_row_i++;
                            }
                            if ($is_empty_row && DIE_AT_EMPTY_ROW) {
                                echo '<p class="info">Found empty row, move on</p>';

                                break;
                            } else {
                                // add row regularly
                                $rows[] = $cells;
                            }
                        }
                    }
                    // free some memory (maybe)
                    unset($worksheet);
                    unset($spreadsheet);
                    unset($reader);
                    break;
                case 'csv':
                    $csv_str = file_get_contents($curr_file_path);
                    $csv_data = explode("\n", $csv_str);

                    $fields = explode($CSV_SEPARATOR, $csv_data[0]);

                    for ($csv_data_i=1; $csv_data_i<count($csv_data); $csv_data_i++) {
                        if ($csv_data[$csv_data_i] === '') {
                            continue;
                        }
                        
                        $csv_data_arr = explode($CSV_SEPARATOR, $csv_data[$csv_data_i]);

                        $cells = [];
                        foreach ($fields as $field_index => $field_name) {
                            if (!isset($csv_data_arr[$field_index])) {
                                continue;
                            }
                            $cells[$field_name] = $csv_data_arr[$field_index];
                        }

                        $rows[] = $cells;
                    }
                    $csv_data_rows_i = 0;
                    break;
                    case 'json':
                        $json_str = file_get_contents($curr_file_path);
                        if (!$json_str) {
                            throw new \Exception('unable to get JSON data');
                        }
                        $json_data = json_decode($json_str, true);
                        if (!$json_data) {
                            throw new \Exception('unable to parse JSON');
                        }

                        $rows = $json_root_prop ? $json_data[$json_root_prop] : $json_data;
                        break;
                default:
                    throw new \Exception('file type invalid');
            }

            $records = array_merge($records, $rows);

            unset($rows);
        } catch (Exception $e) {
            echo '<h2 style="background-color: red;">ERR: ' . $e->getMessage() . '</h2>';
        }
    }

    return $records;
}