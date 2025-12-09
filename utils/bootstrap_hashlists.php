<?php
/**
 * Bootstrap Personal Hashlist from DMM
 * 
 * This script imports hashes from DMM hashlists into your personal hashlist
 * Run once to get started, then your hashlist grows as you stream content
 * 
 * Usage: php bootstrap_hashlists.php [num_files_to_import]
 */

require_once __DIR__ . '/../libs/dmm_hashlists.php';
require_once __DIR__ . '/../libs/personal_hashlists.php';

$maxFiles = isset($argv[1]) ? intval($argv[1]) : 10;

echo "🚀 Bootstrapping Personal Hashlist from DMM\n";
echo "============================================\n\n";

$dmm = new DMMHashlists();
$personal = new PersonalHashlists();

// Get initial stats
$beforeStats = $personal->getStats();
echo "📊 Before: {$beforeStats['total_hashes']} hashes\n\n";

// Get list of DMM hashlists
echo "📥 Fetching DMM hashlist files...\n";
$files = $dmm->getHashlistFiles();
echo "Found " . count($files) . " hashlist files\n";

$files = array_slice($files, 0, $maxFiles);
echo "Will import from first $maxFiles files\n\n";

$totalImported = 0;

foreach ($files as $i => $file) {
    echo "📂 [" . ($i + 1) . "/$maxFiles] Processing: $file\n";
    
    $data = $dmm->decodeHashlist($file);
    if (!$data) {
        echo "   ❌ Failed to decode\n";
        continue;
    }
    
    $imported = 0;
    foreach ($data as $torrent) {
        if (isset($torrent['hash'])) {
            $personal->addHash($torrent['hash'], [
                'filename' => $torrent['filename'] ?? 'Imported from DMM',
                'bytes' => $torrent['bytes'] ?? 0
            ]);
            $imported++;
        }
    }
    
    echo "   ✅ Imported $imported hashes\n";
    $totalImported += $imported;
}

// Get final stats
$afterStats = $personal->getStats();
echo "\n============================================\n";
echo "📊 Import Complete!\n";
echo "   Before: {$beforeStats['total_hashes']} hashes\n";
echo "   After: {$afterStats['total_hashes']} hashes\n";
echo "   New: " . ($afterStats['total_hashes'] - $beforeStats['total_hashes']) . " hashes\n";
echo "   Total Size: {$afterStats['total_size_gb']} GB\n\n";

echo "💡 Tip: Your hashlist will grow automatically as you stream content!\n";
echo "    Each successful RD torrent stream will be saved.\n";
