<?php

namespace App\Services;

use GuzzleHttp\Client;

// cliente de chat completions con tools, sirve para Mistral y OpenAI
class LlmClient
{
    public function chat(array $messages, array $tools = [], array $opciones = []): array
    {
        $payload = [
            'model' => $_ENV['LLM_MODEL'],
            'messages' => $messages,
            'temperature' => 0.1,
        ] + $opciones;

        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $res = (new Client(['http_errors' => false, 'timeout' => 60]))->post(rtrim($_ENV['LLM_BASE_URL'], '/') . '/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $_ENV['LLM_API_KEY']],
            'json' => $payload,
        ]);

        $data = json_decode((string) $res->getBody(), true);

        if ($res->getStatusCode() >= 400 || empty($data['choices'][0]['message'])) {
            throw new \RuntimeException('LLM ' . $res->getStatusCode() . ': ' . substr((string) $res->getBody(), 0, 300));
        }

        return $data['choices'][0]['message'];
    }
}
