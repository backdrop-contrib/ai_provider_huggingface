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
   * Capability flags per model ID, from the catalog or the custom list.
   *
   * @var array
   */
  protected $modelInfo = [];

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
   * Whether requests go to the shared Inference Providers router.
   *
   * The router serves chat through /v1 but has no /v1/embeddings route;
   * embeddings use its hf-inference feature-extraction pipeline instead.
   * Dedicated Inference Endpoints (TGI/TEI) serve both under their own /v1.
   */
  protected function usesRouter(): bool {
    return parse_url($this->baseUrl, PHP_URL_HOST) === 'router.huggingface.co';
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    if ($this->models !== NULL) {
      return $this->models;
    }

    // No built-in list: the router catalog changes as providers add and drop
    // models, so models come from the live catalog plus the custom list.
    $models = [];
    try {
      $result = $this->makeRequest($this->baseUrl . '/models', [], [], 'GET', 15);
      foreach ($result['data'] ?? [] as $model) {
        $id = $model['id'] ?? NULL;
        if (empty($id)) {
          continue;
        }
        $models[$id] = $id;
        $supports_tools = FALSE;
        foreach ($model['providers'] ?? [] as $provider) {
          if (!empty($provider['supports_tools']) && ($provider['status'] ?? 'live') === 'live') {
            $supports_tools = TRUE;
            break;
          }
        }
        $this->modelInfo[$id] = [
          'text' => TRUE,
          'tool_calling' => $supports_tools,
          'vision' => in_array('image', $model['architecture']['input_modalities'] ?? [], TRUE),
        ];
      }
    }
    catch (\Exception $e) {
      watchdog('ai_provider_huggingface', 'Failed to fetch Hugging Face models: @message', ['@message' => $e->getMessage()], WATCHDOG_WARNING);
    }

    if ($this->usesRouter()) {
      // The router catalog lists chat models only. Embedding models are the
      // most-downloaded feature-extraction models hf-inference serves.
      try {
        $query = http_build_query([
          'inference_provider' => 'hf-inference',
          'pipeline_tag' => 'feature-extraction',
          'sort' => 'downloads',
          'limit' => 50,
        ]);
        $result = $this->makeRequest('https://huggingface.co/api/models?' . $query, [], [], 'GET', 15);
        foreach ($result as $model) {
          $id = is_array($model) ? ($model['id'] ?? NULL) : NULL;
          if (!empty($id) && !isset($models[$id])) {
            $models[$id] = $id;
            $this->modelInfo[$id] = ['embeddings' => TRUE];
          }
        }
      }
      catch (\Exception $e) {
        watchdog('ai_provider_huggingface', 'Failed to fetch Hugging Face embedding models: @message', ['@message' => $e->getMessage()], WATCHDOG_WARNING);
      }
    }

    // Custom models carry no metadata; offer them for chat and embeddings and
    // let the Model capabilities page refine that.
    foreach ($this->customModels as $alias => $id) {
      $models[$id] = ($alias !== $id) ? ($alias . ' (' . $id . ')') : $id;
      $this->modelInfo[$id] = ($this->modelInfo[$id] ?? []) + ['text' => TRUE, 'embeddings' => TRUE];
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
      if (!empty($this->modelInfo[$id][$capability])) {
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
    if ($this->usesRouter()) {
      $url = 'https://router.huggingface.co/hf-inference/models/' . str_replace('%2F', '/', rawurlencode($model)) . '/pipeline/feature-extraction';
      $payload = ['inputs' => $input];
    }
    else {
      $url = $this->baseUrl . '/embeddings';
      $payload = ['model' => $model, 'input' => $input];
    }

    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 60);
      if (isset($result['data'][0]['embedding'])) {
        return $result['data'][0]['embedding'];
      }
      // feature-extraction returns the bare vector, or [[vector]] for some
      // models.
      if (isset($result[0]) && is_array($result[0])) {
        $result = $result[0];
      }
      if (isset($result[0]) && is_numeric($result[0])) {
        return array_map('floatval', $result);
      }
      throw new \RuntimeException('Hugging Face returned no embedding vector for ' . $model . '.');
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
