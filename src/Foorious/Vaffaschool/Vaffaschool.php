<?php
namespace Foorious\Vaffaschool;

define('VAFFASCHOOL_BASE_DIR', realpath(__DIR__ . '/../../../'));
define('VAFFASCHOOL_SCHOOLS_RAW_DATA_DIR', rtrim(VAFFASCHOOL_BASE_DIR . '/data/raw/MIUR/2018/', '/'));
define('VAFFASCHOOL_SCHOOLS_DATA_SQLITE_FILE', VAFFASCHOOL_BASE_DIR . '/data/schools.sqlite');

class Vaffaschool {
    private const SCHOOLS_RAW_DATA_DIR = VAFFASCHOOL_SCHOOLS_RAW_DATA_DIR; // location of raw data
    private const SCHOOLS_RAW_DATA_FILE_TYPES = 'json'; // extensions of files that we want to process, separated by comma for multiple file types
    private const SCHOOLS_DATA_SQLITE_FILE = VAFFASCHOOL_SCHOOLS_DATA_SQLITE_FILE; // location of Sqlite DB file

    // search
    private const SEARCH_SCHOOLS_DATA_USE_DB = true; // whether to use DB while searching, or scan raw files one by one
    private const SEARCH_ALGO_SIMPLE = 'simple';
    private const SEARCH_ALGO_FUZZY = 'fuzzy';
    private const SEARCH_ALGO = self::SEARCH_ALGO_FUZZY;

    // search adjustments
    /// adjust weight for matching name vs. location
    private const SEARCH_SCHOOL_NAME_MULTIPLIER = 50;
    private const SEARCH_CITY_NAME_MULTIPLIER = 80;

    public static function handleRawRecord($raw_record) {
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

        // handle location data
        try {
            if (!$school_data['cad_code']) {
                throw new \Exception('postcode missing, cannot get location (ID: ' . $school_data['id'] . ')');
            }

            $location = \Foorious\Komunist::getCityByCadCode($school_data['cad_code'], \Foorious\Komunist::RETURN_TYPE_ARRAY);
            if (!$location) {
                throw new \Exception('cannot find location via cadastral code (code: ' . $school_data['cad_code'] . ')');
            }

            // add location data
            $school_data['city_name'] = $location['name'];
            $school_data['city_id'] = $location['id'];
            $school_data['nuts3_2010_code'] = $location['nuts3_2010_code'];
            $school_data['province_abbr'] = $location['license_plate_code'];
            $school_data['region_name'] = $location['region']['name'];
        } catch (\Exception $e) {
            // do nothing, if anything we can log this, but we have to output data and some missing data is normal
        }

        // handle school parent
        if (!empty($raw_record['miur:CODICEISTITUTORIFERIMENTO'])) {
            // "": "FIIC853009",
            // "": "COMPAGNI - CARDUCCI",
            // "miur:INDICAZIONESEDEDIRETTIVO": "NO",
            // "miur:INDICAZIONESEDEOMNICOMPRENSIVO": "Non Disponibile",
            // "miur:SEDESCOLASTICA": "NO",
            if ($raw_record['miur:CODICEISTITUTORIFERIMENTO'] != $school_data['id']) {
                $school_data['parent_school'] = [
                    'id' => $raw_record['miur:CODICEISTITUTORIFERIMENTO'],
                    'name' => $raw_record['miur:DENOMINAZIONEISTITUTORIFERIMENTO']
                ];
            }
        }

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

    // get all schools
    public static function getSchools($use_db=self::SEARCH_SCHOOLS_DATA_USE_DB) {
        $schools = [];
        $raw_data = [];

        if ($use_db) {
            $db = new \SQLite3(self::SCHOOLS_DATA_SQLITE_FILE);
            $results = $db->query('SELECT * FROM schools');
            while ($row = $results->fetchArray()) {
                $schools[] = $row;
            }
        } else {
            $raw_data = \vaffaschool_get_data_from_folder(self::SCHOOLS_RAW_DATA_DIR, explode(',', self::SCHOOLS_RAW_DATA_FILE_TYPES), '@graph');

            $i=0;
            foreach ($raw_data as $raw_record) {
                $school_data = self::handleRawRecord($raw_record);

                // add to others
                $schools[] = $school_data;

                $i++;
            }
        }

        return $schools;
    }

    // search schools
    public static function getSchoolsBySearchKey($search_key) {
        try {
            // cut short if no search
            if (empty($search_key)) {
                throw new \Exception('search keyword mandatory');
            }

            $schools = [];
            $raw_data = [];

            // figure out needles
            $search = $search_key;
            $search = str_replace([',', ';', '.'], ' ', $search);
            $search = trim($search);
            $search = str_replace('   ', ' ', $search);
            $search = str_replace('  ', ' ', $search);
            $needles = explode(' ', $search);

            // get schools that could match our search key
            $db = new \SQLite3(self::SCHOOLS_DATA_SQLITE_FILE);
            $query = "SELECT * FROM schools WHERE " . implode(' OR ', array_map(function($needle) {
                return "name LIKE '%$needle%' OR city_name LIKE '%$needle%'";
            }, $needles));
            $results = $db->query($query);
            while ($row = $results->fetchArray()) {
                $schools[] = $row;
            }

            $num_schools = count($schools);

            // refine results
            $matches = [];
            foreach ($schools as $school) {
                $score = 0;

                // adjust score by keywords
                $score = 0;

                // do simple search first
                $school_name_score = \vaffaschool_match_get_score($needles, $school['name']);
                $city_name_score = \vaffaschool_match_get_score($needles, $school['city_name']);

                // if search key not in name, it's probably a crappy match
                if (!$school_name_score) {
                    continue;
                }

                $school_name_score *= self::SEARCH_SCHOOL_NAME_MULTIPLIER;
                $city_name_score *= self::SEARCH_CITY_NAME_MULTIPLIER;

                switch (self::SEARCH_ALGO) {
                    case self::SEARCH_ALGO_SIMPLE:
                        $score = $school_name_score + $city_name_score;
                        break;
                    case self::SEARCH_ALGO_FUZZY:
                        // adjust score with fuzzy search
                        $fuzz = new \FuzzyWuzzy\Fuzz();
                        $fuzz_score = $fuzz->tokenSortRatio($search, $school['name'] . ' ' . $school['city_name']);

                        $score = $school_name_score + $city_name_score + $fuzz_score;
                        break;
                    default:
                        throw new \Exception('Invalid search algorithm');
                }

                $key = $score + mt_rand() / mt_getrandmax();

                $school = array_merge($school, [
                    '_debugging' => [
                        'school_name_score' => $school_name_score,
                        'city_name_score' => $city_name_score,
                        'fuzzy_search_score' => $fuzz_score
                    ]
                ]);

                $matches[$key] = $school;
            }

            // sort matches by score
            if (is_array($matches) && count($matches)) {
                krsort($matches);
            }
            $matched_schools = [];
            foreach ($matches as $score => $school) {
                // clean up name
                $school['name'] = trim($school['name']);

                // add score
                $school['_score'] = $score;

                $matched_schools[] = $school;
            }

            return $matched_schools;
        } catch (\Exception $e) {
            throw new \Exception('error while searching for schools: ' . $e->getMessage());
        }
    }
}