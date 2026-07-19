# Tabs Library

A lightweight, self-contained PHP file browser for music tablature, chord sheets, tune books, and related reference material.

## Features

- Folder navigation with breadcrumbs and natural filename sorting
- Inline previews for PDFs, images, and text-based tab files
- Previous/next file navigation and pagination for large folders
- File type and size information
- Responsive layout for desktop and mobile browsers
- Path validation that keeps requests inside the library directory
- No package manager, database, or build step

## Requirements

- PHP 8.1 or newer
- The PHP `fileinfo` extension
- A modern browser; PDF preview support depends on the browser

## Run locally

From the repository root, start PHP's built-in development server:

```bash
php -S 127.0.0.1:8080
```

Then visit <http://127.0.0.1:8080>. The application treats its own directory as the library root, so music files and subdirectories placed beside `index.php` appear automatically. Hidden entries and `index.php` itself are not included in the listing.

## Apache deployment

Point an Apache site or alias at this directory and ensure PHP is enabled. The included `.htaccess` sets `index.php` as the directory index and currently applies HTTP Basic Authentication using:

```text
/etc/apache2/auth/passwords
```

It also contains an access exception for the `/tabs4steve` URL path. Adjust or remove those authentication rules when deploying somewhere else. The password file is server-local and is not stored in this repository.

## Repository layout

| Path | Purpose |
| --- | --- |
| `index.php` | Complete browser, viewer, styles, and client-side behavior |
| `.htaccess` | Apache directory and access configuration |
| `abc/`, `banjo/`, `guitar/`, `songs/`, `uke/`, `whistle/` | Music resources grouped by format or instrument |
| `README.md` | Project and deployment documentation |

## Large files and GitHub

This library contains binary media and several PDFs larger than GitHub's regular 100 MiB per-file limit:

- `Mandolin Updates.pdf`
- `Mandolin Updates_Compress.pdf`
- `extra-mandolin-tabs.pdf`

Install [Git LFS](https://git-lfs.com/) and track these files before the first commit if they should be stored on GitHub, or keep them outside Git and provide them separately. Review the repository's total media size and the applicable Git LFS storage quota before pushing.

## Development checks

There is no automated test suite. Run PHP's syntax checker after editing the application:

```bash
php -l index.php
```

For a quick manual check, open the root directory, browse into a subfolder, preview an image, a PDF, and a text tab, and verify the previous/next controls.
