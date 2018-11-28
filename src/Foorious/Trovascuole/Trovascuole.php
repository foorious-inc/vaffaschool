<?php
namespace Foorious\Trovascuole;

define('TROVASCUOLE_SCHOOLS_RAW_DATA_DIR', rtrim($_SERVER['DOCUMENT_ROOT'] . '/data/raw/MIUR/2018/', '/'));
define('TROVASCUOLE_SCHOOLS_DATA_SQLITE_FILE', $_SERVER['DOCUMENT_ROOT']  . '/data/schools.sqlite');

class Trovascuole {
    private const SCHOOLS_RAW_DATA_DIR = TROVASCUOLE_SCHOOLS_RAW_DATA_DIR; // location of raw data
    private const SCHOOLS_RAW_DATA_FILE_TYPES = 'json'; // extensions of files that we want to process, separated by comma for multiple file types
    private const SCHOOLS_DATA_SQLITE_FILE = TROVASCUOLE_SCHOOLS_DATA_SQLITE_FILE; // location of Sqlite DB file
    
    // search
    private const SEARCH_SCHOOLS_DATA_USE_DB = false; // whether to use DB while searching, or scan raw files one by one
    private const SEARCH_ALGO_SIMPLE = 'simple';
    private const SEARCH_ALGO_FUZZY = 'fuzzy';
    private const SEARCH_ALGO = self::SEARCH_ALGO_FUZZY;
    
    // search adjustments
    /// adjust weight for matching name vs. location
    private const SEARCH_SCHOOL_NAME_MULTIPLIER = 50;
    private const SEARCH_CITY_NAME_MULTIPLIER = 80;

    // get all schools
    public static function getSchools() {
        try {
            $schools = [];
            if (self::SEARCH_SCHOOLS_DATA_USE_DB) {
                throw new \Exception('DB not supported yet');
            } else {
                $raw_data = get_data_from_folder(self::SCHOOLS_RAW_DATA_DIR, explode(',', self::SCHOOLS_RAW_DATA_FILE_TYPES), '@graph');
                foreach ($raw_data as $raw_record) {
                    $school_data = school_data_cleanup($raw_record);
            
                    // add to others
                    $schools[] = $school_data;
                }
            }
            $num_schools = count($schools);
        
            $school_cache_time_end = microtime(true);
            $school_cache_time = $school_cache_time_end - $school_cache_time_start;
        
            // do stuff with $schools
            $matches = [];
            foreach ($schools as $school) {
                if ($school['schoolyear'] != date('Y')) {
                    continue;
                }
        
                if (is_array($matches) && count($matches) > MAX_MATCHES) {
                    break;
                }
        
                $score = 0;
        
                // adjust score by keywords
                $search = $_GET['q'];
                $search = str_replace([',', ';', '.'], ' ', $search);
                $search = trim($search);
                $search = str_replace('   ', ' ', $search);
                $search = str_replace('  ', ' ', $search);
                $needles = explode(' ', $search);            
        
                $score = 0;
        
                // do simple search first
                $school_name_score = trovascuole_match_get_score($needles, $school['name']);
                $city_name_score = trovascuole_match_get_score($needles, $school['city_name']);
        
                // if likely not a match, cut it short
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
        
            $script_time_end = microtime(true);
            $script_time = $script_time_end - $script_time_start;
        
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'debug' => [
                    'num_processed' => $num_schools,
                    'script_time' => $script_time . 's',
                    'cache_time' => $school_cache_time . 's',
                ],
                'num_records' => count($matched_schools),
                'data' => [
                    'schools' => $matched_schools
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    // // search schools
    // public static function getSchoolsBySearchKey($search_key) {
    //     try {
    //         // cut short if no search
    //         if (empty($_GET['q'])) {
    //             throw new \Exception('search keyword mandatory');
    //         }
        
    //         $script_time_start = microtime(true);
        
    //         // get schools
    //         $school_cache_time_start = microtime(true);
        
    //         $schools = [];
    //         if (self::SEARCH_SCHOOLS_DATA_USE_DB) {
    //             throw new \Exception('DB not supported yet');
    //         } else {
    //             $raw_data =  get_data_from_folder(self::SCHOOLS_RAW_DATA_DIR, explode(',', self::SCHOOLS_RAW_DATA_FILE_TYPES), '@graph');
    //             foreach ($raw_data as $raw_record) {
    //                 $school_data = school_data_cleanup($raw_record);
            
    //                 // add to others
    //                 $schools[] = $school_data;
    //             }
    //         }
    //         $num_schools = count($schools);
        
    //         $school_cache_time_end = microtime(true);
    //         $school_cache_time = $school_cache_time_end - $school_cache_time_start;
        
    //         // do stuff with $schools
    //         $matches = [];
    //         foreach ($schools as $school) {
    //             if ($school['schoolyear'] != date('Y')) {
    //                 continue;
    //             }
        
    //             if (is_array($matches) && count($matches) > MAX_MATCHES) {
    //                 break;
    //             }
        
    //             $score = 0;
        
    //             // adjust score by keywords
    //             $search = $_GET['q'];
    //             $search = str_replace([',', ';', '.'], ' ', $search);
    //             $search = trim($search);
    //             $search = str_replace('   ', ' ', $search);
    //             $search = str_replace('  ', ' ', $search);
    //             $needles = explode(' ', $search);            
        
    //             $score = 0;
        
    //             // do simple search first
    //             $school_name_score = trovascuole_match_get_score($needles, $school['name']);
    //             $city_name_score = trovascuole_match_get_score($needles, $school['city_name']);
        
    //             // if likely not a match, cut it short
    //             if (!$school_name_score) {
    //                 continue;
    //             }
        
    //             $school_name_score *= self::SEARCH_SCHOOL_NAME_MULTIPLIER;
    //             $city_name_score *= self::SEARCH_CITY_NAME_MULTIPLIER;                
        
    //             switch (self::SEARCH_ALGO) {
    //                 case self::SEARCH_ALGO_SIMPLE:
    //                     $score = $school_name_score + $city_name_score;
    //                     break;            
    //                 case self::SEARCH_ALGO_FUZZY:
    //                     // adjust score with fuzzy search
    //                     $fuzz = new \FuzzyWuzzy\Fuzz();
    //                     $fuzz_score = $fuzz->tokenSortRatio($search, $school['name'] . ' ' . $school['city_name']);
        
    //                     $score = $school_name_score + $city_name_score + $fuzz_score;            
    //                     break;
    //                 default: 
    //                     throw new \Exception('Invalid search algorithm');
    //             }
        
    //             $key = $score + mt_rand() / mt_getrandmax();
        
    //             $school = array_merge($school, [
    //                 '_debugging' => [
    //                     'school_name_score' => $school_name_score,
    //                     'city_name_score' => $city_name_score,
    //                     'fuzzy_search_score' => $fuzz_score
    //                 ]
    //             ]);        
        
    //             $matches[$key] = $school;    
    //         }
        
    //         // sort matches by score
    //         if (is_array($matches) && count($matches)) {
    //             krsort($matches);
    //         }
    //         $matched_schools = [];
    //         foreach ($matches as $score => $school) {
    //             // clean up name
    //             $school['name'] = trim($school['name']);
                
    //             // add score
    //             $school['_score'] = $score;
        
    //             $matched_schools[] = $school;
    //         }
        
    //         $script_time_end = microtime(true);
    //         $script_time = $script_time_end - $script_time_start;
        
    //         http_response_code(200);
    //         header('Content-Type: application/json');
    //         echo json_encode([
    //             'ok' => true,
    //             'debug' => [
    //                 'num_processed' => $num_schools,
    //                 'script_time' => $script_time . 's',
    //                 'cache_time' => $school_cache_time . 's',
    //             ],
    //             'num_records' => count($matched_schools),
    //             'data' => [
    //                 'schools' => $matched_schools
    //             ]
    //         ]);
    //     } catch (\Exception $e) {
    //         http_response_code(200);
    //         header('Content-Type: application/json');
    //         echo json_encode([
    //             'ok' => false,
    //             'error' => $e->getMessage()
    //         ]);
    //     }
    // }
}