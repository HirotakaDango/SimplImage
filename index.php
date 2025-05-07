<?php
ob_start();
session_start();

$db = new SQLite3('database.sqlite');

$db->exec('
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )
');

$db->exec('
  CREATE TABLE IF NOT EXISTS images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    filename TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    title TEXT,
    description TEXT,
    tags TEXT,
    metadata TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id)
  )
');

function sanitize($input) {
  return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn() {
  return isset($_SESSION['user_id']);
}

function getCurrentUser($db) {
  if (!isLoggedIn()) {
    return null;
  }
  
  $stmt = $db->prepare('SELECT id, username FROM users WHERE id = :id');
  $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
  $result = $stmt->execute();
  return $result->fetchArray(SQLITE3_ASSOC);
}

function generateRandomString($length = 10) {
  $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
  $charactersLength = strlen($characters);
  $randomString = '';
  for ($i = 0; $i < $length; $i++) {
    $randomString .= $characters[random_int(0, $charactersLength - 1)];
  }
  return $randomString;
}

function createThumbnail($sourceImage, $targetImage, $maxWidth, $maxHeight) {
  list($origWidth, $origHeight) = getimagesize($sourceImage);
  
  $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
  $width = $origWidth * $ratio;
  $height = $origHeight * $ratio;
  
  $extension = strtolower(pathinfo($sourceImage, PATHINFO_EXTENSION));
  
  $image = null;
  switch ($extension) {
    case 'jpg':
    case 'jpeg':
      $image = imagecreatefromjpeg($sourceImage);
      break;
    case 'png':
      $image = imagecreatefrompng($sourceImage);
      break;
    case 'gif':
      $image = imagecreatefromgif($sourceImage);
      break;
    default:
      return false;
  }
  
  if (!$image) {
    return false;
  }

  $thumbnail = imagecreatetruecolor($width, $height);
  
  if ($extension == 'png') {
    imagealphablending($thumbnail, false);
    imagesavealpha($thumbnail, true);
    $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
    imagefilledrectangle($thumbnail, 0, 0, $width, $height, $transparent);
  }
  
  imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $width, $height, $origWidth, $origHeight);
  
  $success = false;
  switch ($extension) {
    case 'jpg':
    case 'jpeg':
      $success = imagejpeg($thumbnail, $targetImage, 90);
      break;
    case 'png':
      $success = imagepng($thumbnail, $targetImage, 9);
      break;
    case 'gif':
      $success = imagegif($thumbnail, $targetImage);
      break;
  }
  
  imagedestroy($image);
  imagedestroy($thumbnail);
  
  return $success;
}

function extractImageMetadata($imagePath) {
  $metadata = [];
  if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($imagePath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'tiff'])) {
    $exif = @exif_read_data($imagePath, 'ANY_TAG', true);
    if ($exif) {
      if (isset($exif['COMPUTED']['Width']) && isset($exif['COMPUTED']['Height'])) {
        $metadata['dimensions'] = $exif['COMPUTED']['Width'] . ' x ' . $exif['COMPUTED']['Height'];
      }
      if (isset($exif['FILE']['FileSize'])) {
        $metadata['filesize'] = formatFileSize($exif['FILE']['FileSize']);
      }
      if (isset($exif['FILE']['MimeType'])) {
        $metadata['mimetype'] = $exif['FILE']['MimeType'];
      }
      if (isset($exif['IFD0']['Make']) && isset($exif['IFD0']['Model'])) {
        $metadata['camera'] = trim($exif['IFD0']['Make'] . ' ' . $exif['IFD0']['Model']);
      }
      if (isset($exif['EXIF']['DateTimeOriginal'])) {
        $metadata['date_taken'] = $exif['EXIF']['DateTimeOriginal'];
      }
      if (isset($exif['EXIF']['ExposureTime'])) {
        $exposureTime = $exif['EXIF']['ExposureTime'];
        if (strpos($exposureTime, '/') !== false) {
          $parts = explode('/', $exposureTime);
          if (count($parts) == 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && $parts[1] != 0) {
            $metadata['exposure'] = ($parts[0] == 1 ? $exposureTime : number_format($parts[0] / $parts[1], 4)) . ' sec';
          }
        } elseif (is_numeric($exposureTime) && $exposureTime > 0) {
          $metadata['exposure'] = ($exposureTime < 1 ? '1/' . round(1 / $exposureTime) : number_format($exposureTime, 2)) . ' sec';
        }
      }
      if (isset($exif['EXIF']['ISOSpeedRatings'])) {
        $metadata['iso'] = 'ISO ' . (is_array($exif['EXIF']['ISOSpeedRatings']) ? $exif['EXIF']['ISOSpeedRatings'][0] : $exif['EXIF']['ISOSpeedRatings']);
      }
      if (isset($exif['EXIF']['FNumber'])) {
        $f_parts = explode('/', $exif['EXIF']['FNumber']);
        $f_val = count($f_parts) == 2 && $f_parts[1] != 0 ? ($f_parts[0] / $f_parts[1]) : floatval($exif['EXIF']['FNumber']);
        $metadata['aperture'] = 'f/' . number_format($f_val, 1);
      }
      if (isset($exif['GPS']['GPSLatitude']) && isset($exif['GPS']['GPSLongitude'])) {
        $lat = convertGPSToDecimal($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
        $lng = convertGPSToDecimal($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
        $metadata['location'] = number_format($lat, 6) . ', ' . number_format($lng, 6);
      }
    }
  }
  $imageInfo = @getimagesize($imagePath);
  if ($imageInfo) {
    if (!isset($metadata['dimensions'])) {
      $metadata['dimensions'] = $imageInfo[0] . ' x ' . $imageInfo[1];
    }
    if (!isset($metadata['mimetype'])) {
      $metadata['mimetype'] = $imageInfo['mime'];
    }
  }
  if (file_exists($imagePath)) {
    if (!isset($metadata['filesize'])) {
      $metadata['filesize'] = formatFileSize(filesize($imagePath));
    }
  }
  return array_filter($metadata);
}

function convertGPSToDecimal($coordParts, $hemi) {
  $degrees = count($coordParts) > 0 ? convertFractionToDecimal($coordParts[0]) : 0;
  $minutes = count($coordParts) > 1 ? convertFractionToDecimal($coordParts[1]) : 0;
  $seconds = count($coordParts) > 2 ? convertFractionToDecimal($coordParts[2]) : 0;
  $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
  return (($hemi == 'W' || $hemi == 'S') ? -1 : 1) * $decimal;
}

function convertFractionToDecimal($fraction) {
  $parts = explode('/', $fraction);
  if (count($parts) == 1) {
    return (float)$parts[0];
  }
  if (count($parts) == 2 && (float)$parts[1] != 0) {
    return (float)$parts[0] / (float)$parts[1];
  }
  return 0;
}

function formatFileSize($bytes) {
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
  $pow = min($pow, count($units) - 1);
  $bytes /= (1 << (10 * $pow));
  return round($bytes, 2) . ' ' . $units[$pow];
}

$pageTitle = 'Home';
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$errorMessage = '';
$successMessage = '';

if (isset($_POST['login'])) {
  $username = $_POST['username'];
  $password = $_POST['password'];
  $stmt = $db->prepare('SELECT id, password FROM users WHERE username = :username');
  $stmt->bindValue(':username', $username, SQLITE3_TEXT);
  $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    header('Location: ./?success=' . urlencode('Login successful!'));
    exit;
  } else {
    $errorMessage = 'Invalid username or password';
    $page = 'login';
  }
}

if (isset($_POST['register'])) {
  $username = $_POST['username'];
  $password = $_POST['password'];
  $confirm_password = $_POST['confirm_password'];
  if ($password !== $confirm_password) {
    $errorMessage = 'Passwords do not match';
    $page = 'register';
  } else {
    $stmt = $db->prepare('SELECT id FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    if ($stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
      $errorMessage = 'Username already exists';
      $page = 'register';
    } else {
      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $db->prepare('INSERT INTO users (username, password) VALUES (:username, :password)');
      $stmt->bindValue(':username', $username, SQLITE3_TEXT);
      $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
      if ($stmt->execute()) {
        $successMessage = 'Registration successful! Please login.';
        $page = 'login';
      } else {
        $errorMessage = 'Error creating account';
        $page = 'register';
      }
    }
  }
}

if ($page === 'logout' && isLoggedIn()) {
  session_unset();
  session_destroy();
  header('Location: ./?page=login&success=' . urlencode('You have been logged out successfully'));
  exit;
}

if (isset($_POST['upload']) && isLoggedIn()) {
  $title_common = $_POST['title'] ?? '';
  $description_common = $_POST['description'] ?? '';
  $tags_common = $_POST['tags'] ?? '';
  $upload_messages_success = [];
  $upload_messages_error = [];
  $maxFiles = 20;
  $maxTotalSizeBytes = 20 * 1024 * 1024; // 20MB
  $proceedWithFileUpload = true;

  if (isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
    $uploaded_file_details = [];
    $actual_files_count = 0;
    $currentTotalSize = 0;
    for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
      if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_NO_FILE && !empty($_FILES['images']['name'][$i])) {
        $actual_files_count++;
        if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
          $currentTotalSize += $_FILES['images']['size'][$i];
          $uploaded_file_details[] = [
            'name' => $_FILES['images']['name'][$i],
            'type' => $_FILES['images']['type'][$i],
            'tmp_name' => $_FILES['images']['tmp_name'][$i],
            'error' => $_FILES['images']['error'][$i],
            'size' => $_FILES['images']['size'][$i]
          ];
        } else {
          $err_map = [
            UPLOAD_ERR_INI_SIZE => "exceeds server's upload_max_filesize.",
            UPLOAD_ERR_FORM_SIZE => "exceeds form's MAX_FILE_SIZE.",
            UPLOAD_ERR_PARTIAL => "was only partially uploaded.",
            UPLOAD_ERR_NO_TMP_DIR => "missing temporary folder.",
            UPLOAD_ERR_CANT_WRITE => "failed to write to disk.",
            UPLOAD_ERR_EXTENSION => "PHP extension stopped upload."
          ];
          $upload_messages_error[] = "Upload error for '" . sanitize($_FILES['images']['name'][$i]) . "': " . ($err_map[$_FILES['images']['error'][$i]] ?? "Unknown error (code: {$_FILES['images']['error'][$i]}).");
          $proceedWithFileUpload = false;
        }
      }
    }
    if ($actual_files_count === 0 && $proceedWithFileUpload) {
      $upload_messages_error[] = 'Please select at least one image.';
      $proceedWithFileUpload = false;
    } elseif ($actual_files_count > $maxFiles && $proceedWithFileUpload) {
      $upload_messages_error[] = "Max {$maxFiles} images. You selected {$actual_files_count}.";
      $proceedWithFileUpload = false;
    }
    if ($currentTotalSize > $maxTotalSizeBytes && $proceedWithFileUpload) {
      $upload_messages_error[] = "Total size (" . formatFileSize($currentTotalSize) . ") exceeds limit of " . formatFileSize($maxTotalSizeBytes) . ".";
      $proceedWithFileUpload = false;
    }

    if ($proceedWithFileUpload && $actual_files_count > 0) {
      foreach ($uploaded_file_details as $file) {
        $originalName = $file['name'];
        $tmpName = $file['tmp_name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
          $newFileName = generateRandomString() . '_' . time() . "." . $extension;
          $uploadDir = 'uploads/images/';
          $thumbnailDir = 'uploads/thumbnails/';
          if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
          }
          if (!file_exists($thumbnailDir)) {
            mkdir($thumbnailDir, 0777, true);
          }
          $uploadPath = $uploadDir . $newFileName;
          $thumbnailPath = $thumbnailDir . $newFileName;
          if (move_uploaded_file($tmpName, $uploadPath)) {
            if (createThumbnail($uploadPath, $thumbnailPath, 500, 500)) {
              $metadata = extractImageMetadata($uploadPath);
              $stmt = $db->prepare('INSERT INTO images (user_id, filename, original_filename, title, description, tags, metadata) VALUES (:uid, :fn, :ofn, :t, :d, :tg, :m)');
              $stmt->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
              $stmt->bindValue(':fn', $newFileName, SQLITE3_TEXT);
              $stmt->bindValue(':ofn', $originalName, SQLITE3_TEXT);
              $image_title_to_db = !empty(trim($title_common)) ? $title_common : pathinfo($originalName, PATHINFO_FILENAME);
              $stmt->bindValue(':t', $image_title_to_db, SQLITE3_TEXT);
              $stmt->bindValue(':d', $description_common, SQLITE3_TEXT);
              $stmt->bindValue(':tg', $tags_common, SQLITE3_TEXT);
              $stmt->bindValue(':m', json_encode($metadata), SQLITE3_TEXT);
              if ($stmt->execute()) {
                $upload_messages_success[] = "Image '" . sanitize($originalName) . "' uploaded.";
              } else {
                $upload_messages_error[] = "Error saving '" . sanitize($originalName) . "' to DB.";
              }
            } else {
              $upload_messages_error[] = "Error thumbnail for '" . sanitize($originalName) . "'.";
              if (file_exists($uploadPath)) {
                unlink($uploadPath);
              }
            }
          } else {
            $upload_messages_error[] = "Error moving '" . sanitize($originalName) . "'.";
          }
        } else {
          $upload_messages_error[] = "Invalid format for '" . sanitize($originalName) . "'. JPG, PNG, GIF only.";
        }
      }
    }
  } elseif (isset($_POST['upload'])) {
    $upload_messages_error[] = 'No images detected. Try again.';
  }
  if (!empty($upload_messages_success)) {
    $successMessage = implode('<br>', $upload_messages_success);
  }
  if (!empty($upload_messages_error)) {
    $errorMessage = implode('<br>', $upload_messages_error);
  }
  if (empty($upload_messages_error) && !empty($upload_messages_success)) {
    header('Location: ./?success=' . urlencode(count($upload_messages_success) . ' image(s) uploaded!'));
    exit;
  } else {
    $page = 'upload';
  }
}

if (isset($_POST['change_password']) && isLoggedIn()) {
  $current_password = $_POST['current_password'];
  $new_password = $_POST['new_password'];
  $confirm_password = $_POST['confirm_password'];
  if ($new_password !== $confirm_password) {
    $errorMessage = 'New passwords do not match';
  } else {
    $stmt = $db->prepare('SELECT password FROM users WHERE id = :id');
    $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($user && password_verify($current_password, $user['password'])) {
      $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
      $stmt = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
      $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
      $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
      if ($stmt->execute()) {
        $successMessage = 'Password updated successfully!';
      } else {
        $errorMessage = 'Error updating password';
      }
    } else {
      $errorMessage = 'Current password is incorrect';
    }
  }
  $page = 'change_password';
}

if (isset($_POST['update_image_details']) && isLoggedIn()) {
  $imageId = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;
  $title = $_POST['title'] ?? '';
  $description = $_POST['description'] ?? '';
  $tags = $_POST['tags'] ?? '';

  if ($imageId > 0) {
    $stmt = $db->prepare('SELECT user_id FROM images WHERE id = :id');
    $stmt->bindValue(':id', $imageId, SQLITE3_INTEGER);
    $image = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($image && $image['user_id'] == $_SESSION['user_id']) {
      $stmt = $db->prepare('UPDATE images SET title = :title, description = :description, tags = :tags WHERE id = :id');
      $stmt->bindValue(':title', $title, SQLITE3_TEXT);
      $stmt->bindValue(':description', $description, SQLITE3_TEXT);
      $stmt->bindValue(':tags', $tags, SQLITE3_TEXT);
      $stmt->bindValue(':id', $imageId, SQLITE3_INTEGER);

      if ($stmt->execute()) {
        $redirect_url = './?page=view_image&id=' . $imageId . '&success=' . urlencode('Image details updated.');
        if (isset($_POST['context'])) {
          $redirect_url .= '&context=' . urlencode($_POST['context']);
        }
        if (isset($_POST['context_q'])) {
          $redirect_url .= '&q=' . urlencode($_POST['context_q']);
        }
        if (isset($_POST['context_tag_name'])) {
          $redirect_url .= '&tag_name=' . urlencode($_POST['context_tag_name']);
        }
        if (isset($_POST['context_uname'])) {
          $redirect_url .= '&uname=' . urlencode($_POST['context_uname']);
        }
        header('Location: ' . $redirect_url);
        exit;
      } else {
        $errorMessage = 'Error updating image details.';
        $page = 'edit_image';
        $_GET['id'] = $imageId; // Preserve for the edit page reload
      }
    } else {
      $errorMessage = $image ? 'You do not own this image.' : 'Image not found.';
      $page = 'home';
    }
  } else {
    $errorMessage = 'Invalid image ID.';
    $page = 'home';
  }
}

if (isset($_POST['delete_image_submit']) && isLoggedIn()) {
  $imageId = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;
  if ($imageId > 0) {
    $stmt = $db->prepare('SELECT user_id, filename FROM images WHERE id = :id');
    $stmt->bindValue(':id', $imageId, SQLITE3_INTEGER);
    $image = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($image && $image['user_id'] == $_SESSION['user_id']) {
      $uploadDir = 'uploads/images/';
      $thumbnailDir = 'uploads/thumbnails/';
      $originalPath = $uploadDir . $image['filename'];
      $thumbnailPath = $thumbnailDir . $image['filename'];
      
      if (file_exists($originalPath)) {
        @unlink($originalPath);
      }
      if (file_exists($thumbnailPath)) {
        @unlink($thumbnailPath);
      }

      $stmt = $db->prepare('DELETE FROM images WHERE id = :id');
      $stmt->bindValue(':id', $imageId, SQLITE3_INTEGER);
      if ($stmt->execute()) {
        header('Location: ./?page=profile&success=' . urlencode('Image deleted successfully.'));
        exit;
      } else {
        $errorMessage = 'Error deleting image from database.';
        $page = 'view_image';
        $_GET['id'] = $imageId; // Preserve for page reload
      }
    } else {
      $errorMessage = $image ? 'You do not own this image.' : 'Image not found.';
      $page = 'home';
    }
  } else {
    $errorMessage = 'Invalid image ID for deletion.';
    $page = 'home';
  }
}


switch ($page) {
  case 'login':
    $pageTitle = 'Login';
    break;
  case 'register':
    $pageTitle = 'Register';
    break;
  case 'upload':
    $pageTitle = 'Upload Image';
    break;
  case 'profile':
    $pageTitle = 'My Profile';
    break;
  case 'view_image':
    $pageTitle = 'View Image';
    break;
  case 'edit_image':
    $pageTitle = 'Edit Image';
    break;
  case 'search':
    $pageTitle = 'Search Results';
    break;
  case 'tag':
    $pageTitle = 'Tag Results';
    break;
  case 'user':
    $pageTitle = 'User Images';
    break;
  case 'change_password':
    $pageTitle = 'Change Password';
    break;
  default:
    $pageTitle = 'Home';
}

if (isset($_GET['success']) && empty($successMessage)) {
  $successMessage = sanitize($_GET['success']);
}
if (isset($_GET['error']) && empty($errorMessage)) {
  $errorMessage = sanitize($_GET['error']);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($pageTitle) . ' - SimplImage'; ?></title>
    <link rel="icon" href="https://www.svgrepo.com/show/528834/album.svg" type="image/svg">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
  </head>
  <body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm sticky-top">
      <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="./">
          <i class="bi bi-images text-primary me-2 fs-4"></i>SimplImage
        </a>
        <button class="navbar-toggler focus-ring focus-ring-dark border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarContent">
          <ul class="navbar-nav me-auto mb-2 mb-lg-0">
            <li class="nav-item">
              <a class="nav-link px-3 <?php echo ($page == 'home' || empty($page) || ($page == 'home' && !isset($_GET['page']))) ? 'active fw-bold' : ''; ?>" href="./"><i class="bi bi-house-door me-1"></i> Home</a>
            </li>
          </ul>
          <form class="d-flex me-0 me-lg-2 my-2 my-lg-0" action="./" method="GET">
            <input type="hidden" name="page" value="search">
            <div class="input-group">
              <input class="form-control form-control-sm" type="search" name="q" placeholder="Search images..." aria-label="Search" value="<?php echo isset($_GET['q']) ? sanitize($_GET['q']) : ''; ?>" required>
              <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i></button>
            </div>
          </form>
          <ul class="navbar-nav">
            <?php if (isLoggedIn()): $user = getCurrentUser($db); ?>
            <li class="nav-item dropdown ms-lg-2">
              <a class="nav-link dropdown-toggle d-flex align-items-center py-2" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center fw-bold me-2" style="width: 32px; height: 32px; font-size: 0.9rem;"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></span>
                <span class="d-none d-lg-inline"><?php echo sanitize($user['username']); ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-1" aria-labelledby="navbarDropdown">
                <li><a class="dropdown-item" href="./?page=profile"><i class="bi bi-person-circle me-2"></i> My Profile</a></li>
                <li><a class="dropdown-item" href="./?page=upload"><i class="bi bi-cloud-upload me-2"></i> Upload Images</a></li>
                <li><a class="dropdown-item" href="./?page=change_password"><i class="bi bi-key me-2"></i> Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="./?page=logout"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
              </ul>
            </li>
            <?php else: ?>
            <li class="nav-item ms-lg-2 mt-2 mt-lg-0"><a class="btn btn-outline-primary btn-sm px-3 d-flex align-items-center" href="./?page=login"><i class="bi bi-box-arrow-in-right me-1"></i> Login</a></li>
            <li class="nav-item ms-lg-2 mt-2 mt-lg-0"><a class="btn btn-primary btn-sm px-3 text-white d-flex align-items-center" href="./?page=register"><i class="bi bi-person-plus me-1"></i> Register</a></li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </nav>

    <div class="container mt-4 mb-5">
      <?php if ($errorMessage): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo $errorMessage; ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
      <?php endif; ?>
      <?php if ($successMessage): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo $successMessage; ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
      <?php endif; ?>

      <?php 
      switch ($page) {
        case 'denied':
      ?>
        <div class="d-flex vh-100 position-relative">
          <h5 class="position-absolute top-50 start-50 translate-middle">Access Denied!</h5>
        </div>
      <?php
          break;
        case 'login':
      ?>
        <div class="row justify-content-center">
          <div class="col-md-6 col-lg-5 col-xl-4">
            <div class="card shadow-sm">
              <div class="card-body p-4">
                <h2 class="text-center mb-4 fs-4">Login</h2>
                <form method="POST" action="./?page=login">
                  <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus>
                  </div>
                  <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                  </div>
                  <div class="d-grid gap-2">
                    <button class="btn btn-primary" type="submit" name="login">Login</button>
                  </div>
                  <div class="text-center mt-3">
                    <p class="mb-0">Don't have an account? <a href="./?page=register">Register</a></p>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php
          break;
        case 'register':
      ?>
        <div class="row justify-content-center">
          <div class="col-md-6 col-lg-5 col-xl-4">
            <div class="card shadow-sm">
              <div class="card-body p-4">
                <h2 class="text-center mb-4 fs-4">Register</h2>
                <form method="POST" action="./?page=register">
                  <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus>
                  </div>
                  <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                  </div>
                  <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                  </div>
                  <div class="d-grid gap-2">
                    <button class="btn btn-primary" type="submit" name="register">Register</button>
                  </div>
                  <div class="text-center mt-3">
                    <p class="mb-0">Already have an account? <a href="./?page=login">Login</a></p>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php
          break;
        case 'upload':
          if (!isLoggedIn()) {
            echo '<div class="alert alert-warning">Please login to upload.</div><p class="text-center mt-3"><a href="./?page=login" class="btn btn-primary">Login</a></p>';
            break;
          }
      ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-7">
            <div class="card shadow-sm">
              <div class="card-body p-4">
                <h2 class="text-center mb-4 fs-4">Upload Image(s)</h2>
                <form method="POST" action="./?page=upload" enctype="multipart/form-data" id="uploadForm">
                  <div class="mb-3">
                    <label for="images" class="form-label">Select Image(s) (max 20 files, 20MB total)</label>
                    <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/gif" class="form-control" required multiple>
                    <div id="image-preview-container" class="mt-3 row g-2"></div>
                    <small class="form-text text-muted d-block mt-1">Previews above. Allowed: JPG, PNG, GIF.</small>
                  </div>
                  <div class="mb-3">
                    <label for="title" class="form-label">Title (Optional)</label>
                    <input type="text" id="title" name="title" class="form-control">
                    <small class="form-text text-muted">Applies to all. If empty, filename is used.</small>
                  </div>
                  <div class="mb-3">
                    <label for="description" class="form-label">Description (Optional)</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    <small class="form-text text-muted">Applies to all images.</small>
                  </div>
                  <div class="mb-3">
                    <label for="tags" class="form-label">Tags (comma separated, Optional)</label>
                    <input type="text" id="tags" name="tags" class="form-control" placeholder="nature, landscape, sunset">
                    <small class="form-text text-muted">Applies to all images.</small>
                  </div>
                  <div class="d-grid gap-2">
                    <button class="btn btn-primary" type="submit" name="upload" id="uploadButton">Upload</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php
          break;
        case 'profile':
          if (!isLoggedIn()) {
            echo '<div class="alert alert-warning">Please login.</div><p class="text-center mt-3"><a href="./?page=login" class="btn btn-primary">Login</a></p>';
            break;
          }
          $user = getCurrentUser($db);
          $profileUsername = sanitize($user['username']);
          $currentPage = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
          $imagesPerPage = 20;
          $offset = ($currentPage - 1) * $imagesPerPage;
          $countStmt = $db->prepare('SELECT COUNT(*) as total FROM images WHERE user_id = :uid');
          $countStmt->bindValue(':uid', $user['id'], SQLITE3_INTEGER);
          $totalImages = $countStmt->execute()->fetchArray(SQLITE3_ASSOC)['total'];
          $totalPages = ceil($totalImages / $imagesPerPage);
          $stmt = $db->prepare('SELECT id, filename, title, created_at FROM images WHERE user_id = :uid ORDER BY created_at DESC LIMIT :l OFFSET :o');
          $stmt->bindValue(':uid', $user['id'], SQLITE3_INTEGER);
          $stmt->bindValue(':l', $imagesPerPage, SQLITE3_INTEGER);
          $stmt->bindValue(':o', $offset, SQLITE3_INTEGER);
          $images = $stmt->execute();
          $paginationParams = ['page' => 'profile'];
      ?>
        <div class="row mb-4">
          <div class="col-md-12">
            <div class="d-flex align-items-center mb-4">
              <div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center fw-bold me-3 p-3" style="width:80px; height:80px; font-size:2.5rem;"><?php echo strtoupper(substr($profileUsername, 0, 1)); ?></div>
              <div>
                <h1 class="mb-0 display-6 fw-bold"><?php echo $profileUsername; ?>'s Profile</h1>
                <p class="text-muted fw-medium mb-0">Manage your images.</p>
              </div>
            </div>
          </div>
        </div>
        <div class="row mb-4">
          <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="fs-4">Your Images (<?php echo $totalImages; ?>)</h2>
              <a href="./?page=upload" class="btn btn-primary btn-sm"><i class="bi bi-cloud-upload me-1"></i> Upload New</a>
            </div>
            <?php if ($totalImages > 0): ?>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">
              <?php while ($image = $images->fetchArray(SQLITE3_ASSOC)): ?>
              <div class="col">
                <div class="card shadow-sm h-100">
                  <div class="ratio ratio-1x1">
                    <a href="./?page=view_image&id=<?php echo $image['id']; ?>&context=profile&uname=<?php echo urlencode($profileUsername); ?>" class="d-block w-100 h-100">
                      <img src="<?php echo 'uploads/thumbnails/' . sanitize($image['filename']); ?>" class="card-img-top img-fluid object-fit-cover w-100 h-100" alt="<?php echo sanitize($image['title']); ?>">
                    </a>
                  </div>
                  <div class="card-body d-flex flex-column p-3">
                    <h5 class="card-title fs-6 mb-1 text-truncate"><?php echo sanitize($image['title']); ?></h5>
                    <p class="text-muted small mt-auto mb-0"><i class="bi bi-calendar-event me-1"></i> <?php echo date('M j, Y', strtotime($image['created_at'])); ?></p>
                  </div>
                </div>
              </div>
              <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="col-12">
              <div class="alert alert-info w-100">No images yet. <a href="./?page=upload">Upload one!</a></div>
            </div>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-content-center mt-4">
              <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm">
                  <li class="page-item <?php if ($currentPage <= 1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => 1])); ?>"><i class="bi bi-chevron-double-left"></i></a></li>
                  <li class="page-item <?php if ($currentPage <= 1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => $currentPage - 1])); ?>"><i class="bi bi-chevron-left"></i></a></li>
                  <?php 
                  $s = max(1, $currentPage - 2);
                  $e = min($totalPages, $currentPage + 2);
                  if ($s > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($paginationParams, ['p' => 1])) . '">1</a></li>';
                    if ($s > 2) {
                      echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                  }
                  for ($i = $s; $i <= $e; $i++) {
                    $activeClass = ($i === $currentPage) ? 'active' : '';
                    $tagStart = ($i === $currentPage) 
                      ? '<span class="page-link"' 
                      : '<a class="page-link" href="?' . http_build_query(array_merge($paginationParams, ['p' => $i])) . '"';
                    $tagEnd = ($i === $currentPage) ? 'span' : 'a';
                    echo '<li class="page-item ' . $activeClass . '">' . $tagStart . '>' . $i . '</' . $tagEnd . '></li>';
                  }
                  if ($e < $totalPages) {
                    if ($e < $totalPages - 1) {
                      echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($paginationParams, ['p' => $totalPages])) . '">' . $totalPages . '</a></li>';
                  }
                  ?>
                  <li class="page-item <?php if ($currentPage >= $totalPages) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => $currentPage + 1])); ?>"><i class="bi bi-chevron-right"></i></a></li>
                  <li class="page-item <?php if ($currentPage >= $totalPages || $totalPages <= 1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => $totalPages])); ?>"><i class="bi bi-chevron-double-right"></i></a></li>
                </ul>
              </nav>
            </div>
            <?php endif; ?>
          </div>
        </div>
      <?php
          break;
        case 'edit_image':
          if (!isLoggedIn()) {
            echo '<div class="alert alert-danger">Please login to edit images.</div><p class="text-center"><a href="./?page=login" class="btn btn-primary">Login</a></p>';
            break;
          }
          if (!isset($_GET['id'])) {
            echo '<div class="alert alert-danger">Image ID missing.</div>';
            break;
          }
          
          $imageId = (int)$_GET['id'];
          $stmt = $db->prepare('SELECT * FROM images WHERE id = :id AND user_id = :user_id');
          $stmt->bindValue(':id', $imageId, SQLITE3_INTEGER);
          $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
          $image_data = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

          if (!$image_data) {
            echo '<div class="alert alert-danger">Image not found or you do not have permission to edit it.</div>';
            break;
          }
      ?>
        <div class="row justify-content-center">
          <div class="col-md-8 col-lg-7">
            <div class="card shadow-sm">
              <div class="card-body p-4">
                <h2 class="text-center mb-4 fs-4">Edit Image Details</h2>
                <form method="POST" action="./">
                  <input type="hidden" name="image_id" value="<?php echo $image_data['id']; ?>">
                  <?php if (isset($_GET['context'])): ?><input type="hidden" name="context" value="<?php echo sanitize($_GET['context']); ?>"><?php endif; ?>
                  <?php if (isset($_GET['q'])): ?><input type="hidden" name="context_q" value="<?php echo sanitize($_GET['q']); ?>"><?php endif; ?>
                  <?php if (isset($_GET['tag_name'])): ?><input type="hidden" name="context_tag_name" value="<?php echo sanitize($_GET['tag_name']); ?>"><?php endif; ?>
                  <?php if (isset($_GET['uname'])): ?><input type="hidden" name="context_uname" value="<?php echo sanitize($_GET['uname']); ?>"><?php endif; ?>

                  <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" id="title" name="title" class="form-control" value="<?php echo sanitize($image_data['title']); ?>" required>
                  </div>
                  <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"><?php echo sanitize($image_data['description']); ?></textarea>
                  </div>
                  <div class="mb-3">
                    <label for="tags" class="form-label">Tags (comma separated)</label>
                    <input type="text" id="tags" name="tags" class="form-control" value="<?php echo sanitize($image_data['tags']); ?>" placeholder="nature, landscape, sunset">
                  </div>
                  <div class="d-flex justify-content-end gap-2">
                    <a href="./?page=view_image&id=<?php echo $image_data['id']; ?><?php if (isset($_GET['context'])) echo '&context=' . sanitize($_GET['context']); if (isset($_GET['q'])) echo '&q=' . urlencode(sanitize($_GET['q'])); if (isset($_GET['tag_name'])) echo '&tag_name=' . urlencode(sanitize($_GET['tag_name'])); if (isset($_GET['uname'])) echo '&uname=' . urlencode(sanitize($_GET['uname'])); ?>" class="btn btn-secondary">Cancel</a>
                    <button class="btn btn-primary" type="submit" name="update_image_details">Save Changes</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php
          break;
        case 'view_image':
          if (!isset($_GET['id'])) {
            echo '<div class="alert alert-danger">Image ID missing.</div>';
            break;
          }
          $imageId = (int)$_GET['id'];
          $stmt = $db->prepare('SELECT i.*, u.username FROM images i JOIN users u ON i.user_id = u.id WHERE i.id = :id');
          $stmt->bindValue(':id', $imageId, SQLITE3_INTEGER);
          $image = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
          if (!$image) {
            echo '<div class="alert alert-danger">Image not found.</div>';
            break;
          }

          $context = isset($_GET['context']) ? $_GET['context'] : 'global';
          $contextQuery = isset($_GET['q']) ? $_GET['q'] : null;
          $contextTag = isset($_GET['tag_name']) ? $_GET['tag_name'] : null;
          $contextUser = isset($_GET['uname']) ? $_GET['uname'] : null;
          $prevId = null;
          $nextId = null;
          $baseSqlWhereParts = [];
          $bindingsNav = [];
          if ($context === 'search' && $contextQuery) {
            $baseSqlWhereParts[] = "(i.title LIKE :qn OR i.description LIKE :qn OR i.tags LIKE :qn)";
            $bindingsNav[':qn'] = '%' . $contextQuery . '%';
          } elseif ($context === 'tag' && $contextTag) {
            $baseSqlWhereParts[] = "i.tags LIKE :tqn";
            $bindingsNav[':tqn'] = '%' . $contextTag . '%';
          } elseif (($context === 'user' || $context === 'profile') && $contextUser) {
            $uStmt = $db->prepare('SELECT id FROM users WHERE username = :ucn');
            $uStmt->bindValue(':ucn', $contextUser, SQLITE3_TEXT);
            $uRes = $uStmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($uRes) {
              $baseSqlWhereParts[] = "i.user_id = :uidcn";
              $bindingsNav[':uidcn'] = $uRes['id'];
            } else {
              $context = 'global'; // Reset context if user not found
              $baseSqlWhereParts = [];
              $bindingsNav = [];
            }
          }
          $whereClauseForNav = !empty($baseSqlWhereParts) ? implode(" AND ", $baseSqlWhereParts) . " AND " : "";
          $prevSql = "SELECT i.id FROM images i WHERE " . $whereClauseForNav . " ((i.created_at = :cca AND i.id > :cid) OR i.created_at > :cca) ORDER BY i.created_at ASC, i.id ASC LIMIT 1";
          $nextSql = "SELECT i.id FROM images i WHERE " . $whereClauseForNav . " ((i.created_at = :cca AND i.id < :cid) OR i.created_at < :cca) ORDER BY i.created_at DESC, i.id DESC LIMIT 1";
          
          $prevStmt = $db->prepare($prevSql);
          $prevStmt->bindValue(':cid', $imageId, SQLITE3_INTEGER);
          $prevStmt->bindValue(':cca', $image['created_at'], SQLITE3_TEXT);
          foreach ($bindingsNav as $ph => $v) $prevStmt->bindValue($ph, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
          if ($prevRes = $prevStmt->execute()->fetchArray(SQLITE3_ASSOC)) $prevId = $prevRes['id'];
          
          $nextStmt = $db->prepare($nextSql);
          $nextStmt->bindValue(':cid', $imageId, SQLITE3_INTEGER);
          $nextStmt->bindValue(':cca', $image['created_at'], SQLITE3_TEXT);
          foreach ($bindingsNav as $ph => $v) $nextStmt->bindValue($ph, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
          if ($nextRes = $nextStmt->execute()->fetchArray(SQLITE3_ASSOC)) $nextId = $nextRes['id'];
          
          $imageTitle = sanitize($image['title']);
          $imageDescription = nl2br(sanitize($image['description']));
          $imageUsername = sanitize($image['username']);
          $imageDate = date('F j, Y \a\t g:i A', strtotime($image['created_at']));
          $originalImageUrl = 'uploads/images/' . sanitize($image['filename']);
          $thumbnailImageUrl = 'uploads/thumbnails/' . sanitize($image['filename']);
          $tags = !empty($image['tags']) ? array_filter(array_map('trim', explode(',', sanitize($image['tags'])))) : [];
          $metadata = json_decode($image['metadata'] ?? '[]', true);
          if (empty($metadata) && file_exists('uploads/images/' . $image['filename'])) {
            $metadata = extractImageMetadata('uploads/images/' . $image['filename']);
          }

          $contextParams = "&context=" . urlencode($context);
          if ($contextQuery) $contextParams .= "&q=" . urlencode($contextQuery);
          if ($contextTag) $contextParams .= "&tag_name=" . urlencode($contextTag);
          if (($context === 'user' || $context === 'profile') && $contextUser) $contextParams .= "&uname=" . urlencode($contextUser);
          if ($context === 'global' && empty(array_filter([$contextQuery, $contextTag, $contextUser]))) $contextParams = ""; // No specific context for global
      ?>
        <div class="row justify-content-center">
          <div class="col-lg-10 col-xl-9">
            <div class="card shadow-small border-0">
              <div class="text-center">
                <img id="image-display" class="rounded w-100 h-100" src="<?php echo $thumbnailImageUrl; ?>" style="object-fit:contain; cursor:pointer;" alt="<?php echo $imageTitle; ?>" data-original="<?php echo $originalImageUrl; ?>" data-thumbnail="<?php echo $thumbnailImageUrl; ?>" title="Click to toggle Thumbnail/Original">
              </div>
              <div class="d-flex justify-content-between align-items-center p-3">
                <div>
                  <?php if ($prevId): ?>
                  <a href="./?page=view_image&id=<?php echo $prevId . $contextParams; ?>" class="btn border-0"><i class="bi bi-arrow-left-circle me-1"></i></a>
                  <?php else: ?>
                  <span class="btn border-0 disabled"><i class="bi bi-arrow-left-circle me-1"></i></span>
                  <?php endif; ?>
                </div>
                <div>
                  <?php if ($nextId): ?>
                  <a href="./?page=view_image&id=<?php echo $nextId . $contextParams; ?>" class="btn border-0"><i class="bi bi-arrow-right-circle ms-1"></i></a>
                  <?php else: ?>
                  <span class="btn border-0 disabled"><i class="bi bi-arrow-right-circle ms-1"></i></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="card-body p-3 p-md-4">
                <h1 class="h3 mb-1"><?php echo $imageTitle; ?></h1>
                <div class="text-muted small mb-3">
                  Uploaded by <a href="./?page=user&username=<?php echo urlencode($imageUsername); ?>" class="text-decoration-none fw-medium"><i class="bi bi-person-fill me-1"></i><?php echo $imageUsername; ?></a>
                  <h6 class="mt-3 small">on <i class="bi bi-calendar3 me-1"></i><?php echo $imageDate; ?>.</h6>
                  <h6 class="small">Original Filename: <span class="fst-italic"><?php echo sanitize($image['original_filename']); ?></span></h6>
                </div>
                
                <div class="d-flex flex-wrap justify-content-start align-items-center mb-4 gap-2">
                  <a href="<?php echo $originalImageUrl; ?>" download="<?php echo sanitize($image['original_filename']); ?>" class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i> Download Original</a>
                  <button id="view-toggle" class="btn btn-sm btn-info"></button>
                  <?php if (isLoggedIn() && $image['user_id'] == $_SESSION['user_id']): ?>
                  <a href="./?page=edit_image&id=<?php echo $image['id'] . $contextParams; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square me-1"></i> Edit Details</a>
                  <form method="POST" action="./" style="display: inline;" onsubmit="return confirm('Delete this image? This cannot be undone.');">
                    <input type="hidden" name="delete_image_submit" value="1">
                    <input type="hidden" name="image_id" value="<?php echo $image['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i> Delete Image</button>
                  </form>
                  <?php endif; ?>
                </div>

                <div class="row">
                  <div class="col-md-<?php echo (!empty($tags) || !empty($metadata)) ? '7' : '12'; ?>">
                    <?php if (!empty($imageDescription)): ?>
                    <div class="mb-3">
                      <h5 class="text-light fw-semibold fs-6 mb-1">Description</h5>
                      <p class="card-text text-body-secondary" style="white-space: pre-wrap;"><?php echo $imageDescription; ?></p>
                    </div>
                    <?php elseif (empty($tags) && empty($metadata)): ?>
                    <p class="text-muted">No description, tags, or metadata.</p>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($tags) || !empty($metadata)): ?>
                  <div class="col-md-5">
                    <?php if (!empty($tags)): ?>
                    <div class="mb-3">
                      <h5 class="text-light fw-semibold fs-6 mb-2"><i class="bi bi-tags-fill me-1"></i>Tags</h5>
                      <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($tags as $tag): ?>
                        <a href="./?page=tag&tag=<?php echo urlencode(trim($tag)); ?>" class="badge text-bg-secondary text-decoration-none fw-normal"><?php echo sanitize(trim($tag)); ?></a>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($metadata)): ?>
                    <div class="mb-3">
                      <h5 class="text-light fw-semibold fs-6 mb-2"><i class="bi bi-camera-fill me-1"></i>Metadata</h5>
                      <ul class="list-unstyled small mb-0 text-body-secondary">
                        <?php foreach ($metadata as $key => $value): ?>
                        <li class="mb-1 d-flex">
                          <strong class="text-muted me-2" style="min-width:80px;"><?php echo ucfirst(str_replace('_', ' ', sanitize($key))); ?>:</strong>
                          <span><?php echo sanitize($value); ?></span>
                        </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php
          break;
        case 'search':
        case 'user':
        case 'tag':
        default: 
          $imagesPerPage = 20;
          $currentPage = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
          $offset = ($currentPage - 1) * $imagesPerPage;
          $sqlCount = 'SELECT COUNT(DISTINCT i.id) as total FROM images i JOIN users u ON i.user_id = u.id ';
          $sqlImages = 'SELECT i.*, u.username FROM images i JOIN users u ON i.user_id = u.id ';
          $whereClauses = [];
          $bindings = [];
          $paginationParams = [];
          if (isset($_GET['page']) && in_array($_GET['page'], ['search', 'user', 'tag'])) {
            $paginationParams['page'] = $_GET['page'];
          }
          $pageHeader = "Recent Images";
          $context = 'global';
          $contextLinkParams = '&context=global';

          if ($page === 'search') {
            if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
              echo '<div class="alert alert-warning">Enter search query. <a href="./">View all</a></div>';
              break;
            }
            $searchTerm = $_GET['q'];
            $query = '%' . $searchTerm . '%';
            $whereClauses[] = '(i.title LIKE :q OR i.description LIKE :q OR i.tags LIKE :q OR u.username LIKE :qu)';
            $bindings[':q'] = $query;
            $bindings[':qu'] = $query; // Different placeholder in case needs to be different value later
            $paginationParams['q'] = $searchTerm;
            $pageHeader = 'Search: "' . sanitize($searchTerm) . '"';
            $context = 'search';
            $contextLinkParams = '&context=search&q=' . urlencode($searchTerm);
          } elseif ($page === 'user') {
            if (!isset($_GET['username']) || empty($_GET['username'])) {
              echo '<div class="alert alert-warning">User not specified. <a href="./">View all</a></div>';
              break;
            }
            $usernameToFilter = $_GET['username'];
            $whereClauses[] = 'u.username = :uf';
            $bindings[':uf'] = $usernameToFilter;
            $paginationParams['username'] = $usernameToFilter;
            $pageHeader = 'Images by ' . sanitize($usernameToFilter);
            $context = 'user';
            $contextLinkParams = '&context=user&uname=' . urlencode($usernameToFilter);
          } elseif ($page === 'tag') {
            if (!isset($_GET['tag']) || empty(trim($_GET['tag']))) {
              echo '<div class="alert alert-warning">Tag not specified. <a href="./">View all</a></div>';
              break;
            }
            $tagToSearch = $_GET['tag'];
            // More robust tag searching: exact match, starts with, ends with, contains within comma-separated list
            $whereClauses[] = "(',' || i.tags || ',' LIKE :tqe OR i.tags LIKE :tqs OR i.tags LIKE :tqe2 OR i.tags = :tq_single)";
            $bindings[':tqe'] = '%,' . $tagToSearch . ',%'; // e.g. ,tag,
            $bindings[':tqs'] = $tagToSearch . ',%';   // e.g. tag,%
            $bindings[':tqe2'] = '%,' . $tagToSearch;  // e.g. %,tag
            $bindings[':tq_single'] = $tagToSearch;    // e.g. tag (exact match if only one tag)
            $paginationParams['tag'] = $tagToSearch;
            $pageHeader = 'Tagged: "' . sanitize($tagToSearch) . '"';
            $context = 'tag';
            $contextLinkParams = '&context=tag&tag_name=' . urlencode($tagToSearch);
          } else { // Default (home page)
            $contextLinkParams = '&context=global';
          }

          if (!empty($whereClauses)) {
            $sqlCount .= ' WHERE ' . implode(' AND ', $whereClauses);
            $sqlImages .= ' WHERE ' . implode(' AND ', $whereClauses);
          }
          $countStmt = $db->prepare($sqlCount);
          foreach ($bindings as $ph => $v) $countStmt->bindValue($ph, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
          $totalImagesRes = $countStmt->execute()->fetchArray(SQLITE3_ASSOC);
          $totalImages = $totalImagesRes ? $totalImagesRes['total'] : 0;
          $totalPages = ceil($totalImages / $imagesPerPage);
          
          $sqlImages .= ' ORDER BY i.created_at DESC LIMIT :l OFFSET :o';
          $stmt = $db->prepare($sqlImages);
          foreach ($bindings as $ph => $v) $stmt->bindValue($ph, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
          $stmt->bindValue(':l', $imagesPerPage, SQLITE3_INTEGER);
          $stmt->bindValue(':o', $offset, SQLITE3_INTEGER);
          $images = $stmt->execute();
      ?>
        <div class="row mb-4">
          <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="fs-4 mb-0"><?php echo $pageHeader; ?></h2>
              <span class="text-muted"><?php echo $totalImages; ?> Image<?php echo ($totalImages != 1) ? 's' : ''; ?> Found</span>
            </div>
            <?php if ($totalImages > 0): ?>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">
              <?php while ($image = $images->fetchArray(SQLITE3_ASSOC)): $viewImageLink = "./?page=view_image&id=" . $image['id'] . $contextLinkParams; ?>
              <div class="col">
                <div class="card shadow-sm h-100">
                  <div class="ratio ratio-1x1">
                    <a href="<?php echo $viewImageLink; ?>" class="d-block w-100 h-100">
                      <img src="<?php echo 'uploads/thumbnails/' . sanitize($image['filename']); ?>" class="card-img-top img-fluid object-fit-cover w-100 h-100" alt="<?php echo sanitize($image['title']); ?>">
                    </a>
                  </div>
                  <div class="card-body d-flex flex-column p-3">
                    <h5 class="card-title fs-6 mb-1 text-truncate" title="<?php echo sanitize($image['title']); ?>"><?php echo sanitize($image['title']); ?></h5>
                    <p class="text-muted small mt-auto mb-0">
                      <i class="bi bi-person me-1"></i> 
                      <a href="./?page=user&username=<?php echo urlencode(sanitize($image['username'])); ?>" class="text-decoration-none"><?php echo sanitize($image['username']); ?></a>
                    </p>
                  </div>
                </div>
              </div>
              <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="col-12">
              <?php 
              if ($page === 'home' || $page === '') {
                echo '<div class="alert alert-info w-100">No images. ' . (isLoggedIn() ? '<a href="./?page=upload">Upload one</a>!' : '<a href="./?page=login">Login</a> or <a href="./?page=register">Register</a> to upload.') . '</div>';
              } else {
                echo '<div class="alert alert-info w-100">No images found. <a href="./">Try different search or view all</a>.</div>';
              }
              ?>
            </div>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-content-center mt-4">
              <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm">
                  <li class="page-item <?php if ($currentPage <= 1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => 1])); ?>"><i class="bi bi-chevron-double-left"></i></a></li>
                  <li class="page-item <?php if ($currentPage <= 1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => $currentPage - 1])); ?>"><i class="bi bi-chevron-left"></i></a></li>
                  <?php 
                  $s = max(1, $currentPage - 2);
                  $e = min($totalPages, $currentPage + 2);
                  if ($s > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($paginationParams, ['p' => 1])) . '">1</a></li>';
                    if ($s > 2) {
                      echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                  }
                  for ($i = $s; $i <= $e; $i++) {
                    $activeClass = ($i === $currentPage) ? 'active' : '';
                    $tagStart = ($i === $currentPage) 
                      ? '<span class="page-link"' 
                      : '<a class="page-link" href="?' . http_build_query(array_merge($paginationParams, ['p' => $i])) . '"';
                    $tagEnd = ($i === $currentPage) ? 'span' : 'a';
                    echo '<li class="page-item ' . $activeClass . '">' . $tagStart . '>' . $i . '</' . $tagEnd . '></li>';
                  }
                  if ($e < $totalPages) {
                    if ($e < $totalPages - 1) {
                      echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($paginationParams, ['p' => $totalPages])) . '">' . $totalPages . '</a></li>';
                  }
                  ?>
                  <li class="page-item <?php if ($currentPage >= $totalPages) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => $currentPage + 1])); ?>"><i class="bi bi-chevron-right"></i></a></li>
                  <li class="page-item <?php if ($currentPage >= $totalPages || $totalPages <= 1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($paginationParams, ['p' => $totalPages])); ?>"><i class="bi bi-chevron-double-right"></i></a></li>
                </ul>
              </nav>
            </div>
            <?php endif; ?>
          </div>
        </div>
      <?php
          break; 
        case 'change_password':
          if (!isLoggedIn()) {
            echo '<div class="alert alert-warning">Please login.</div><p class="text-center mt-3"><a href="./?page=login" class="btn btn-primary">Login</a></p>';
            break;
          }
      ?>
        <div class="row justify-content-center">
          <div class="col-md-6 col-lg-5 col-xl-4">
            <div class="card shadow-sm">
              <div class="card-body p-4">
                <h2 class="text-center mb-4 fs-4">Change Password</h2>
                <form method="POST" action="./?page=change_password">
                  <div class="mb-3">
                    <label for="current_password" class="form-label">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                  </div>
                  <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
                  </div>
                  <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6">
                  </div>
                  <div class="d-grid gap-2">
                    <button class="btn btn-primary" type="submit" name="change_password">Change Password</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php
          break;
      }
      ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const imageUploadInput = document.getElementById('images');
        const previewContainer = document.getElementById('image-preview-container');
        const uploadButton = document.getElementById('uploadButton');

        if (imageUploadInput && previewContainer) {
          const MAX_FILES = 20;
          const MAX_TOTAL_SIZE_MB = 20;
          const MAX_TOTAL_SIZE_BYTES = MAX_TOTAL_SIZE_MB * 1024 * 1024;
          
          function setInitialPreviewPlaceholder() {
            if (previewContainer.children.length === 0) {
              previewContainer.innerHTML = '<div class="col-12"><small class="text-muted text-center d-block py-4">Image previews here.</small></div>';
            }
          }
          setInitialPreviewPlaceholder();

          imageUploadInput.addEventListener('change', function(event) {
            previewContainer.innerHTML = ''; 
            const files = event.target.files;
            let totalSize = 0;
            let fileCount = files.length;
            let errors = [];
            
            if (fileCount === 0) {
              setInitialPreviewPlaceholder();
              if (uploadButton) {
                uploadButton.disabled = !imageUploadInput.required; // Disable only if not required or no files
              }
              return;
            }

            if (fileCount > MAX_FILES) {
              errors.push(`Max ${MAX_FILES} images. You selected ${fileCount}.`);
            }

            for (let i = 0; i < Math.min(fileCount, MAX_FILES + 1); i++) { // Allow iterating one past MAX_FILES to sum total size for error message
              if (i >= fileCount) break; // Should not happen if loop condition is correct based on files.length
              const file = files[i];
              totalSize += file.size;

              if (i < MAX_FILES) { // Only preview up to MAX_FILES
                if (!['image/jpeg', 'image/png', 'image/gif'].includes(file.type)) {
                  const pW = document.createElement('div');
                  pW.classList.add('col-12');
                  const p = document.createElement('p');
                  p.textContent = `Cannot preview '${file.name}': unsupported file type.`;
                  p.classList.add('text-danger', 'small');
                  pW.appendChild(p);
                  previewContainer.appendChild(pW);
                  continue;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                  const iW = document.createElement('div');
                  iW.classList.add('col-6', 'border-0', 'rounded', 'overflow-hidden');
                  iW.style.padding = '0.75rem';
                  iW.style.aspectRatio = '1/1';
                  const img = document.createElement('img');
                  img.src = e.target.result;
                  img.alt = file.name;
                  img.title = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
                  img.style.width = '100%';
                  img.style.height = '100%';
                  img.style.objectFit = 'cover';
                  iW.appendChild(img);
                  previewContainer.appendChild(iW);
                }
                reader.readAsDataURL(file);
              }
            }

            if (totalSize > MAX_TOTAL_SIZE_BYTES) {
              errors.push(`Total size (${(totalSize / (1024 * 1024)).toFixed(2)}MB) exceeds ${MAX_TOTAL_SIZE_MB}MB.`);
            }

            if (errors.length > 0) {
              alert(errors.join('\n'));
              if (uploadButton) {
                uploadButton.disabled = true;
              }
            } else {
              if (uploadButton) {
                uploadButton.disabled = false;
              }
            }

            if (previewContainer.children.length === 0 && errors.length === 0) {
              setInitialPreviewPlaceholder();
            } else if (previewContainer.children.length === 0 && errors.length > 0) {
              previewContainer.innerHTML = '<div class="col-12"><small class="text-danger text-center d-block py-4">Previews could not be shown due to errors.</small></div>';
            }
          });
        }
        
        const imgDisplay = document.getElementById('image-display');
        const viewToggleBtn = document.getElementById('view-toggle');
        if (imgDisplay && viewToggleBtn) {
          imgDisplay.addEventListener('click', toggleImageView);
          viewToggleBtn.addEventListener('click', toggleImageView);
          viewToggleBtn.textContent = (imgDisplay.getAttribute('src') === imgDisplay.dataset.thumbnail) ? 'View Original' : 'View Thumbnail';
        }
      });

      function toggleImageView() {
        const imgD = document.getElementById('image-display');
        const btn = document.getElementById('view-toggle');
        if (!imgD || !btn) {
          return;
        }
        const origSrc = imgD.dataset.original;
        const thumbSrc = imgD.dataset.thumbnail;
        if (imgD.getAttribute('src') === thumbSrc) {
          imgD.setAttribute('src', origSrc);
          btn.textContent = 'View Thumbnail';
        } else {
          imgD.setAttribute('src', thumbSrc);
          btn.textContent = 'View Original';
        }
      }
    </script>
  </body>
</html>
<?php
if (isset($db)) {
  $db->close();
}
ob_end_flush();
?>