<?php
/**
 * Personal Hashlist Resolver
 * 
 * Looks up IMDB IDs in your personal hashlist and returns cached RD streams
 * 
 * This is the "read" side - complements play.php which is the "write" side
 * that saves successful hashes.
 * 
 * Usage:
 *   GET /hashlist_resolver.php?imdb=tt0118480&season=5&episode=1
 *   GET /hashlist_resolver.php?imdb=tt0133093  (for movies)
 *   GET /hashlist_resolver.php?action=stats
 */

require_once __DIR__ . '/../libs/personal_hashlists.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'lookup';
$imdbId = $_GET['imdb'] ?? null;
$season = $_GET['season'] ?? null;
$episode = $_GET['episode'] ?? null;

$hashlist = new PersonalHashlists();

switch ($action) {
    case 'stats':
        echo json_encode($hashlist->getStats(), JSON_PRETTY_PRINT);
        break;
        
    case 'export':
        // Export in DMM format
        echo $hashlist->exportToDMM();
        break;
        
    case 'all':
        // Get all hashes (for debugging)
        $all = $hashlist->getAllHashes();
        echo json_encode([
            'count' => count($all),
            'hashes' => array_slice($all, 0, 100, true) // Limit to first 100
        ], JSON_PRETTY_PRINT);
        break;
        
    case 'lookup':
    default:
        if (!$imdbId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing imdb parameter']);
            exit;
        }
        
        // Find hashes for this IMDB ID
        $results = $hashlist->findByImdb($imdbId, $season, $episode);
        
        if (empty($results)) {
            echo json_encode([
                'imdb' => $imdbId,
                'season' => $season,
                'episode' => $episode,
                'found' => false,
                'message' => 'No hashes found in personal hashlist',
                'hashes' => []
            ]);
            exit;
        }
        
        // Optionally resolve with RD (if configured)
        $resolved = [];
        if (isset($PRIVATE_TOKEN) && !empty($PRIVATE_TOKEN)) {
            foreach ($results as &$result) {
                // Add magnet link
                $result['magnet'] = "magnet:?xt=urn:btih:" . $result['hash'];
                
                // Could add instant availability check here
                // but that requires the RD API which has rate limits
            }
        }
        
        echo json_encode([
            'imdb' => $imdbId,
            'season' => $season,
            'episode' => $episode,
            'found' => true,
            'count' => count($results),
            'hashes' => $results
        ], JSON_PRETTY_PRINT);
        break;
}
