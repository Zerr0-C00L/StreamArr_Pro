<?php
/**
 * XMLTV EPG Server
 * 
 * Serves the cached EPG file (populated by background_sync_daemon.php)
 * The EPG is fetched from Pluto TV during sync
 */

$cacheXmlFile = __DIR__ . '/channels/epg.xml';
$cacheGzFile  = __DIR__ . '/channels/epg.xml.gz';

// Check if client accepts gzip
$acceptGzip = strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;

// Prefer uncompressed XML for compatibility
if (file_exists($cacheXmlFile) && filesize($cacheXmlFile) > 100) {
    // Serve uncompressed XML
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Length: ' . filesize($cacheXmlFile));
    readfile($cacheXmlFile);
    exit;
}

// Fallback to gzipped version
if (file_exists($cacheGzFile) && filesize($cacheGzFile) > 100) {
    if ($acceptGzip) {
        // Serve gzipped directly
        header('Content-Type: application/gzip');
        header('Content-Encoding: gzip');
        header('Content-Length: ' . filesize($cacheGzFile));
        readfile($cacheGzFile);
    } else {
        // Decompress and serve
        header('Content-Type: application/xml; charset=utf-8');
        $content = file_get_contents('compress.zlib://' . $cacheGzFile);
        header('Content-Length: ' . strlen($content));
        echo $content;
    }
    exit;
}

// No EPG available - return minimal valid XML
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<tv generator-info-name="tmdb-to-vod-playlist"></tv>' . "\n";

