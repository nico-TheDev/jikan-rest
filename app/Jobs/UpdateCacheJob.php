<?php

namespace App\Jobs;

use App\Http\HttpHelper;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


/**
 * Class UpdateCacheJob
 * @package App\Jobs
 */
class UpdateCacheJob extends Job
{

    /**
     * @var string
     */
    protected $requestUri;
    protected $requestUriHash;
    protected $requestType;
    protected $requestCacheTtl;
    protected $requestCacheExpiry;
    protected $fingerprint;
    protected $cacheExpiryFingerprint;
    protected $requestCached;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Request $request)
    {
        $this->requestUriHash = HttpHelper::getRequestUriHash($request);
        $this->requestType = HttpHelper::requestType($request);

        $this->requestCacheTtl = HttpHelper::requestCacheExpiry($this->requestType);
        $this->requestCacheExpiry = time() + $this->requestCacheTtl;

        $this->fingerprint = "request:{$this->requestType}:{$this->requestUriHash}";
        $this->cacheExpiryFingerprint = "ttl:{$this->fingerprint}";

        $this->requestCached = (bool) Cache::has($this->fingerprint);
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle() : void
    {

        $queueFingerprint = "queue_update:{$this->fingerprint}";

        $client = new Client();

        $response = $client
            ->request(
                'GET',
                env('APP_URL') . $this->requestUri,
                [
                    'headers' => [
                        'auth' => env('APP_KEY') // skip middleware
                    ]
                ]
            );

        $cache = json_decode($response->getBody()->getContents(), true);
        unset($cache['request_hash'], $cache['request_cached'], $cache['request_cache_expiry']);
        $cache = json_encode($cache);


        Cache::forever($this->fingerprint, $cache);
        Cache::forever($this->cacheExpiryFingerprint, time() + $this->requestCacheTtl);
        app('redis')->del($queueFingerprint);

        sleep((int) env('QUEUE_DELAY_PER_JOB', 5));
    }

    public function failed(\Exception $e)
    {
        Log::error($e->getMessage());
    }
}
