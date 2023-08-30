<?php

namespace Ze\OpenAi;

use Ze\OpenAi\Exceptions\OpenAiException;

class OpenAi
{
    private string $model = "text-davinci-003";
    private string $chatModel = "gpt-3.5-turbo";
    private array $headers;
    private array $contentTypes;
    private object $streamMethod;

    private int $timeout = 300;
    private array $curlConfig = [];
    private string $apiBaseUrl = 'https://api.openai.com/v1';

    public function __construct(string $OPENAI_API_KEY = '', string $OPENAI_ORG = '')
    {
        $this->contentTypes = [
            "application/json" => "Content-Type: application/json",
            "multipart/form-data" => "Content-Type: multipart/form-data",
        ];

        $this->headers = [
            $this->contentTypes["application/json"],
            "Authorization: Bearer $OPENAI_API_KEY",
        ];

        if ($OPENAI_ORG != "") {
            $this->headers[] = "OpenAI-Organization: $OPENAI_ORG";
        }
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

    public function listModels()
    {
        return $this->sendRequest('/models', 'GET');
    }

    public function retrieveModel($model)
    {
        return $this->sendRequest('/models/' . $model, 'GET');
    }

    public function completion($opts, $stream = null)
    {
        if ($stream != null && array_key_exists('stream', $opts)) {
            if (! $opts['stream']) {
                throw new OpenAiException(
                    'Please provide a stream function.'
                );
            }

            $this->streamMethod = $stream;
        }

        $opts['model'] = $opts['model'] ?? $this->model;

        return $this->sendRequest('/completions', 'POST', $opts);
    }

    public function createEdit($opts)
    {
        return $this->sendRequest('/edits', 'POST', $opts);
    }

    public function image($opts)
    {
        return $this->sendRequest('/images/generations', 'POST', $opts);
    }

    public function imageEdit($opts)
    {
        return $this->sendRequest('/images/edits', 'POST', $opts);
    }

    public function createImageVariation($opts)
    {
        return $this->sendRequest('/images/variations', 'POST', $opts);
    }

    public function moderation($opts)
    {
        return $this->sendRequest('/moderations', 'POST', $opts);
    }

    public function chat($opts, $stream = null)
    {
        if ($stream != null && array_key_exists('stream', $opts)) {
            if (! $opts['stream']) {
                throw new OpenAiException(
                    'Please provide a stream function. '
                );
            }

            $this->streamMethod = $stream;
        }

        $opts['model'] = $opts['model'] ?? $this->chatModel;

        return $this->sendRequest('/chat/completions', 'POST', $opts);
    }

    public function transcribe($opts)
    {
        return $this->sendRequest('/audio/transcriptions', 'POST', $opts);
    }

    public function translate($opts)
    {
        return $this->sendRequest('/audio/translations', 'POST', $opts);
    }

    public function uploadFile($opts)
    {
        return $this->sendRequest('/files', 'POST', $opts);
    }

    public function listFiles()
    {
        return $this->sendRequest('/files', 'GET');
    }

    public function retrieveFile($fileId)
    {
        return $this->sendRequest('/files/' . $fileId, 'GET');
    }

    public function retrieveFileContent($fileId)
    {
        return $this->sendRequest('/files/' . $fileId . '/content', 'GET');
    }

    public function deleteFile($fileId)
    {
        return $this->sendRequest('/files/' . $fileId, 'DELETE');
    }

    public function createFineTune($opts)
    {
        return $this->sendRequest('/fine-tunes', 'POST', $opts);
    }

    public function listFineTunes()
    {
        return $this->sendRequest('/fine-tunes', 'GET');
    }

    public function retrieveFineTune($fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' .$fineTuneId, 'GET');
    }

    public function cancelFineTune($fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' .$fineTuneId . '/cancel', 'POST');
    }

    public function listFineTuneEvents($fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' .$fineTuneId . '/events', 'GET');
    }

    public function deleteFineTune($fineTuneId)
    {
        return $this->sendRequest('/fine-tunes/' .$fineTuneId, 'DELETE');
    }

    public function embeddings($opts)
    {
        return $this->sendRequest('/embeddings', 'POST', $opts);
    }

    private function sendRequest(string $url, string $method, array $opts = [])
    {
        $url = $this->apiBaseUrl . '/' . $url;

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

        if ($httpCode != 200) {
            $errJson = json_decode($response, true);
            $httpErrMsg = "An error occurred [{$httpCode}]";
            if (isset($errJson['error'])) {
                $httpErrMsg = $errJson['error']['type'] . '|' . $errJson['error']['message'];
            }
            throw new OpenAiException('OpenAIError: ' . $httpErrMsg, $httpCode);
        }

        curl_close($curl);

        return $response;
    }
}
