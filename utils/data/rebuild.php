<?php
define('VAFFASCHOOL_ALLOW_REBUILD', false);
if (!VAFFASCHOOL_ALLOW_REBUILD) {
    die('Access forbidden!');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(60*60*5);

require_once realpath(__DIR__ . '/../../') . '/vendor/autoload.php';

require_once realpath(__DIR__ . '/../../') . '/config.php';
require_once __DIR__ . '/functions.php';
require_once realpath(__DIR__ . '/../../') . '/src/Foorious/Vaffaschool/Vaffaschool.php';
use Foorious\Vaffaschool\Vaffaschool;

// do stuff with $schools
try {
    $school_groups = vaffaschool_get_school_groups();
    if (!$school_groups) {
        throw new \Exception('NO schools found');
    }

    // at least in 1 case, subschools point to non-existent school, PUT FAKE DATA
    foreach ($school_groups as $group_id => $group) {
        if (empty($group['data'])) {
            $school_groups[$group_id]['data'] = [
                'id' => 'AOIP020000',
                'name' => 'SCONOSCIUTO'
            ];
        }
    }

    // reset DB
    unlink(VAFFASCHOOL_SQLITE_FILE);

    // connect
    $pdo  = new PDO('sqlite:/' . VAFFASCHOOL_SQLITE_FILE);
    if (!$pdo) {
        throw new \Exception("cannot open the database");
    }

    $queries = [];

    // create table
    $queries[] = <<<EOF
        CREATE TABLE schools(
            id string,
            ref_id string,
            schoolyear int,
            type string,
            name string,
            email string,
            certified_email string,
            website string,
            address string,
            cad_code string,
            postcode string,
            city_name string,
            city_id string,
            province_name string,
            province_id string,
            province_iso_code string,
            region_name string,
            region_id string,
            nuts3_2010_code string,
            parent_school_id string,
            parent_school_ref_id string,
            parent_school_schoolyear int,
            parent_school_type string,
            parent_school_name string,
            parent_school_email string,
            parent_school_certified_email string,
            parent_school_website string,
            parent_school_address string,
            parent_school_cad_code string,
            parent_school_postcode string,
            parent_school_city_name string,
            parent_school_city_id string,
            parent_school_province_name string,
            parent_school_province_id string,
            parent_school_province_iso_code string,
            parent_school_region_name string,
            parent_school_region_id string,
            parent_school_nuts3_2010_code string
        )
EOF;

    // add indexes
    $queries[] = "CREATE INDEX index_schoolyear                         ON schools (schoolyear)";
    $queries[] = "CREATE INDEX index_type                               ON schools (type)";
    $queries[] = "CREATE INDEX index_name                               ON schools (name)";
    $queries[] = "CREATE INDEX index_address                            ON schools (address)";
    $queries[] = "CREATE INDEX index_cad_code                           ON schools (cad_code)";
    $queries[] = "CREATE INDEX index_city_name                          ON schools (city_name)";
    $queries[] = "CREATE INDEX index_city_id                            ON schools (city_id)";
    $queries[] = "CREATE INDEX index_province_name                      ON schools (province_name)";
    $queries[] = "CREATE INDEX index_province_id                      ON schools (province_id)";
    $queries[] = "CREATE INDEX index_province_iso_code                      ON schools (province_iso_code)";
    $queries[] = "CREATE INDEX index_region_name                        ON schools (region_name)";
    $queries[] = "CREATE INDEX index_region_id                        ON schools (region_id)";
    $queries[] = "CREATE INDEX index_nuts3_2010_code                    ON schools (nuts3_2010_code)";
    $queries[] = "CREATE INDEX parent_school_index_schoolyear           ON schools (schoolyear)";
    $queries[] = "CREATE INDEX parent_school_index_type                 ON schools (type)";
    $queries[] = "CREATE INDEX parent_school_index_name                 ON schools (name)";
    $queries[] = "CREATE INDEX parent_school_index_address              ON schools (address)";
    $queries[] = "CREATE INDEX parent_school_index_cad_code             ON schools (cad_code)";
    $queries[] = "CREATE INDEX parent_school_index_city_name            ON schools (city_name)";
    $queries[] = "CREATE INDEX parent_school_index_city_id              ON schools (city_id)";
    $queries[] = "CREATE INDEX parent_school_index_province_name        ON schools (province_name)";
    $queries[] = "CREATE INDEX parent_school_index_province_id        ON schools (province_id)";
    $queries[] = "CREATE INDEX parent_school_index_province_iso_code        ON schools (province_iso_code)";
    $queries[] = "CREATE INDEX parent_school_index_region_name          ON schools (region_name)";
    $queries[] = "CREATE INDEX parent_school_index_region_id          ON schools (region_id)";
    $queries[] = "CREATE INDEX parent_school_index_nuts3_2010_code      ON schools (nuts3_2010_code)";

    foreach ($queries as $query) {
        $result = $pdo->query($query);
        if ($result == false) {
            throw new \Exception('error while running query: ' . implode(', ', $pdo->errorInfo()));
        }
    }

    // write
    function db_insert($school, $group) {
        $pdo = vaffaschool_get_pdo();
        if (!$pdo) {
            throw new \Exception('no PDO');
        }

        $query = <<<EOF
            INSERT INTO schools(
                id,
                ref_id,
                schoolyear,
                type,
                name,
                email,
                certified_email,
                website,
                address,
                cad_code,
                postcode,
                city_name,
                city_id,
                province_name,
                province_id,
                province_iso_code,
                region_name,
                region_id,
                nuts3_2010_code,
                parent_school_id,
                parent_school_ref_id,
                parent_school_schoolyear,
                parent_school_type,
                parent_school_name,
                parent_school_email,
                parent_school_certified_email,
                parent_school_website,
                parent_school_address,
                parent_school_cad_code,
                parent_school_postcode,
                parent_school_city_name,
                parent_school_city_id,
                parent_school_province_name,
                parent_school_province_id,
                parent_school_province_iso_code,
                parent_school_region_name,
                parent_school_region_id,
                parent_school_nuts3_2010_code
            ) VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
EOF;

        $stmt = $pdo->prepare($query);

        $i=0;
        $i++; $stmt->bindParam($i, $school['id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['ref_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['schoolyear'], \PDO::PARAM_INT);
        $i++; $stmt->bindParam($i, $school['type'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['email'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['certified_email'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['website'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['address'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['cad_code'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['postcode'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['city_name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['city_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['province_name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['province_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['province_iso_code'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['region_name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['region_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $school['nuts3_2010_code'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['ref_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['schoolyear'], \PDO::PARAM_INT);
        $i++; $stmt->bindParam($i, $group['type'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['email'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['certified_email'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['website'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['address'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['cad_code'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['postcode'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['city_name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['city_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['province_name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['province_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['province_iso_code'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['region_name'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['region_id'], \PDO::PARAM_STR);
        $i++; $stmt->bindParam($i, $group['nuts3_2010_code'], \PDO::PARAM_STR);

        $stmt->execute();

        $pdo = null; // close PDO connection
    }

    foreach ($school_groups as $group_id => $group) {
        $group_data = $group['data'];

        // insert group itself
        try {
            db_insert($group_data, []);

            echo '+ ';
        } catch (\Exception $e) {
            throw new \Exception('unable to insert group: ' . $e->getMessage());
        }

        // insert subschools
        if (!empty($group['schools'])) {
            foreach ($group['schools'] as $school_id => $school_data) {
                try {
                    db_insert($school_data, $group_data);

                    echo '+ ';
                } catch (\Exception $e) {
                    throw new \Exception('unable to insert group: ' . $e->getMessage());
                }
            }
        }
    }

    echo "\n";
    echo "\nDone.";
    echo "\n\nDon't forget to disable access to this script!";
    echo "\n";
} catch (\Exception $e) {
    echo "\n";
    echo "\n";
    echo 'ERROR: ' . $e->getMessage();
    echo "\n";

    exit;
}