<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(60*60*5);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/functions.php';


$schools = [];
$raw_data =  trovascuole_get_data_from_folder(SCHOOLS_RAW_DATA_DIR, explode(',', SCHOOLS_RAW_DATA_FILE_TYPES), '@graph');
foreach ($raw_data as $raw_record) {
    $school_data = self::handleRawRecord($raw_record);

    // add to others
    $schools[] = $school_data;
}

$num_schools = count($schools);
echo '<p>' . $num_schools . ' total schools found.</p>';

// do stuff with $schools
try {
    // reset DB
    unlink(SCHOOLS_DATA_SQLITE_FILE);

    // connect
    $pdo  = new PDO('sqlite:/' . SCHOOLS_DATA_SQLITE_FILE);
    if (!$pdo) {
        throw new \Exception("cannot open the database");
    }

    // create table
    $query = <<<EOF
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
        province_name string, 
        region_name string, 
        parent_school_id string, 
        parent_school_name string, 
        location_id string, 
        nuts3_2010_code string
    )
EOF;
    $result = $pdo->query($query);
    if ($result == false) {
        throw new \Exception('error while running query: ' . implode(', ', $pdo->errorInfo()));
    }    

    // write
    foreach ($schools as $school) {
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
            province_name,
            region_name, 
            parent_school_id,
            parent_school_name,
            location_id,
            nuts3_2010_code
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
            ?
        )
EOF;

        $stmt = $pdo->prepare($query);

        $stmt->bindParam(1, $school['id']);
        $stmt->bindParam(2, $school['ref_id']);
        $stmt->bindParam(3, $school['schoolyear']);
        $stmt->bindParam(4, $school['type']);
        $stmt->bindParam(5, $school['name']);
        $stmt->bindParam(6, $school['email']);
        $stmt->bindParam(7, $school['certified_email']);
        $stmt->bindParam(8, $school['website']);
        $stmt->bindParam(9, $school['address']);
        $stmt->bindParam(10, $school['cad_code']);
        $stmt->bindParam(11, $school['postcode']);
        $stmt->bindParam(12, $school['city_name']);
        $stmt->bindParam(13, $school['province_name']);
        $stmt->bindParam(14, $school['region_name']); 
        $stmt->bindParam(15, $school['parent_school']['id']);
        $stmt->bindParam(16, $school['parent_school']['name']);
        $stmt->bindParam(17, $school['location_id']);
        $stmt->bindParam(18, $school['nuts3_2010_code']);

        $stmt->execute();

        echo '+ ';
    }

    echo '<h2>Done.</h2>';

    $pdo = null; // close PDO connection
} catch (\Exception $e) {
    die($e->getMessage());
}