<?php
/**
 * Generate individual photo detail pages in /custom/photography/
 * Run this after updating photography.json
 */

$dataFile = __DIR__ . '/data/photography.json';
$outputDir = __DIR__ . '/custom/photography';

if (!file_exists($dataFile)) {
    die("Error: $dataFile not found\n");
}

$photos = json_decode(file_get_contents($dataFile), true);
if (!is_array($photos)) {
    die("Error: Invalid JSON in $dataFile\n");
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Clean old files (optional - comment out if you want to keep old pages)
$existing = glob("$outputDir/*.html");
foreach ($existing as $file) {
    if (basename($file) !== 'index.html') {
        unlink($file);
    }
}

// Generate page for each photo
foreach ($photos as $photo) {
    $title = $photo['title'] ?? 'Untitled';
    $description = $photo['description'] ?? '';
    $username = $photo['username'] ?? '';
    $image = $photo['image'] ?? '';
    
    // Create URL-friendly slug
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    
    // Create meta section only if username exists
    $metaSection = $username ? "<div class=\"photo-meta\">\n        <strong>By:</strong> {$username}\n      </div>" : '';
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$title} - Poyo Photography</title>
  <link rel="stylesheet" href="../../web/main.css">
  <link rel="icon" type="image/png" href="../../assets/happy-sprout.png" />
  <style>
    .photo-detail {
      max-width: 1000px;
      margin: 40px auto;
      padding: 20px;
    }

    .photo-detail img {
      width: 100%;
      height: auto;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      margin-bottom: 24px;
    }

    .photo-info {
      background: rgba(255, 255, 255, 0.9);
      padding: 24px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .photo-info h1 {
      margin: 0 0 16px 0;
      font-size: 2rem;
      color: #333;
    }

    .photo-info p {
      margin: 0 0 16px 0;
      line-height: 1.6;
      color: #555;
      font-size: 1.1rem;
    }

    .photo-meta {
      color: #666;
      font-size: 0.9rem;
      border-top: 1px solid #ddd;
      padding-top: 16px;
      margin-top: 16px;
    }

    .back {
      display: inline-block;
      margin: 20px;
      padding: 10px 20px;
      background: rgba(255, 255, 255, 0.9);
      border-radius: 8px;
      text-decoration: none;
      color: #333;
      font-weight: 600;
      transition: transform 0.2s;
    }

    .back:hover {
      transform: translateX(-4px);
    }
  </style>
</head>
<body class="stuff-body">
  <a class="back" href="../../web/photography.html">← Back to Gallery</a>
  
  <div class="photo-detail">
    <img src="{$image}" alt="{$title}">
    
    <div class="photo-info">
      <h1>{$title}</h1>
      <p>{$description}</p>
      {$metaSection}
    </div>
  </div>
</body>
</html>
HTML;
    
    $filename = "$outputDir/$slug.html";
    file_put_contents($filename, $html);
    echo "Generated: $slug.html\n";
}

echo "\nDone! Generated " . count($photos) . " photo pages.\n";
?>
