<?php

namespace Ze\OpenAi;

use Ze\OpenAi\Exceptions\OpenAiException;

class AzureOpenAi
{
    const
        AUTH_API_KEY = 1,
        AUTH_API_TOKEN = 2;

    private $contentTypes = [
        'application/json' => 'Content-Type: application/json',
        'multipart/form-data' => 'Content-Type: multipart/form-data',
    ];

    private $streamMethod;

    private int $timeout = 300;
    private array $curlConfig = [];

    private string $apiBaseUrl = 'https://goldentech.openai.azure.com/openai';
    private string $apiVersion = '2023-07-01';

    public function __construct(string $authKey, string $apiVersion = null, int $authType = self::AUTH_API_KEY)
    {
        $this->headers = [
            $this->contentTypes['application/json']
        ];

        if ($authType == self::AUTH_API_KEY) {
            $this->headers[] = "api-key: {$authKey}";
        }

        if ($authType == self::AUTH_API_TOKEN) {
            $this->headers[] = "Authorization: Bearer {$authKey}";
        }

        $this->apiVersion = $apiVersion;
    }

    public function setApiBaseUrl(string $url)
    {
        $this->apiBaseUrl = $url;
    }

    public function setCurlConfig(array $conf)
    {
        $this->curlConfig = $conf;
    }

    public function setTimeout(int $timeout)
    {
        $this->timeout = $timeout;
    }

    private function sendRequest(string $url, string $method, array $opts = [])
    {
        $url = $this->apiBaseUrl . '/' . $url . '?api-version=' . $this->apiVersion;

        $postFields = json_encode($opts);

        if (array_key_exists('file', $opts) || array_key_exists('image', $opts)) {
            $this->headers[0] = $this->contentTypes["multipart/form-data"];
            $postFields = $opts;
        } else {
            $this->headers[0] = $this->contentTypes["application/json"];
        }

        $curlInfo = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => $this->headers,
        ] + $this->curlConfig;

        if ($opts == []) {
            unset($curlInfo[CURLOPT_POSTFIELDS]);
        }

        if (array_key_exists('stream', $opts) && $opts['stream']) {
            $curlInfo[CURLOPT_WRITEFUNCTION] = $this->streamMethod;
        }

        $curl = curl_init();
        curl_setopt_array($curl, $curlInfo);
        $response = curl_exec($curl);

        if ($response === false || curl_errno($curl)) {
            throw new OpenAiException('Curl error: ' . curl_error($curl));
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (! in_array($httpCode, [200, 201])) {
            $errJson = json_decode($response, true);
            $httpErrMsg = "An error occurred [{$httpCode}]";
            if (isset($errJson['error'])) {
                $httpErrMsg = $errJson['error']['code'] . '|' . $errJson['error']['message'];
            }
            throw new OpenAiException('OpenAIError: ' . $httpErrMsg, $httpCode);
        }

        curl_close($curl);

        return $response;
    }

    public function embeddings(array $opts)
    {
        unset($opts['model']);

        return $this->sendRequest('/deployments/' . $model .'/embeddings', 'POST', $opts);
    }

    public function chat(array $opts, $stream = null)
    {
        if ($stream != null && array_key_exists('stream', $opts)) {
            if (! $opts['stream']) {
                throw new OpenAiException(
                    'Please provide a stream function. '
                );
            }
            $this->streamMethod = $stream;
        }

        $model = str_replace('.', '', $opts['model']);

        unset($opts['model']);

        return $this->sendRequest('/deployments/' . $model .'/chat/completions', 'POST', $opts);
    }

    public function completion(array $opts, $stream = null)
    {
        if ($stream != null && array_key_exists('stream', $opts)) {
            if (! $opts['stream']) {
                throw new OpenAiException(
                    'Please provide a stream function.'
                );
            }
            $this->streamMethod = $stream;
        }

        unset($opts['model']);

        return $this->sendRequest('/deployments/' . $model .'/completions', 'POST', $opts);
    }

    public function deleteFile(string $fileId)
    {
        return $this->sendRequest('/files/' . $fileId, 'DELETE');
    }

    public function retrieveFile(string $fileId)
    {
        return $this->sendRequest('/files/' . $fileId, 'GET');
    }

    public function fileContent(string $fileId)
    {
        return $this->sendRequest('/files/' . $fileId . '/content', 'GET');
    }

    public function importFile(array $opts)
    {
        return $this->sendRequest('/files/import', 'POST', $opts);
    }

    // file upload
    public function uploadFile(array $opts)
    {
        return $this->sendRequest('/files', 'POST', $opts);
    }

    // get all files info
    public function listFiles()
    {
        return $this->sendRequest('/files', 'GET');
    }

    public function createFineTune(array $opts)
    {
        return $this->sendRequest('/fine-tunes', 'POST', $opts);
    }

    public function cancelFineTune(string $fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' . $fineTuneId . '/cancel', 'POST', [
            'fine_tune_id' => $fineTuneId,
        ]);
    }

    public function deleteFineTune(string $fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' . $fineTuneId, 'DELETE');
    }

    public function retrieveFineTune(string $fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' . $fineTuneId, 'GET');
    }

    public function retrieveFineTuneEvents(string $fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' . $fineTuneId . '/events', 'GET');
    }

    public function listFineTunes()
    {
        return $this->sendRequest('/fine-tunes', 'GET');
    }

    public function createDeployment(array $opts)
    {
        return $this->sendRequest('/deployments', 'POST', $opts);
    }

    public function deleteDeployment(string $deploymentId)
    {
        return $this->sendRequest('/deployments/' . $deploymentId, 'DELETE');
    }

    public function retrieveDeployment(string $deploymentId)
    {
        return $this->sendRequest('/deployments/' . $deploymentId, 'GET');
    }

    public function listDeployments()
    {
        return $this->sendRequest('/deployments', 'GET');
    }

    public function updateDeployment(string $deploymentId, array $opts)
    {
        return $this->sendRequest('/deployments/' . $deploymentId, 'PATCH', $opts);
    }

    public function retrieveModel(string $modelId)
    {
        return $this->sendRequest('/models/' . $modelId, 'GET');
    }

    public function listModels()
    {
        return $this->sendRequest('/models', 'GET');
    }
}
