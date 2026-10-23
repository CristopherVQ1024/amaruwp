<?php

namespace App\Services;

use GuzzleHttp\Client;

// cliente de chat con tools, Mistral usa chat completions y OpenAI la API Responses
class LlmClient
{
    private const PROVEEDORES = [
        'mistral' => ['url' => 'https://api.mistral.ai/v1', 'modelo' => 'ministral-8b-latest'],
        'openai' => ['url' => 'https://api.openai.com/v1', 'modelo' => 'gpt-6-luna'],
    ];

    private string $proveedor;
    private array $conf;
    private string $prefijo;

    public function __construct()
    {
        $this->proveedor = isset(self::PROVEEDORES[strtolower($_ENV['LLM_PROVIDER'] ?? '')]) ? strtolower($_ENV['LLM_PROVIDER']) : 'mistral';
        $this->conf = self::PROVEEDORES[$this->proveedor];
        $this->prefijo = strtoupper($this->proveedor);
    }

    // devuelve el mensaje en formato chat completions con content y tool_calls
    public function chat(array $messages, array $tools = [], array $opciones = []): array
    {
        return $this->proveedor === 'openai'
            ? $this->responses($messages, $tools, $opciones)
            : $this->completions($messages, $tools, $opciones);
    }

    private function completions(array $messages, array $tools, array $opciones): array
    {
        unset($opciones['razonamiento']);
        $payload = ['model' => $this->modelo(), 'messages' => $messages, 'temperature' => 0.1] + $opciones;

        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $data = $this->post('/chat/completions', $payload);

        if (empty($data['choices'][0]['message'])) {
            throw new \RuntimeException('LLM sin respuesta: ' . substr(json_encode($data), 0, 300));
        }

        return $data['choices'][0]['message'];
    }

    private function responses(array $messages, array $tools, array $opciones): array
    {
        $instrucciones = [];
        $input = [];

        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $instrucciones[] = $m['content'];
            } elseif ($m['role'] === 'tool') {
                $input[] = ['type' => 'function_call_output', 'call_id' => $m['tool_call_id'], 'output' => $m['content']];
            } else {
                if (($m['content'] ?? '') !== '') {
                    $input[] = ['role' => $m['role'], 'content' => $m['content']];
                }

                foreach ($m['tool_calls'] ?? [] as $c) {
                    $input[] = ['type' => 'function_call', 'call_id' => $c['id'], 'name' => $c['function']['name'], 'arguments' => $c['function']['arguments']];
                }
            }
        }

        $payload = [
            'model' => $this->modelo(),
            'instructions' => implode("\n\n", $instrucciones),
            'input' => $input,
            'store' => false,
            'reasoning' => ['effort' => $opciones['razonamiento'] ?? 'low'],
        ];

        // el limite de salida incluye los tokens de razonamiento
        $payload['max_output_tokens'] = max(200, (int) ($opciones['max_tokens'] ?? 400)) + ($payload['reasoning']['effort'] === 'none' ? 0 : 2000);

        if (($opciones['response_format']['type'] ?? '') === 'json_object') {
            $payload['text'] = ['format' => ['type' => 'json_object']];
        }

        if ($tools) {
            $payload['tools'] = array_map(fn ($t) => ['type' => 'function'] + $t['function'], $tools);
            $payload['tool_choice'] = 'auto';
        }

        $data = $this->post('/responses', $payload);
        $texto = '';
        $llamadas = [];

        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'message') {
                foreach ($item['content'] ?? [] as $c) {
                    $texto .= ($c['type'] ?? '') === 'output_text' ? $c['text'] : '';
                }
            } elseif (($item['type'] ?? '') === 'function_call') {
                $llamadas[] = ['id' => $item['call_id'], 'type' => 'function', 'function' => ['name' => $item['name'], 'arguments' => $item['arguments']]];
            }
        }

        return ['role' => 'assistant', 'content' => $texto, 'tool_calls' => $llamadas];
    }

    private function modelo(): string
    {
        return ($_ENV[$this->prefijo . '_MODEL'] ?? '') ?: $this->conf['modelo'];
    }

    private function post(string $ruta, array $payload): array
    {
        $res = (new Client(['http_errors' => false, 'timeout' => 60]))->post($this->conf['url'] . $ruta, [
            'headers' => ['Authorization' => 'Bearer ' . ($_ENV[$this->prefijo . '_API_KEY'] ?? '')],
            'json' => $payload,
        ]);

        if ($res->getStatusCode() >= 400) {
            throw new \RuntimeException('LLM ' . $res->getStatusCode() . ': ' . substr((string) $res->getBody(), 0, 300));
        }

        return json_decode((string) $res->getBody(), true) ?: [];
    }
}
