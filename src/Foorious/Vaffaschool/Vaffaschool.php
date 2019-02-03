<?php
namespace Foorious\Vaffaschool;

require_once realpath(__DIR__ . '/../../../') . '/config.php';

class Vaffaschool {
    private const VAFFASCHOOL_SQLITE_FILE = VAFFASCHOOL_SQLITE_FILE;

    // search
    private const SEARCH_ALGO_SIMPLE = 'simple';
    private const SEARCH_ALGO_FUZZY = 'fuzzy';
    private const SEARCH_ALGO = self::SEARCH_ALGO_FUZZY;
    private const SEARCH_SCHOOL_NAME_MULTIPLIER = 50;
    private const SEARCH_CITY_NAME_MULTIPLIER = 80;

    private static function getPdo() {
        // check if file OK
        if (!is_file(VAFFASCHOOL_SQLITE_FILE)) {
            echo VAFFASCHOOL_SQLITE_FILE;
            throw new \Exception('cannot find DB file');
        }
        if (!is_readable(VAFFASCHOOL_SQLITE_FILE)) {
            throw new \Exception('cannot read schools, DB file is not readable');
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

    // fix some data, separate parent school from school
    private static function getSchoolFromRow($row) {
        // clean up row
        foreach ($row as $k=>$v) {
            if (is_numeric($k)) {
                unset($row[$k]);
            }
        }

        // separate school from parent
        $school_data = [];
        $parent_school_data = [];
        foreach ($row as $k=>$v) {
            if (strpos($k, 'parent_school_') === 0) {
                $parent_school_data[str_replace('parent_school_', '', $k)] = $v;
                unset($school_data[$k]);
            } else {
                $school_data[$k] = $v;
            }
        }
        if (!empty($row['parent_school_id'])) {
            $school_data['parent_school_id'] = $parent_school_data['id']; // for compatibility
            $school_data['parent_school'] = $parent_school_data;
        }

        // fix other data on the fly
        $school_data['name'] = trim($school_data['name']);

        return $school_data;
    }

    private static function match($needles, $haystack) {
        foreach($needles as $needle){
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function matchGetScore($needles, $haystack) {
        $num_matches = 0;

        foreach($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                $num_matches++;
            }
        }

        return $num_matches / count($needles);
    }

    private static function matchAll($needles, $haystack) {
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

    // get all schools
    public static function getSchools() {
        $schools = [];
        $raw_data = [];

        $db = new \SQLite3(VAFFASCHOOL_SQLITE_FILE);
        $results = $db->query('SELECT * FROM schools');
        while ($row = $results->fetchArray()) {
            $schools[] = self::getSchoolFromRow($row);
        }

        return $schools;
    }

    public static function getSchoolById($school_id) {
        $pdo = self::getPdo();
        if (!$pdo) {
            throw new \Exception('no PDO');
        }

        $query = "SELECT * FROM schools WHERE id = :school_id";
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':school_id' => $school_id
            ]);
            $school = self::getSchoolFromRow($stmt->fetch(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            // fail silently
        }

        return $school;
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
            $search = str_ireplace(['scuola primaria'], '', $search);
            $search = str_replace([',', ';', '.'], ' ', $search);
            $search = trim($search);
            $search = str_replace('   ', ' ', $search);
            $search = str_replace('  ', ' ', $search);
            $search = trim($search);
            $needles = explode(' ', $search);

            // get schools that could match our search key
            $statements = [];
            $params = [];
            for ($i=0; $i<count($needles); $i++) {
                $needle = trim($needles[$i]);

                $skip_needle = false;
                // ignore common words (articles, pronouns, very common words etc.)
                $words = [
                    'di',
                    'del',
                    'dei',
                    'dello',
                    'della',
                    'a',
                    'al',
                    'allo',
                    'alla',
                    'alle',
                    'SCUOLA',
                    'Istituto',
                    'Comprensivo',
                    'primaria',
                    'primaria',
                    'Plesso',
                    'san',
                    'santo',
                    'santa',
                    'Materna',
                    'Infanzia'
                ];
                foreach ($words as $word) {
                    if (strtolower($needle) == strtolower($word)) {
                        $skip_needle = true;

                        break;
                    }
                }
                // ignore words that are too short
                if (strlen($needle) < 5) {
                    $skip_needle = true;
                }

                if ($skip_needle) {
                   continue;
                }

                $param_key = ":needle_" . (count($params)+1);
                $statements[] = "name LIKE $param_key OR city_name LIKE $param_key";
                $params[$param_key] = '%' . $needle . '%';

                // also search by ID
                $param_key_exact = $param_key . '_exact';
                $statements[] = "id LIKE $param_key_exact";
                $params[$param_key_exact] = $needle;
            }

            $pdo = self::getPdo();
            if (!$pdo) {
                throw new \Exception('no PDO');
            }
            $query = "SELECT * FROM schools WHERE " . implode(' OR ', $statements);

            try {
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $schools[] = self::getSchoolFromRow($row);
                }
            } catch (\Exception $e) {
                // fail silently
            }

            // refine results
            $matches = [];
            foreach ($schools as $school) {
                $score = 0;

                // adjust score by keywords
                $score = 0;

                // do simple search first
                $school_name_score = self::matchGetScore($needles, $school['name']);
                $city_name_score = self::matchGetScore($needles, $school['city_name']);

                // if search key not in name, it's probably a crappy match
                if (!$school_name_score) {
                    // continue;
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

                // fix some data
                $school['name'] = trim($school['name']);

                // add some data
                if (!empty($school['parent_school_id'])) {
                    $school['parent_school'] = self::getSchoolById($school['parent_school_id']);
                }
                $school['sibling_schools'] = [
                    'COMING',
                    'SOON'
                ];

                // add dev-only some data
                $school = array_merge($school, [
                    '_score' => $score,
                    '_score_details' => [
                        'school_name_score' => $school_name_score,
                        'city_name_score' => $city_name_score,
                        'fuzzy_search_score' => $fuzz_score
                    ]
                ]);

                $matches[] = $school;
            }

            // sort matches by score
            $matched_schools = array_reverse(array_values(array_sort($matches, function ($school) {
                return $school['_score'];
            })));

            return $matched_schools;
        } catch (\Exception $e) {
            throw new \Exception('error while searching for schools: ' . $e->getMessage());
        }
    }
}
