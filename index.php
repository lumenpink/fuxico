<?php

// Configuration
const FILENAME_LENGTH = 5;                                          // Length of the random filename
const DATA_DIR = 'data/';                                           // Must end with a slash
const RANDOM_NAME_ALLOWED_CHARS = '256789bcdfghjklmnpqrstvwxyz';    // Excluding vowels and vowels lookalikes
const MAX_UPLOAD_SIZE = 1024 * 1024 * 500;                          // 500MB
const AUTHORIZATION = ['tatanka'];                                  // Auth token
const ALLOWED_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg', 'mp3', 'wav',
    'flac', 'pdf', 'txt', 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'doc', 'docx', 'xls',
    'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'odg', 'odf', 'odc', 'odb', 'odi', 'odm',
    'odp', 'odt', 'ott', 'ots', 'otp', 'otg', 'otf', 'otc', 'otb', 'oti', 'oth', 'pdf', 'txt',
    'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'odt', 'ods', 'odp', 'odg', 'odf', 'odc', 'odb', 'odi', 'odm', 'odp', 'odt', 'ott', 'ots',
    'otp', 'otg', 'otf', 'otc', 'otb', 'oti', 'oth', 'md',
];

// Functions
function generate_name()
{
    $charactersLength = strlen(RANDOM_NAME_ALLOWED_CHARS);
    $randomString = '';
    for ($i = 0; $i < FILENAME_LENGTH; $i++) {
        $randomString .= RANDOM_NAME_ALLOWED_CHARS[rand(0, $charactersLength - 1)];
    }

    return $randomString;
}

function generate_name_safe()
{
    do {
        $name = generate_name();
    } while (check_file_exists($name));

    return $name;
}
function check_file_exists($name)
{
    $files = glob(DATA_DIR . $name . '.*');

    return count($files) > 0;
}
function check_and_create_upload_dir()
{
    if (!file_exists(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }
}
function upload_file($file)
{
    global $oneshot;
    check_and_create_upload_dir();
    $validate = validate_file($file);
    if (!$validate['result']) {
        return $validate;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_name = generate_name_safe() . '.' . $ext;
    $file_path = DATA_DIR . $file_name;
    if ($oneshot) {
        $file_path = $file_path . '.oneshot';
    }

    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        return ['result' => true, 'description' => $file_name];
    } else {
        return ['result' => false, 'description' => 'Error moving file to destination'];
    }
}

function validate_file($file)
{
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['result' => false, 'description' => 'File too large'];
    }
    if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ALLOWED_EXTENSIONS)) {
        return ['result' => false, 'description' => 'Invalid file type'];
    }

    return ['result' => true, 'description' => 'File OK'];
}

function create_url_file($url, $oneshot, $remote)
{
    $file_name = generate_name_safe();
    $file_path = $file_name . '.' . ($oneshot ? 'oneshot.' : '') . 'url';
    $file = fopen(DATA_DIR . $file_path, 'w');
    fwrite($file, $url);
    fclose($file);
    echo $_SERVER['HTTP_HOST'] . '/' . $file_name;
    exit;
}
// Main

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (count(AUTHORIZATION) > 0) {
        if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
            header('HTTP/1.1 401 Unauthorized (no auth header)');
            exit;
        }
        if (!in_array($_SERVER['HTTP_AUTHORIZATION'], AUTHORIZATION)) {
            header('HTTP/1.1 401 Unauthorized (invalid auth header)');
            exit;
        }
    }
    $oneshot = false;
    $remote = false;
    if (isset($_FILES['file']) || isset($_FILES['oneshot'])) {
        if (isset($_FILES['file'])) {
            $saved_file = upload_file($_FILES['file']);
        } else {
            $oneshot = true;
            $saved_file = upload_file($_FILES['oneshot']);
        }
        if ($saved_file['result']) {
            echo $_SERVER['HTTP_HOST'] . '/' . $saved_file['description'];
        } else {
            echo 'Error uploading file: ' . $saved_file['description'];
        }
    }

    if (isset($_POST['url']) || isset($_POST['oneshot_url']) || isset($_POST['remote'])) {
        if (isset($_POST['url'])) {
            $url = $_POST['url'];
        }
        if (isset($_POST['oneshot_url'])) {
            $url = $_POST['oneshot_url'];
            $oneshot = true;
        }
        if (isset($_POST['remote'])) {
            $url = $_POST['remote'];
            $remote = true;
            echo 'Remote method not implemented';
            exit;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            echo 'Invalid URL';
            exit;
        }
        create_url_file($url, $oneshot, $remote);
    }
}
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $oneshot = false;
    $request_uri = ltrim($_SERVER['REQUEST_URI'], '/');
    $request = explode('.', $request_uri);
    //convert to lowercase and permit only the allowed chars
    $file_name = preg_replace('/[^' . RANDOM_NAME_ALLOWED_CHARS . ']+/', '', strtolower($request[0]));
    $files = glob(DATA_DIR . $file_name . '.*');
    if (count($files) == 0) {
        header('HTTP/1.1 404 File Not Found');
        echo 'File Not Found';
        exit;
    }
    if (count($files) > 1) {
        echo 'Too many files with same name';
        exit;
    }
    $file_exploded = explode('.', basename($files[0]));
    $file_last_part = $file_exploded[count($file_exploded) - 1];
    if ($file_last_part == 'url') {
        $file_penultimate_part = $file_exploded[count($file_exploded) - 2];
        if ($file_penultimate_part == 'oneshot') {
            $oneshot = true;
        }
        $file = fopen("$files[0]", 'r') or exit('Unable to open file!');
        $url = fgets($file);
        fclose($file);
        if ($oneshot) {
            unlink($files[0]);
        }
        header('Location: ' . $url);
        exit;
    } else {
        $file_last_part = $file_exploded[count($file_exploded) - 1];
        if ($file_last_part == 'oneshot') {
            $oneshot = true;
            unset($file_exploded[count($file_exploded) - 1]);
        }
        $file = fopen("$files[0]", 'r') or exit('Unable to open file!');
        $mime_type = mime_content_type($files[0]);
        $mime_type_family = explode('/', $mime_type)[0];
        if ($mime_type_family == 'image' || $mime_type_family == 'video' || $mime_type_family == 'audio') {
            header('Content-Disposition: inline; filename="' . implode('.', $file_exploded) . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . implode('.', $file_exploded) . '"');
        }
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: inline; filename="' . implode('.', $file_exploded) . '"');
        header('Content-Length: ' . filesize($files[0]));
        fpassthru($file);
        fclose($file);
        if ($oneshot) {
            unlink($files[0]);
        }

        exit;
    }
}
header('HTTP/1.1 405 Method Not Allowed');
exit;
