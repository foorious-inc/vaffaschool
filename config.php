<?php
define('SCHOOLS_RAW_DATA_DIR', rtrim($_SERVER['DOCUMENT_ROOT']  . '/data/raw/MIUR/2018/', '/')); // location of raw data
define('SCHOOLS_RAW_DATA_FILE_TYPES', 'json'); // extensions of files that we want to process, separated by comma for multiple file types
define('SCHOOLS_DATA_SQLITE_FILE', $_SERVER['DOCUMENT_ROOT']  . '/data/schools.sqlite'); // location of Sqlite DB file

// search
define('SEARCH_SCHOOLS_DATA_USE_DB', false); // whether to use DB while searching, or scan raw files one by one
define('SEARCH_ALGO_SIMPLE', 'simple');
define('SEARCH_ALGO_FUZZY', 'fuzzy');
define('SEARCH_ALGO', SEARCH_ALGO_FUZZY);

// search adjustments
/// adjust weight for matching name vs. location
define('SEARCH_SCHOOL_NAME_MULTIPLIER', 50);
define('SEARCH_CITY_NAME_MULTIPLIER', 80);
