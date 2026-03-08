<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client as HttpClient;

class HttpRequestCapability
{
    /**
     * Agent/Flow unified input:
     * - method: GET/POST/PUT/PATCH/DELETE
     * - url: string
     * - headers: object
     * - query: object
     * - bodyType: json|form|raw
     * - body: string (json/raw)
     * - bodyForm: object (form)
     * - timeout: number (seconds)
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $method = strtoupper((string)($input['method'] ?? 'GET'));
        $url = trim((string)($input['url'] ?? ''));
        if ($url === '') {
            throw new ExceptionBusiness('未配置请求地址');
        }

        $timeout = (float)($input['timeout'] ?? 30);
        if ($timeout <= 0) {
            $timeout = 30;
        }

        $client = new HttpClient([
            'timeout' => $timeout,
        ]);

        $options = [];
        $headers = is_array($input['headers'] ?? null) ? $input['headers'] : [];
        if ($headers !== []) {
            $options['headers'] = $headers;
        }

        $query = is_array($input['query'] ?? null) ? $input['query'] : [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        $bodyType = (string)($input['bodyType'] ?? $input['body_type'] ?? 'json');

        $requestRawBody = '';
        $jsonBody = null;
        $formParams = [];

        if ($bodyType === 'form') {
            $form = is_array($input['bodyForm'] ?? $input['body_form'] ?? null) ? ($input['bodyForm'] ?? $input['body_form']) : [];
            if ($form !== []) {
                $options['form_params'] = $form;
            }
            $formParams = $form;
        } else {
            $body = (string)($input['body'] ?? '');
            if ($body !== '') {
                if ($bodyType === 'json') {
                    $decoded = json_decode($body, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $options['json'] = $decoded;
                        $jsonBody = $decoded;
                    } else {
                        $options['body'] = $body;
                        $requestRawBody = $body;
                        if (!isset($options['headers'])) {
                            $options['headers'] = [];
                        }
                        $options['headers']['Content-Type'] = $options['headers']['Content-Type'] ?? 'application/json';
                    }
                } else {
                    $options['body'] = $body;
                    $requestRawBody = $body;
                }
            }
        }

        $response = $client->request($method, $url, $options);
        $responseBody = (string)$response->getBody();
        $decodedBody = json_decode($responseBody, true);
        $value = is_array($decodedBody) ? $decodedBody : $responseBody;

        return [
            'body' => $value,
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'method' => $method,
            'url' => $url,
            'timeout' => $timeout,
            'request_body_type' => $bodyType,
            'request_body_raw' => $requestRawBody,
            'request_body_json' => $jsonBody,
            'request_body_form' => $formParams,
            'request_headers' => $headers,
            'request_query' => $query,
        ];
    }
}
