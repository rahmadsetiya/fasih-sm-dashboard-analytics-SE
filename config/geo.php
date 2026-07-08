<?php

return [
    'source' => env('GEOJSON_PATH', storage_path('app/final_sls_202517316.geojson')),
    'prepared' => storage_path('app/private/geo/boundaries.json'),
];
