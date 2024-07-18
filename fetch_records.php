<?php
// Cloudflare DNS Records Fetcher (Backend)

header('Content-Type: text/plain');

// Verify Turnstile token, dont forget to add your own key
$turnstileSecret = 'YOUR_TURNSTILE_SECRET_KEY';
$turnstileResponse = $_POST['cf-turnstile-response'] ?? '';

if (empty($turnstileResponse)) {
    die("Error: Captcha verification failed. Please try again.");
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://challenges.cloudflare.com/turnstile/v0/siteverify");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'secret' => $turnstileSecret,
    'response' => $turnstileResponse,
    'remoteip' => $_SERVER['REMOTE_ADDR']
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$turnstileResult = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!$turnstileResult['success']) {
    die("Error: Captcha verification failed. Please try again.");
}

// Initialize variables
$apiToken = $_POST['api_token'] ?? '';
$accountId = $_POST['account_id'] ?? '';
$zoneIds = $_POST['zone_ids'] ?? '';
$mode = $_POST['mode'] ?? 'account';
$results = '';

// Function to make API requests
function makeApiRequest($apiToken, $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$apiToken}",
        "Content-Type: application/json"
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    } else {
        return null;
    }
}

// Function to fetch and format DNS records for a zone
function fetchAndFormatDnsRecords($apiToken, $zoneId) {
    $recordsUrl = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records";
    $recordsResponse = makeApiRequest($apiToken, $recordsUrl);

    $result = "";
    if ($recordsResponse && isset($recordsResponse['result'])) {
        $result .= "Type\tName\tContent\tTTL\tProxied\n";
        foreach ($recordsResponse['result'] as $record) {
            $result .= "{$record['type']}\t{$record['name']}\t{$record['content']}\t{$record['ttl']}\t" . ($record['proxied'] ? 'Yes' : 'No') . "\n";
        }
    } else {
        $result .= "Failed to fetch records for this zone.\n";
    }
    return $result;
}

if ($mode === 'account') {
    // Fetch zones for the account
    $zonesUrl = "https://api.cloudflare.com/client/v4/zones?account.id={$accountId}&page=1&per_page=50";
    $zonesResponse = makeApiRequest($apiToken, $zonesUrl);

    if ($zonesResponse && isset($zonesResponse['result'])) {
        foreach ($zonesResponse['result'] as $zone) {
            $zoneId = $zone['id'];
            $zoneName = $zone['name'];
            $results .= "Zone: {$zoneName} (ID: {$zoneId})\n\n";
            $results .= fetchAndFormatDnsRecords($apiToken, $zoneId);
            $results .= "\n";
        }
    } else {
        $results = "Failed to fetch zones. Please check your API Token and Account ID.";
    }
} else {
    // Fetch records for specific zones
    $zoneIdArray = array_filter(array_map('trim', explode(',', $zoneIds)));
    foreach ($zoneIdArray as $zoneId) {
        $zoneUrl = "https://api.cloudflare.com/client/v4/zones/{$zoneId}";
        $zoneResponse = makeApiRequest($apiToken, $zoneUrl);

        if ($zoneResponse && isset($zoneResponse['result'])) {
            $zoneName = $zoneResponse['result']['name'];
            $results .= "Zone: {$zoneName} (ID: {$zoneId})\n\n";
            $results .= fetchAndFormatDnsRecords($apiToken, $zoneId);
            $results .= "\n";
        } else {
            $results .= "Failed to fetch info for Zone ID: {$zoneId}\n\n";
        }
    }
}

echo $results;
