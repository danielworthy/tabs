<?php
declare(strict_types=1);

const FILES_PER_PAGE = 100;
const TEXT_PREVIEW_MAX_BYTES = 1048576;

$root = __DIR__;
$rawCurrentDir = $_GET['dir'] ?? '';
$rawViewFile = $_GET['file'] ?? '';
$rawPage = $_GET['page'] ?? '1';

if (!is_string($rawCurrentDir) || !is_string($rawViewFile) || !is_string($rawPage)) {
    failRequest(400, 'Invalid query parameter.');
}

$requestedPage = filter_var($rawPage, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($requestedPage === false) {
    failRequest(400, 'Invalid page number.');
}

$currentDir = normalizeRelativePath($rawCurrentDir);
$currentPath = resolveWithinRoot($root, $currentDir);

if ($currentPath === null || !is_dir($currentPath)) {
    http_response_code(404);
    echo 'Directory not found.';
    exit;
}

$viewFile = normalizeRelativePath($rawViewFile);
$viewPath = $viewFile === '' ? null : resolveWithinRoot($root, $viewFile);

if ($viewFile !== '' && ($viewPath === null || !is_file($viewPath))) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

if ($viewFile !== '' && parentPath($viewFile) !== $currentDir) {
    failRequest(400, 'The selected file does not belong to this directory.');
}

$items = listDirectoryItems($root, $currentDir, $currentPath);
$breadcrumbs = buildBreadcrumbs($currentDir);
$viewer = $viewPath === null ? null : buildViewerData($root, $viewFile, $viewPath);
$allFiles = $items['files'];
$totalFiles = count($allFiles);
$totalPages = max(1, (int) ceil($totalFiles / FILES_PER_PAGE));

$currentFileIndex = null;
if ($viewer !== null) {
    foreach ($allFiles as $i => $file) {
        if ($file['path'] === $viewer['path']) {
            $currentFileIndex = $i;
            break;
        }
    }
}

if ($viewer !== null && $currentFileIndex === null) {
    failRequest(404, 'File not found.');
}

$currentPage = min($requestedPage, $totalPages);
if ($currentFileIndex !== null) {
    $currentPage = intdiv($currentFileIndex, FILES_PER_PAGE) + 1;
}

$pageOffset = ($currentPage - 1) * FILES_PER_PAGE;
$pageFiles = array_slice($allFiles, $pageOffset, FILES_PER_PAGE);
$currentPageFileIndex = $currentFileIndex === null ? -1 : $currentFileIndex - $pageOffset;

$prevFile = ($currentFileIndex !== null && $currentFileIndex > 0)
    ? $allFiles[$currentFileIndex - 1]
    : null;
$nextFile = ($currentFileIndex !== null && $currentFileIndex < $totalFiles - 1)
    ? $allFiles[$currentFileIndex + 1]
    : null;

$filesForJs = [];
foreach ($pageFiles as $pageIndex => $file) {
    $globalIndex = $pageOffset + $pageIndex;
    $kind = fileKind($file['ext']);
    $filesForJs[] = [
        'name' => $file['name'],
        'path' => $file['path'],
        'url' => rawurlencodePath($file['path']),
        'historyUrl' => buildViewerUrl($currentDir, $file['path'], $currentPage),
        'prevUrl' => $globalIndex > 0
            ? buildViewerUrl($currentDir, $allFiles[$globalIndex - 1]['path'], intdiv($globalIndex - 1, FILES_PER_PAGE) + 1, true)
            : null,
        'nextUrl' => $globalIndex < $totalFiles - 1
            ? buildViewerUrl($currentDir, $allFiles[$globalIndex + 1]['path'], intdiv($globalIndex + 1, FILES_PER_PAGE) + 1, true)
            : null,
        'kind' => $kind,
        'type' => $file['type'],
        'size' => (int) $file['size'],
        'canPreviewText' => $kind === 'text'
            && $file['size_known']
            && $file['size'] <= TEXT_PREVIEW_MAX_BYTES,
    ];
}

function failRequest(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function normalizeRelativePath(string $path): string
{
    $parts = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($parts);
            continue;
        }

        $parts[] = $part;
    }

    return implode('/', $parts);
}

function parentPath(string $path): string
{
    $parent = dirname($path);
    return $parent === '.' ? '' : $parent;
}

function hasHiddenPathSegment(string $path): bool
{
    foreach (explode('/', $path) as $part) {
        if ($part !== '' && $part[0] === '.') {
            return true;
        }
    }

    return false;
}

function resolveWithinRoot(string $root, string $relativePath): ?string
{
    if (hasHiddenPathSegment($relativePath)) {
        return null;
    }

    $fullPath = $root . ($relativePath === '' ? '' : '/' . $relativePath);
    $realPath = realpath($fullPath);

    if ($realPath === false) {
        return null;
    }

    if ($realPath !== $root && strpos($realPath, $root . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }

    if (hasHiddenPathSegment(substr($realPath, strlen($root)))) {
        return null;
    }

    return $realPath;
}

function listDirectoryItems(string $root, string $currentDir, string $currentPath): array
{
    $entries = scandir($currentPath);
    $directories = [];
    $files = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }

        if ($entry === 'index.php') {
            continue;
        }

        $relativePath = ltrim($currentDir . '/' . $entry, '/');
        $resolvedPath = resolveWithinRoot($root, $relativePath);

        if ($resolvedPath === null) {
            continue;
        }

        if (is_dir($resolvedPath)) {
            $directories[] = [
                'name' => $entry,
                'path' => $relativePath,
                'modified' => @filemtime($resolvedPath) ?: 0,
            ];
            continue;
        }

        if (!is_file($resolvedPath)) {
            continue;
        }

        $size = @filesize($resolvedPath);
        $files[] = [
            'name' => $entry,
            'path' => $relativePath,
            'size' => $size === false ? 0 : $size,
            'size_known' => $size !== false,
            'modified' => @filemtime($resolvedPath) ?: 0,
            'ext' => strtolower(pathinfo($entry, PATHINFO_EXTENSION)),
            'type' => fileTypeLabel($entry),
        ];
    }

    usort($directories, fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    usort($files, fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    return ['directories' => $directories, 'files' => $files];
}

function buildBreadcrumbs(string $currentDir): array
{
    $crumbs = [['label' => 'Tabs', 'path' => '']];

    if ($currentDir === '') {
        return $crumbs;
    }

    $parts = explode('/', $currentDir);
    $build = [];

    foreach ($parts as $part) {
        $build[] = $part;
        $crumbs[] = [
            'label' => $part,
            'path' => implode('/', $build),
        ];
    }

    return $crumbs;
}

function buildViewerData(string $root, string $relativeFile, string $fullPath): array
{
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mime = mime_content_type($fullPath) ?: 'application/octet-stream';
    $url = rawurlencodePath($relativeFile);
    $kind = fileKind($ext);
    $rawSize = @filesize($fullPath);
    $size = $rawSize === false ? 0 : $rawSize;
    $canPreviewText = $kind === 'text'
        && $rawSize !== false
        && $size <= TEXT_PREVIEW_MAX_BYTES;
    $text = $canPreviewText ? @file_get_contents($fullPath) : null;

    return [
        'name' => basename($fullPath),
        'path' => $relativeFile,
        'url' => $url,
        'kind' => $kind,
        'mime' => $mime,
        'size' => $size,
        'modified' => @filemtime($fullPath) ?: 0,
        'text' => $text === false ? null : $text,
        'text_error' => $canPreviewText && $text === false,
        'can_preview_text' => $canPreviewText,
        'parent' => parentPath($relativeFile),
    ];
}

function rawurlencodePath(string $path): string
{
    $parts = array_map('rawurlencode', explode('/', $path));
    return implode('/', $parts);
}

function buildViewerUrl(string $directory, string $file, int $page, bool $withFragment = false): string
{
    $url = '?dir=' . rawurlencodePath($directory)
        . '&file=' . rawurlencodePath($file)
        . '&page=' . $page;

    return $withFragment ? $url . '#viewer' : $url;
}

function buildDirectoryUrl(string $directory, int $page): string
{
    return '?dir=' . rawurlencodePath($directory) . '&page=' . $page;
}

function fileKind(string $ext): string
{
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        return 'image';
    }

    if ($ext === 'pdf') {
        return 'pdf';
    }

    if (in_array($ext, ['txt', 'abc', 'tab', 'md', 'log'], true)) {
        return 'text';
    }

    return 'download';
}

function fileTypeLabel(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return match ($ext) {
        'pdf' => 'PDF',
        'png', 'jpg', 'jpeg', 'gif', 'webp' => 'Image',
        'txt', 'abc', 'tab', 'md', 'log' => 'Text',
        'zip' => 'Archive',
        'doc', 'docx' => 'Document',
        default => $ext !== '' ? strtoupper($ext) : 'File',
    };
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $size = $bytes / 1024;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return number_format($size, $size < 10 ? 1 : 0) . ' ' . $units[$unitIndex];
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonForHtml(mixed $value): string
{
    return json_encode(
        $value,
        JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($viewer['name'] ?? ($currentDir === '' ? 'Tabs' : basename($currentDir))) ?></title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4efe2;
            --panel: #fffaf0;
            --panel-strong: #fff;
            --line: #d5c9af;
            --text: #201912;
            --muted: #665c4d;
            --accent: #8a4b14;
            --accent-soft: #f0dbc3;
            --shadow: 0 12px 30px rgba(58, 35, 9, 0.09);
            --radius: 18px;
            --mobile-text: clamp(1.02rem, 0.95rem + 0.55vw, 1.2rem);
            --desktop-text: 1rem;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            font-size: var(--mobile-text);
            line-height: 1.55;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(255,255,255,0.65), transparent 30rem),
                linear-gradient(180deg, #f1ead9 0%, #f8f4ea 100%);
        }

        a { color: var(--accent); text-decoration-thickness: 0.08em; }
        a:hover { text-decoration-thickness: 0.14em; }

        .page {
            width: min(1100px, calc(100% - 1rem));
            margin: 0.5rem auto 2rem;
        }

        .shell {
            background: rgba(255, 250, 240, 0.92);
            border: 1px solid rgba(138, 75, 20, 0.15);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .masthead {
            padding: 1rem 1rem 0.8rem;
            background: linear-gradient(135deg, rgba(138, 75, 20, 0.12), rgba(255,255,255,0.55));
            border-bottom: 1px solid var(--line);
        }

        h1 {
            margin: 0 0 0.35rem;
            font-size: clamp(1.7rem, 1.25rem + 2vw, 2.6rem);
            line-height: 1.05;
        }

        .subtitle, .meta {
            color: var(--muted);
            font-size: 0.98em;
        }

        .breadcrumbs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            margin-top: 0.75rem;
        }

        .crumb {
            padding: 0.45rem 0.7rem;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: rgba(255,255,255,0.7);
            text-decoration: none;
        }

        .content {
            display: grid;
            gap: 1rem;
            padding: 1rem;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 0.85rem;
        }

        .panel h2 {
            margin: 0 0 0.8rem;
            font-size: 1.15rem;
        }

        .viewer-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 0.6rem;
        }

        .viewer-head h2 {
            margin: 0;
        }

        .viewer-nav {
            display: flex;
            gap: 0.4rem;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            padding: 0.4rem 0.8rem;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--accent-soft);
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9em;
            cursor: pointer;
        }

        .nav-btn:hover {
            background: #e9cfae;
        }

        .nav-btn.disabled,
        .nav-btn[aria-disabled="true"] {
            opacity: 0.4;
            pointer-events: none;
            cursor: default;
        }

        .row a.active {
            color: var(--accent);
            text-decoration: underline;
        }

        .row a.active::before {
            content: "\25B8\00a0";
        }

        .list {
            display: grid;
            gap: 0.65rem;
        }

        .row {
            display: grid;
            gap: 0.15rem;
            padding: 0.8rem 0.9rem;
            border-radius: 14px;
            border: 1px solid rgba(138, 75, 20, 0.12);
            background: var(--panel-strong);
        }

        .row a {
            font-weight: 700;
            text-decoration: none;
        }

        .row a:hover {
            text-decoration: underline;
        }

        .row-meta {
            color: var(--muted);
            font-size: 0.92em;
        }

        .pagination {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.9rem;
        }

        .page-status {
            flex: 1 1 100%;
            text-align: center;
            color: var(--muted);
            font-size: 0.92em;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            margin-top: 0.85rem;
        }

        .button {
            display: inline-block;
            padding: 0.65rem 0.9rem;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--accent-soft);
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
        }

        .viewer-box {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(138, 75, 20, 0.12);
            overflow: hidden;
            min-height: 20rem;
        }

        iframe, object {
            width: 100%;
            height: 75vh;
            border: 0;
            display: block;
            background: #f8f8f8;
        }

        img.preview {
            display: block;
            width: 100%;
            height: auto;
            background: #fff;
        }

        pre.text-preview {
            margin: 0;
            padding: 1rem;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 1rem;
            line-height: 1.5;
            font-family: "Courier New", Courier, monospace;
            background: #fffdfa;
        }

        .empty {
            color: var(--muted);
            padding: 1rem 0.25rem 0.25rem;
        }

        @media (min-width: 860px) {
            body { font-size: var(--desktop-text); }
            .content { grid-template-columns: minmax(320px, 380px) minmax(0, 1fr); }
            .page { width: min(1280px, calc(100% - 2rem)); margin-top: 1rem; }
            .masthead { padding: 1.3rem 1.3rem 1rem; }
            .panel { padding: 1rem; }
            #viewer, #browser {
                position: sticky;
                top: 1rem;
                align-self: start;
                max-height: calc(100vh - 2rem);
                overflow: auto;
            }
            #viewer { order: 2; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="shell">
            <header class="masthead">
                <h1 id="pageTitle"><?= h($viewer['name'] ?? ($currentDir === '' ? 'Tabs Library' : basename($currentDir))) ?></h1>
                <div class="subtitle">
                    Simple file browser with larger mobile text and direct previews for images, PDFs, and text tabs.
                </div>
                <nav class="breadcrumbs" aria-label="Breadcrumb">
                    <?php foreach ($breadcrumbs as $crumb): ?>
                        <a class="crumb" href="?dir=<?= h(rawurlencodePath($crumb['path'])) ?>"><?= h($crumb['label']) ?></a>
                    <?php endforeach; ?>
                </nav>
            </header>

            <main class="content">
                <section class="panel" id="viewer">
                    <div class="viewer-head">
                        <h2>Viewer</h2>
                        <div class="viewer-nav">
                            <a class="nav-btn<?= $prevFile === null ? ' disabled' : '' ?>" id="prevBtn"
                               <?= $prevFile === null ? 'aria-disabled="true"' : 'href="' . h(buildViewerUrl($currentDir, $prevFile['path'], intdiv($currentFileIndex - 1, FILES_PER_PAGE) + 1, true)) . '"' ?>>‹ Prev</a>
                            <a class="nav-btn<?= $nextFile === null ? ' disabled' : '' ?>" id="nextBtn"
                               <?= $nextFile === null ? 'aria-disabled="true"' : 'href="' . h(buildViewerUrl($currentDir, $nextFile['path'], intdiv($currentFileIndex + 1, FILES_PER_PAGE) + 1, true)) . '"' ?>>Next ›</a>
                        </div>
                    </div>
                    <?php if ($viewer !== null): ?>
                    <div class="meta" id="viewerMeta">
                        <?= h($viewer['mime']) ?> · <?= h(formatBytes((int) $viewer['size'])) ?>
                    </div>
                    <div class="actions" id="viewerActions">
                        <a class="button" href="<?= h(buildDirectoryUrl($viewer['parent'], $currentPage)) ?>">Back to folder</a>
                        <a class="button" id="openBtn" href="<?= h($viewer['url']) ?>" target="_blank" rel="noopener">Open original file</a>
                    </div>
                    <div class="viewer-box" id="viewerBox" style="margin-top: 0.9rem;">
                        <?php if ($viewer['kind'] === 'image'): ?>
                            <img class="preview" src="<?= h($viewer['url']) ?>" alt="<?= h($viewer['name']) ?>">
                        <?php elseif ($viewer['kind'] === 'pdf'): ?>
                            <iframe src="<?= h($viewer['url']) ?>" title="<?= h($viewer['name']) ?>"></iframe>
                        <?php elseif ($viewer['kind'] === 'text' && !$viewer['can_preview_text']): ?>
                            <div class="empty">This text file is too large to preview safely. Use "Open original file" instead.</div>
                        <?php elseif ($viewer['kind'] === 'text' && $viewer['text_error']): ?>
                            <div class="empty">Could not load this text file.</div>
                        <?php elseif ($viewer['kind'] === 'text'): ?>
                            <pre class="text-preview"><?= h((string) $viewer['text']) ?></pre>
                        <?php else: ?>
                            <div class="empty">
                                No inline preview for this file type. Use "Open original file" to view or download it.
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="meta" id="viewerMeta" hidden></div>
                    <div class="actions" id="viewerActions" hidden>
                        <a class="button" href="<?= h(buildDirectoryUrl($currentDir, $currentPage)) ?>">Back to folder</a>
                        <a class="button" id="openBtn" href="#" target="_blank" rel="noopener">Open original file</a>
                    </div>
                    <div class="viewer-box" id="viewerBox" style="margin-top: 0.9rem;">
                        <div class="empty">Pick a file to preview it here.</div>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="panel" id="browser">
                    <h2>Folders</h2>
                    <?php if ($currentDir !== ''): ?>
                        <div class="row">
                            <a href="?dir=<?= h(rawurlencodePath(dirname($currentDir) === '.' ? '' : dirname($currentDir))) ?>">.. Parent folder</a>
                        </div>
                    <?php endif; ?>

                    <div class="list">
                        <?php foreach ($items['directories'] as $directory): ?>
                            <div class="row">
                                <a href="?dir=<?= h(rawurlencodePath($directory['path'])) ?>"><?= h($directory['name']) ?>/</a>
                                <div class="row-meta">Folder</div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h2 style="margin-top: 1.1rem;">Files (<?= $totalFiles ?>)</h2>
                    <?php if ($totalFiles === 0): ?>
                        <div class="empty">No files in this folder.</div>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($pageFiles as $i => $file): ?>
                                <div class="row">
                                    <a class="file-link" data-index="<?= $i ?>" href="<?= h(buildViewerUrl($currentDir, $file['path'], $currentPage, true)) ?>"><?= h($file['name']) ?></a>
                                    <div class="row-meta"><?= h($file['type']) ?> · <?= h(formatBytes((int) $file['size'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <nav class="pagination" aria-label="File pages">
                            <?php if ($currentPage > 1): ?>
                                <a class="nav-btn" href="<?= h(buildDirectoryUrl($currentDir, $currentPage - 1)) ?>">‹ Previous page</a>
                            <?php else: ?>
                                <span class="nav-btn disabled" aria-disabled="true">‹ Previous page</span>
                            <?php endif; ?>
                            <span class="page-status">Page <?= $currentPage ?> of <?= $totalPages ?> · Showing <?= $pageOffset + 1 ?>–<?= min($pageOffset + count($pageFiles), $totalFiles) ?> of <?= $totalFiles ?></span>
                            <?php if ($currentPage < $totalPages): ?>
                                <a class="nav-btn" href="<?= h(buildDirectoryUrl($currentDir, $currentPage + 1)) ?>">Next page ›</a>
                            <?php else: ?>
                                <span class="nav-btn disabled" aria-disabled="true">Next page ›</span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>
    <script>
        (function () {
            const FILES = <?= jsonForHtml($filesForJs) ?>;
            const BASE_TITLE = <?= jsonForHtml($currentDir === '' ? 'Tabs' : basename($currentDir)) ?>;
            const BASE_HEADING = <?= jsonForHtml($currentDir === '' ? 'Tabs Library' : basename($currentDir)) ?>;
            let currentIndex = <?= $currentPageFileIndex ?>;

            const box = document.getElementById('viewerBox');
            const meta = document.getElementById('viewerMeta');
            const actions = document.getElementById('viewerActions');
            const openBtn = document.getElementById('openBtn');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const pageTitle = document.getElementById('pageTitle');

            if (!box) {
                return;
            }


            function formatBytes(bytes) {
                if (bytes < 1024) {
                    return bytes + ' B';
                }
                const units = ['KB', 'MB', 'GB', 'TB'];
                let size = bytes / 1024;
                let i = 0;
                while (size >= 1024 && i < units.length - 1) {
                    size /= 1024;
                    i++;
                }
                return (size < 10 ? size.toFixed(1) : Math.round(size)) + ' ' + units[i];
            }

            function renderBox(file) {
                box.textContent = '';
                if (file.kind === 'image') {
                    const img = document.createElement('img');
                    img.className = 'preview';
                    img.src = file.url;
                    img.alt = file.name;
                    box.appendChild(img);
                } else if (file.kind === 'pdf') {
                    const frame = document.createElement('iframe');
                    frame.src = file.url;
                    frame.title = file.name;
                    box.appendChild(frame);
                } else if (file.kind === 'text') {
                    if (!file.canPreviewText) {
                        const div = document.createElement('div');
                        div.className = 'empty';
                        div.textContent = 'This text file is too large to preview safely. Use "Open original file" instead.';
                        box.appendChild(div);
                        return;
                    }
                    const pre = document.createElement('pre');
                    pre.className = 'text-preview';
                    pre.textContent = 'Loading…';
                    box.appendChild(pre);
                    fetch(file.url)
                        .then(function (r) {
                            if (!r.ok) {
                                throw new Error('Request failed');
                            }
                            return r.text();
                        })
                        .then(function (t) { pre.textContent = t; })
                        .catch(function () { pre.textContent = 'Could not load this file.'; });
                } else {
                    const div = document.createElement('div');
                    div.className = 'empty';
                    div.textContent = 'No inline preview for this file type. Use "Open original file" to view or download it.';
                    box.appendChild(div);
                }
            }

            function highlight(index) {
                document.querySelectorAll('.file-link.active').forEach(function (a) {
                    a.classList.remove('active');
                });
                const link = document.querySelector('.file-link[data-index="' + index + '"]');
                if (link) {
                    link.classList.add('active');
                }
            }

            function setNavButton(btn, url) {
                if (!btn) {
                    return;
                }
                if (!url) {
                    btn.classList.add('disabled');
                    btn.setAttribute('aria-disabled', 'true');
                    btn.removeAttribute('href');
                } else {
                    btn.classList.remove('disabled');
                    btn.removeAttribute('aria-disabled');
                    btn.setAttribute('href', url);
                }
            }

            function updateNav() {
                if (currentIndex < 0) {
                    setNavButton(prevBtn, null);
                    setNavButton(nextBtn, FILES.length ? FILES[0].historyUrl + '#viewer' : null);
                    return;
                }
                const file = FILES[currentIndex];
                setNavButton(prevBtn, file ? file.prevUrl : null);
                setNavButton(nextBtn, file ? file.nextUrl : null);
            }

            function applyFile(index) {
                const file = FILES[index];
                if (!file) {
                    return;
                }
                currentIndex = index;
                document.title = file.name;
                if (pageTitle) {
                    pageTitle.textContent = file.name;
                }
                if (meta) {
                    meta.hidden = false;
                    meta.textContent = file.type + ' · ' + formatBytes(file.size);
                }
                if (actions) {
                    actions.hidden = false;
                }
                if (openBtn) {
                    openBtn.href = file.url;
                }
                renderBox(file);
                highlight(index);
                updateNav();
                revealViewer();
            }

            function revealViewer() {
                const section = document.getElementById('viewer');
                if (!section) {
                    return;
                }
                const rect = section.getBoundingClientRect();
                // Only scroll if the viewer's top is off-screen (above or below the viewport).
                if (rect.top < 0 || rect.top > window.innerHeight) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            function showFile(index) {
                if (index < 0 || index >= FILES.length) {
                    return;
                }
                applyFile(index);
                history.pushState({ index: index }, '', FILES[index].historyUrl);
            }

            // Intercept file list clicks.
            document.querySelectorAll('.file-link').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) {
                        return;
                    }
                    e.preventDefault();
                    showFile(parseInt(link.dataset.index, 10));
                });
            });

            // Prev / Next use their generated links so crossing a page boundary remains reliable.

            // Keep browser back/forward in sync.
            window.addEventListener('popstate', function (e) {
                const idx = e.state && typeof e.state.index === 'number' ? e.state.index : -1;
                if (idx >= 0) {
                    applyFile(idx);
                } else {
                    currentIndex = -1;
                    document.title = BASE_TITLE;
                    if (pageTitle) {
                        pageTitle.textContent = BASE_HEADING;
                    }
                    highlight(-1);
                    updateNav();
                    if (meta) { meta.hidden = true; }
                    if (actions) { actions.hidden = true; }
                    box.textContent = '';
                    const div = document.createElement('div');
                    div.className = 'empty';
                    div.textContent = 'Pick a file to preview it here.';
                    box.appendChild(div);
                }
            });

            history.replaceState({ index: currentIndex }, '', location.href);
            highlight(currentIndex);
            updateNav();
        })();
    </script>
</body>
</html>
