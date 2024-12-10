<?php

namespace Ze\OpenAi;

use GuzzleHttp\Client;
use GuzzleHttp\Stream\Stream;
use Illuminate\Support\Facades\Log;
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

    /** 回调方法
     * @var callable
     */
    private $streamMethod;

    /** 回调方法
     * @var callable
     */
    public $endMethod = null;

    private $response = "";

    private int $timeout = 300;
    private array $curlConfig = [];

    private string $apiBaseUrl = 'https://goldentech.openai.azure.com/openai';
    private string $apiVersion = '2023-07-01';

    public array $models = [
        'gpt35' => 'gpt-3.5-turbo',
    ];

    private array $opts = [];

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
        $url = $this->apiBaseUrl . $url . '?api-version=' . $this->apiVersion;

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

            $errorMsg = 'HTTP CODE [' . $httpCode . ']';

            $errJson = json_decode($response, true);

            if (isset($errJson['object']) && $errJson['object'] == 'error') {
                $errJson['error'] = [
                    'type'    => $errJson['code'],
                    'message' => $errJson['message'],
                ];
            }

            if (isset($errJson['error'])) {
                $errCode = $errJson['error']['type'] ?? $errJson['error']['code'] ?? 'Unknown Err';
                $errorMsg = $errCode . '|' . $errJson['error']['message'];
            }

            throw new OpenAiException('OpenAIError: ' . $errorMsg, $httpCode);
        }

        curl_close($curl);

        return $response;
    }

    /**
     * @param string $url
     * @param array $data
     * @return array
     * @throws OpenAiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function sendRequestStream(string $url, array $data = [])
    {
        $url = $this->apiBaseUrl . $url . '?api-version=' . $this->apiVersion;
        $body = (new Client)->request("POST", $url, $this->opts)->getBody();

        $result = [];
        $this->response = $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(256);
            while (($pos = strpos($buffer, PHP_EOL . PHP_EOL)) !== false) {
                $data = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                empty($result['raw']) && $result['raw'] = $data;
                $msg = strtolower(trim($data));
                if (connection_aborted() || $data === "data: [DONE]") {
                    is_callable($this->endMethod) && call_user_func($this->endMethod, $data);
                    break 2;
                } elseif (in_array($msg, ['data: [continue]', "rate limit.."]) || "data: " != substr($data, 0, 6)) {
                    throw new OpenAiException("OpenAi error: " . $data, 500);
                }

                $json = json_decode(substr($data, 6), true);
                if (empty($json) || JSON_ERROR_NONE !== json_last_error()) {
                    throw new OpenAiException("OpenAi JSON error: " . json_last_error_msg() . ": {$data}", json_last_error());
                }

                $text = $json['choices'][0]['delta']['content'] ?? "";
                $this->response .= $text;
                $this->streamClient($text);
            }
        }
        echo PHP_EOL . PHP_EOL;
        $result['content'] = $this->response;
        return $result;
    }

    protected function streamClient($text)
    {
        if (!headers_sent()) {
            header("Content-Type: text/event-stream");
            header("X-Accel-Buffering: no");
            header("Cach-Control: no-cache");
        }

        echo Stream::factory(is_callable($this->streamMethod) ? call_user_func($this->streamMethod, $text) : $text);

        ob_get_length() && ob_flush();
        flush();
    }

    public function chatStream(string $model = "gpt35")
    {
        $headers = [];
        foreach ($this->headers as $item) {
            $one = explode(":", $item);
            $headers[ trim($one[0]) ] = trim($one[1]);
        }

        $model = str_replace('.', '', $model);
        $this->opts = array_merge([
            'stream' => true,
            "timeout" => $this->timeout,
            'model' => $this->models[$model],
            "headers" => $headers,
        ], $this->opts);

        return $this->sendRequestStream("/deployments/{$model}/chat/completions");
    }

    public function setFunc($name, callable $func = null)
    {
        is_callable($func) && $this->$name = $func;
        return $this;
    }

    public function setOpts(array $data)
    {
        $data['stream'] = true;
        $this->opts['json'] = $data;
        return $this;
    }

    public function embeddings(array $opts)
    {
        $model = str_replace('.', '', $opts['model']);

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

        $model = str_replace('.', '', $opts['model']);

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
