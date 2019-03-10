<?php
function vaffaschool_get_pdo() {
    // check if file OK
    if (!is_file(VAFFASCHOOL_SQLITE_FILE)) {
        // this function is used only when rebuilding DB, it's probably fine if we can't find file
    }
    if (!is_readable(VAFFASCHOOL_SQLITE_FILE)) {
        // this function is used only when rebuilding DB, it's probably fine if we can't find file
    }
    // check if we have Sqlite
    $has_sqlite = false;
    $avail_drivers = \PDO::getAvailableDrivers();
    foreach ($avail_drivers as $driver_name) {
        if ($driver_name == 'sqlite') {
            $has_sqlite = true;
        }
    }
    if (!$has_sqlite) {
        throw new \Exception('cannot read schools, Sqlite PHP extension missing');
    }

    $pdo = new \PDO('sqlite:/' . VAFFASCHOOL_SQLITE_FILE);
    if (!$pdo) {
        throw new \Exception("cannot open the database");
    }

    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $pdo;
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

function vaffaschool_handle_raw_record($raw_record) {
    $school_data = array_map(function($data) {
        if (is_array($data)) {
            return $data;
        }

        $data = trim($data);
        $data = str_replace(['Non Disponibile', 'Non disponibile'], '', $data);

        return $data;
    }, [
        'id' => $raw_record['miur:CODICESCUOLA'],
        'ref_id' => $raw_record['@id'],

        'schoolyear' => substr($raw_record['miur:ANNOSCOLASTICO'], 0, 4),
        'type' => $raw_record['miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA'],

        'name' => $raw_record['miur:DENOMINAZIONESCUOLA'],

        'email' => $raw_record['miur:INDIRIZZOEMAILSCUOLA'],
        'certified_email' => $raw_record['miur:INDIRIZZOPECSCUOLA'],
        'website' => $raw_record['miur:SITOWEBSCUOLA'],

        'address' => $raw_record['miur:INDIRIZZOSCUOLA'],
        'postcode' => $raw_record['miur:CAPSCUOLA'],
        'cad_code' => $raw_record['miur:CODICECOMUNESCUOLA'],
        // '' => $data['miur:AREAGEOGRAFICA'],
        'city_name' => $raw_record['miur:DESCRIZIONECOMUNE'],
        // 'province_name' => $raw_record['miur:PROVINCIA'],
        // 'region_name' => $raw_record['miur:REGIONE']
    ]);

    // handle name
    $school_data['name'] = str_replace($school_data['type'], '', $school_data['name']); // remove school type
    if ($school_data['name'] == $school_data['city_name']) { // when it's the only school and called "Scuola elementare Ponte a Sieve"
        $school_data['name'] = $school_data['type'] . ' ' . $school_data['name'];
    }

    // handle email
    if (!$school_data['email']) {
        // set government one
        $school_data['email'] = strtolower($school_data['id']) . '@istruzione.it';
    }

    // handle website
    $school_data['website'] = vaffaschool_fix_url($school_data['website']);

    // handle location data
    if (!$school_data['cad_code']) {
        throw new \Exception('postcode missing, cannot get location (ID: ' . $school_data['id'] . ')');
    }

    $location = \Foorious\Komunist::getCityByCadCode($school_data['cad_code'], \Foorious\Komunist::RETURN_TYPE_ARRAY);
    if (!$location) {
        throw new \Exception('cannot find location via cadastral code (code: ' . $school_data['cad_code'] . ')');
    }
    if (!$location['province']) {
        throw new \Exception('Location is missing province info');
    }

    // add location data
    $school_data['city_name'] = $location['name'];
    $school_data['city_id'] = $location['id'];
    $school_data['province_name'] = $location['province']['name'];
    $school_data['province_id'] = $location['province']['id'];
    $school_data['province_iso_code'] = $location['province']['iso_code'];
    $school_data['region_name'] = $location['region']['name'];
    $school_data['region_id'] = $location['region']['id'];
    $school_data['nuts3_2010_code'] = $location['nuts3_2010_code'];

    // handle school parent
    $school_data['parent_school_id'] = !empty($raw_record['miur:CODICEISTITUTORIFERIMENTO']) && $raw_record['miur:CODICEISTITUTORIFERIMENTO'] != $school_data['id'] ? $raw_record['miur:CODICEISTITUTORIFERIMENTO'] : '';

    // etc (?)
    // "miur:DESCRIZIONECARATTERISTICASCUOLA": "NORMALE"

    // add debugging info
    if (@reset(explode(':', $_SERVER['HTTP_HOST'])) == 'localhost') {
        $school_data = array_merge($school_data, [
            '_raw_record' => $raw_record,
            '_location' => $location
        ]);
    }

    return $school_data;
}

function vaffaschool_get_school_groups() {
    $schools = [];

    $raw_data = \vaffaschool_get_data_from_folder(VAFFASCHOOL_RAW_DATA_DIR, explode(',', VAFFASCHOOL_RAW_DATA_FILE_TYPES), '@graph');
    if (!$raw_data) {
        throw new \Exception('no raw data');
    }

    $i=0;
    $num_problems = 0;
    foreach ($raw_data as $raw_record) {
        try {
            $school_data = vaffaschool_handle_raw_record($raw_record);
        } catch (\Exception $e) {
            // do nothing, if anything we can log this, but we have to output data and some missing data is normal
            $num_problems++;
        }

        if ($school_data['parent_school_id']) {
            if (empty($schools[$school_data['parent_school_id']])) {
                $schools[$school_data['parent_school_id']] = [];
            }

            $schools[$school_data['parent_school_id']]['schools'][$school_data['id']] = $school_data;
        } else {
            if (empty($schools[$school_data['id']])) {
                $schools[$school_data['id']] = [];
            }

            $schools[$school_data['id']]['data'] = $school_data;
        }

        $i++;
    }

    echo 'Problems with location: ' . $num_problems . '/' . count($raw_data);
    echo "\n";

    return $schools;
}

function vaffaschool_fix_url($url) {
    $url = trim($url);
    if (!$url) {
        return '';
    }

    if (strpos($url, 'http') !== 0) {
        $url = 'http://' . $url;
    }

    return $url;
}