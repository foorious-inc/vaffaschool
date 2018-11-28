<?php
error_reporting(E_ALL);
ini_set('display_errors', $_SERVER['HTTP_HOST'] == 'localhost' && 1);
ini_set('memory_limit', '512M');
set_time_limit(60*60*5);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/functions.php';

define('MAX_MATCHES', 100);
define('SEARCH_ALGO', 'fuzzy');

// array holding allowed origin domains. can be '*' for all, or array for specific domains
$allowed_origins = '*'; /* array(
    '(http(s)://)?(www\.)?my\-domain\.com'
);*/
$allow = false;
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] != '') {
    if (is_array($allowed_origins)) {
        foreach ($allowed_origins as $allowed_origin) {
            if (preg_match('#' . $allowed_origin . '#', $_SERVER['HTTP_ORIGIN'])) {
                $allow = true;
                break;
            }
        }
    } else {
        if ($allowed_origins == '*') {
            $allow = true;
        }
    }
}
if ($allow) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 1000');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

// cut it short if OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);

    exit;
}

try {
    // cut short if no search
    if (empty($_GET['q'])) {
        throw new \Exception('search keyword mandatory');
    }

    $script_time_start = microtime(true);

    // get schools
    $school_cache_time_start = microtime(true);

    $schools = [];
    if (SEARCH_SCHOOLS_DATA_USE_DB) {
        throw new \Exception('DB not supported yet');
    } else {
        $raw_data =  get_data_from_folder(SCHOOLS_RAW_DATA_DIR, explode(',', SCHOOLS_RAW_DATA_FILE_TYPES), '@graph');
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

        $school_name_score *= SEARCH_SCHOOL_NAME_MULTIPLIER;
        $city_name_score *= SEARCH_CITY_NAME_MULTIPLIER;                

        switch (SEARCH_ALGO) {
            case SEARCH_ALGO_SIMPLE:
                $score = $school_name_score + $city_name_score;
                break;            
            case SEARCH_ALGO_FUZZY:
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
