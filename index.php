<?php
// Configuration
const FILENAME_LENGTH = 5;                                              // Length of the random filename
const DATA_DIR = 'data/';                                           // Must end with a slash
const RANDOM_NAME_ALLOWED_CHARS = '256789bcdfghjklmnpqrstvwxyz';    // Excluding vowels and vowels lookalikes
const MAX_UPLOAD_SIZE = 1024 * 1024 * 500;                         // 500MB
const ALLOWED_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg', 'mp3', 'wav',
    'flac', 'pdf', 'txt', 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'doc', 'docx', 'xls',
    'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'odg', 'odf', 'odc', 'odb', 'odi', 'odm',
    'odp', 'odt', 'ott', 'ots', 'otp', 'otg', 'otf', 'otc', 'otb', 'oti', 'oth', 'pdf', 'txt',
    'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'odt', 'ods', 'odp', 'odg', 'odf', 'odc', 'odb', 'odi', 'odm', 'odp', 'odt', 'ott', 'ots',
    'otp', 'otg', 'otf', 'otc', 'otb', 'oti', 'oth', 'md'
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
        return ['result' => false, 'description' => "Error moving file to destination"];
    }
}

function validate_file($file)
{
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['result' => false, 'description' => "File too large"];
    }
    if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ALLOWED_EXTENSIONS)) {
        return ['result' => false, 'description' => "Invalid file type"];
    }
    return ['result' => true, 'description' => 'File OK'];
}

// Main

$oneshot = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
            echo "Error uploading file" . $saved_file['description'];
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
        }
        $url = $_POST['url'];
        $ext = pathinfo($url, PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), ALLOWED_EXTENSIONS)) {
            check_and_create_upload_dir();
            $file_name = generate_name_safe() . '.' . $ext;
            if ($oneshot) {
                $file_name = $file_name . '.oneshot';
            }
            $file_path = DATA_DIR . $file_name;
            if (file_put_contents($file_path, file_get_contents($url))) {
                echo $_SERVER['HTTP_HOST'] . '/' . $file_name;
            } else {
                echo "Error downloading file";
            }
        } else {
            echo "Invalid file type";
        }
    }
}

header('HTTP/1.1 405 Method Not Allowed');
exit;
