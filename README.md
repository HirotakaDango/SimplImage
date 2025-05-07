# SimplImage

**SimplImage** is a simple, self-hosted image sharing and gallery web application built with PHP and SQLite. It allows users to register, log in, upload and manage their image collection, and explore others' images. The UI is modern and responsive, powered by Bootstrap.

## Features

- **User Registration & Login:** Secure user system with password hashing.
- **Image Upload:** Upload up to 20 images at once (JPG, PNG, GIF; max 20MB total). Thumbnails are auto-generated.
- **Gallery & Search:** Browse, search, and filter images by tag or user.
- **Profiles:** View your own uploaded images and manage them.
- **Image Details:** Rich image pages with metadata (EXIF, camera info, etc.), tags, and download options.
- **Image Edit & Delete:** Manage your uploads (edit details, delete images).
- **Responsive UI:** Mobile-friendly, dark theme, and user-friendly interface with Bootstrap 5.
- **Metadata Extraction:** EXIF data extraction for supported images.

## Requirements

- PHP 7.4 or newer (with SQLite3, GD, and EXIF extensions enabled)
- Web server (Apache, Nginx, etc.)
- Write permissions for the `uploads/` and root directory to create the SQLite database

## Installation

1. **Clone or Download**
    ```bash
    git clone https://github.com/HirotakaDango/SimplImage.git
    cd SimplImage
    ```

2. **Directory Permissions**
    - Ensure `uploads/images/` and `uploads/thumbnails/` directories exist and are writable by the web server.
    - If not present, they will be created automatically on upload.

3. **Web Server Setup**
    - Deploy in a web-accessible directory.
    - Point your web server's document root to this folder.

4. **First Run**
    - Open the site in your browser.
    - Register a new user account and start uploading images!

## Usage

- **Upload Images:** After logging in, click "Upload Images" in the navigation bar.
- **View, Edit, Delete:** Manage your images from your profile page.
- **Explore:** Browse, search, and filter images on the homepage.

## Configuration

Most configuration (e.g., upload limits) is set in `index.php`. For advanced setups, you may customize the code as needed.

## Security Notes

- Passwords are securely hashed.
- User input is sanitized to prevent XSS.
- Only logged-in users can upload or manage images.
- Uploaded files are validated by type and extension.

## File Structure

- `index.php` – Main application file (handles routing, logic, and rendering)
- `uploads/images/` – Stores original uploaded images
- `uploads/thumbnails/` – Stores auto-generated thumbnails
- `database.sqlite` – SQLite database file (auto-created)
