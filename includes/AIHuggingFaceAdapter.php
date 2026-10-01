<?php

/**
 * @file
 * Hugging Face adapter for AI core.
 */

class AIHuggingFaceAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /** @var string */
  protected $baseUrl = 'https://router.huggingface.co/v1';

  /** @var array */
  protected $customModels = [];

  /** @var array|null */
  protected $models = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);

    $config = config('ai_provider_huggingface.settings');
    $base_url = trim((string) $config->get('base_url'));
    if ($base_url !== '') {
      $this->baseUrl = rtrim($base_url, '/');
    }

    $custom_text = trim((string) $config->get('custom_models'));
    if ($custom_text !== '') {
      $lines = preg_split('/[\r\n]+/', $custom_text);
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        if (strpos($line, '=') !== FALSE) {
          [$alias, $model_id] = explode('=', $line, 2);
          $this->customModels[trim($alias)] = trim($model_id);
        }
        else {
          $this->customModels[$line] = $line;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->apiKey,
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    if ($this->models !== NULL) {
      return $this->models;
    }

    $models = [
      'meta-llama/Llama-3.3-70B-Instruct' => 'Meta Llama 3.3 70B Instruct',
      'meta-llama/Llama-3.1-8B-Instruct' => 'Meta Llama 3.1 8B Instruct',
      'mistralai/Mistral-7B-Instruct-v0.3' => 'Mistral 7B Instruct v0.3',
      'Qwen/Qwen2.5-72B-Instruct' => 'Qwen 2.5 72B Instruct',
      'deepseek-ai/DeepSeek-R1' => 'DeepSeek R1 (via Hugging Face)',
      'microsoft/Phi-3.5-mini-instruct' => 'Microsoft Phi-3.5 Mini',
      'BAAI/bge-large-en-v1.5' => 'BAAI BGE Large EN v1.5 (Embeddings)',
      'sentence-transformers/all-MiniLM-L6-v2' => 'Sentence Transformers MiniLM-L6-v2 (Embeddings)',
      'mixedbread-ai/mxbai-embed-large' => 'MixedBread AI Embed Large (Embeddings)',
    ];

    if (!empty($this->customModels)) {
      foreach ($this->customModels as $alias => $id) {
        $models[$id] = ($alias !== $id) ? ($alias . ' (' . $id . ')') : $id;
      }
    }

    asort($models);
    return $this->models = $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelsByCapability($capability): array {
    $models = $this->getModels();
    $capability = ai_normalize_capability_name($capability);
    $filtered = [];

    foreach ($models as $id => $label) {
      $ok = FALSE;
      switch ($capability) {
        case 'text':
        case 'chat':
          $ok = !preg_match('/embed|bge|sentence/i', $id);
          break;

        case 'thinking':
          $ok = (bool) preg_match('/r1|reason/i', $id);
          break;

        case 'tool_calling':
          $ok = (bool) preg_match('/llama-3|mistral|qwen/i', $id);
          break;

        case 'embeddings':
        case 'embedding':
          $ok = (bool) preg_match('/embed|bge|sentence/i', $id);
          break;

        case 'vision':
        case 'image':
        case 'moderation':
        case 'stt':
          $ok = FALSE;
          break;
      }

      if ($ok) {
        $filtered[$id] = $label;
      }
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $messages = [
      ['role' => 'user', 'content' => $prompt],
    ];
    return $this->chat($model, $messages, $temperature, $max_tokens, $stream_response);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    $payload = [
      'model' => $model,
      'messages' => $messages,
      'temperature' => (float) $temperature,
    ];
    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }

    if (!empty($context_extra['response_format'])) {
      $payload['response_format'] = $context_extra['response_format'];
    }
    elseif (!empty($context_extra['json_schema'])) {
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => $context_extra['json_schema_name'] ?? 'response',
          'strict' => TRUE,
          'schema' => $context_extra['json_schema'],
        ],
      ];
    }
    elseif (!empty($context_extra['json_mode'])) {
      $payload['response_format'] = ['type' => 'json_object'];
    }

    $url = $this->baseUrl . '/chat/completions';

    try {
      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse($url, [
          'method' => 'POST',
          'headers' => array_merge(['Accept' => 'text/event-stream'], $this->getDefaultHeaders()),
          'data' => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          return $data['choices'][0]['delta']['content'] ?? '';
        });
      }

      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      return trim($result['choices'][0]['message']['content'] ?? '');
    }
    catch (\Exception $e) {
      watchdog('ai_provider_huggingface', 'Hugging Face chat error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    $payload = [
      'model' => $model,
      'messages' => $messages,
      'tools' => $tools,
      'tool_choice' => $tool_choice,
      'temperature' => (float) $temperature,
    ];
    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }

    $url = $this->baseUrl . '/chat/completions';

    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      return $this->normalizeToolResponse($result);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_huggingface', 'Hugging Face chatWithTools error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    $url = $this->baseUrl . '/embeddings';
    $payload = [
      'model' => $model,
      'input' => $input,
    ];

    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 60);
      return $result['data'][0]['embedding'] ?? [];
    }
    catch (\Exception $e) {
      watchdog('ai_provider_huggingface', 'Hugging Face embedding error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    watchdog('ai_provider_huggingface', 'Image generation is not supported by Hugging Face.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Image generation is not supported by Hugging Face.');
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    watchdog('ai_provider_huggingface', 'Text-to-speech is not supported by Hugging Face.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Text-to-speech is not supported by Hugging Face.');
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    watchdog('ai_provider_huggingface', 'Speech-to-text is not supported by Hugging Face.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Speech-to-text is not supported by Hugging Face.');
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    watchdog('ai_provider_huggingface', 'Moderation is not supported by Hugging Face.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Moderation is not supported by Hugging Face.');
  }

}
