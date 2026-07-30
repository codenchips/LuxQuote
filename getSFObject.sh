vendor/bin/sail artisan tinker --execute '

# "Account", "User", or "Opportunity".
$object = "Account";

$sf = app(\App\Services\SalesforceService::class);
$m = new ReflectionMethod($sf, "authenticate");
$auth = $m->invoke($sf);

$http = \Illuminate\Support\Facades\Http::withToken($auth["token"])->acceptJson();

$describe = $http
    ->get($auth["instanceUrl"]."/services/data/v65.0/sobjects/".$object."/describe")
    ->json();

$fields = collect($describe["fields"] ?? [])
    ->pluck("name")
    ->filter()
    ->unique()
    ->values()
    ->all();

$idQuery = "SELECT Id FROM ".$object." ORDER BY CreatedDate DESC LIMIT 1";
$idResponse = $http
    ->get($auth["instanceUrl"]."/services/data/v65.0/query/", ["q" => $idQuery])
    ->json();

$id = $idResponse["records"][0]["Id"] ?? null;

if (! $id) {
    $idResponse = $http
        ->get($auth["instanceUrl"]."/services/data/v65.0/query/", ["q" => "SELECT Id FROM ".$object." LIMIT 1"])
        ->json();

    $id = $idResponse["records"][0]["Id"] ?? null;
}

if (! $id) {
    echo "No ".$object." record found.".PHP_EOL;
    return;
}

$record = ["Id" => $id];
$skipped = [];

$fetch = function (array $fieldChunk) use (&$fetch, &$record, &$skipped, $http, $auth, $object, $id): void {
    $query = "SELECT ".implode(", ", $fieldChunk)." FROM ".$object." WHERE Id = ".chr(39).$id.chr(39)." LIMIT 1";
    $response = $http->get($auth["instanceUrl"]."/services/data/v65.0/query/", ["q" => $query])->json();

    if (isset($response["records"][0])) {
        $record = array_replace($record, $response["records"][0]);
        return;
    }

    if (count($fieldChunk) === 1) {
        $skipped[] = $fieldChunk[0];
        return;
    }

    foreach (array_chunk($fieldChunk, max(1, intdiv(count($fieldChunk), 2))) as $smallerChunk) {
        $fetch($smallerChunk);
    }
};

foreach (array_chunk($fields, 100) as $fieldChunk) {
    $fetch($fieldChunk);
}

echo json_encode([
    "object" => $object,
    "record" => $record,
    "skipped_fields" => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
'