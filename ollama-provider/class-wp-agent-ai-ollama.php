<?php
/**
 * Ollama AI Provider for WP Agent
 *
 * Drop-in fourth provider that routes requests to a self-hosted Ollama instance.
 * Ollama streams raw NDJSON (one JSON object per line, no SSE "data:" prefix), so
 * the base-class stream_request() is intentionally bypassed in favour of a custom
 * chat_stream() implementation below.
 *
 * @package WP_Private_AI
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Class WP_Agent_AI_Ollama
 *
 * Implements the WP_Agent_AI_Provider contract for self-hosted Ollama instances.
 *
 * Settings consumed (managed by WP Agent's encrypted settings store):
 *   ollama_endpoint_url  – Full chat endpoint, e.g. https://ollama.example.com/api/chat
 *   ollama_api_key       – Bearer token (stored AES-256-CBC encrypted by WP Agent)
 *   ollama_model         – Model tag, e.g. llama3.1:8b  (default: llama3.1:8b)
 *   ollama_site_id       – Forwarded as X-Site-Id for nginx access-log correlation
 */
class WP_Agent_AI_Ollama extends WP_Agent_AI_Provider {

	// ------------------------------------------------------------------
	// Identity
	// ------------------------------------------------------------------

	/**
	 * Unique provider identifier used by the AI router.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'ollama';
	}

	/**
	 * Human-readable provider name shown in the admin UI.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Ollama (Self-hosted)';
	}

	// ------------------------------------------------------------------
	// Configuration
	// ------------------------------------------------------------------

	/**
	 * Returns true only when both the endpoint URL and API key are non-empty.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		$endpoint = $this->settings->get('ollama_endpoint_url');
		$api_key  = $this->settings->get('ollama_api_key');

		return ! empty($endpoint) && ! empty($api_key);
	}

	/**
	 * Active model tag, falling back to llama3.1:8b when unset.
	 *
	 * @return string
	 */
	public function get_model(): string {
		$model = $this->settings->get('ollama_model');

		return ! empty($model) ? $model : 'llama3.1:8b';
	}

	// ------------------------------------------------------------------
	// Cost estimation
	// ------------------------------------------------------------------

	/**
	 * Ollama is self-hosted — per-token cost is always zero.
	 *
	 * @param int $input  Number of prompt/input tokens.
	 * @param int $output Number of completion/output tokens.
	 * @return float
	 */
	public function estimate_cost(int $input, int $output): float {
		return 0.0;
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Build the HTTP headers required for every Ollama request.
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		$headers = [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->settings->get('ollama_api_key'),
		];

		$site_id = $this->settings->get('ollama_site_id');
		if ( ! empty($site_id) ) {
			$headers['X-Site-Id'] = $site_id;
		}

		return $headers;
	}

	/**
	 * Normalize Ollama's tool_calls array to the canonical WP Agent format.
	 *
	 * Ollama format:
	 *   [ { "function": { "name": "...", "arguments": { ... } | "..." } } ]
	 *
	 * Canonical format:
	 *   [ { "id": "<uniqid>", "name": "...", "arguments": array } ]
	 *
	 * @param array $raw_tool_calls Raw tool_calls from Ollama.
	 * @return array Normalized tool calls.
	 */
	private function normalize_tool_calls(array $raw_tool_calls): array {
		$normalized = [];

		foreach ($raw_tool_calls as $call) {
			if ( empty($call['function']['name']) ) {
				continue;
			}

			$arguments = $call['function']['arguments'] ?? [];

			// Arguments may arrive as a JSON string — decode when necessary.
			if ( is_string($arguments) ) {
				$decoded = json_decode($arguments, true);
				$arguments = is_array($decoded) ? $decoded : [];
			}

			$normalized[] = [
				'id'        => uniqid('ollama_tool_', true),
				'name'      => $call['function']['name'],
				'arguments' => $arguments,
			];
		}

		return $normalized;
	}

	// ------------------------------------------------------------------
	// Non-streaming chat
	// ------------------------------------------------------------------

	/**
	 * Send a blocking (non-streaming) chat request to Ollama.
	 *
	 * @param array $messages Conversation history in OpenAI message format.
	 * @param array $tools    Tool definitions (optional).
	 * @param array $options  Runtime options; supports 'max_tokens' (int).
	 * @return array|WP_Error Normalised response array or WP_Error on failure.
	 */
	public function chat(array $messages, array $tools = [], array $options = []): array|WP_Error {
		$endpoint = $this->settings->get('ollama_endpoint_url');

		if ( empty($endpoint) ) {
			return new WP_Error('ollama_not_configured', 'Ollama endpoint URL is not configured.');
		}

		$body = [
			'model'    => $this->get_model(),
			'messages' => $messages,
			'stream'   => false,
			'options'  => [
				'num_predict' => isset($options['max_tokens']) ? (int) $options['max_tokens'] : 2048,
			],
		];

		if ( ! empty($tools) ) {
			$body['tools'] = $tools;
		}

		$response = wp_remote_post(
			$endpoint,
			[
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode($body),
				'timeout' => 120,
			]
		);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$raw_body    = wp_remote_retrieve_body($response);
		$data        = json_decode($raw_body, true);

		if ( $status_code !== 200 || ! is_array($data) ) {
			return new WP_Error(
				'ollama_api_error',
				sprintf(
					'Ollama returned HTTP %d: %s',
					$status_code,
					wp_strip_all_tags($raw_body)
				)
			);
		}

		// Extract content and tool calls from the response message.
		$message    = $data['message'] ?? [];
		$content    = $message['content'] ?? '';
		$tool_calls = isset($message['tool_calls']) && is_array($message['tool_calls'])
			? $this->normalize_tool_calls($message['tool_calls'])
			: [];

		// Token usage — Ollama uses different field names.
		$input_tokens  = (int) ($data['prompt_eval_count'] ?? 0);
		$output_tokens = (int) ($data['eval_count'] ?? 0);

		return [
			'content'       => $content,
			'tool_calls'    => $tool_calls,
			'finish_reason' => $data['done_reason'] ?? 'stop',
			'usage'         => [
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'total_tokens'  => $input_tokens + $output_tokens,
				'cost'          => $this->estimate_cost($input_tokens, $output_tokens),
			],
			'model'         => $data['model'] ?? $this->get_model(),
			'provider'      => $this->get_id(),
		];
	}

	// ------------------------------------------------------------------
	// Streaming chat (NDJSON)
	// ------------------------------------------------------------------

	/**
	 * Send a streaming chat request to Ollama and process NDJSON chunks.
	 *
	 * Ollama streams one JSON object per newline with NO "data:" SSE prefix.
	 * The base-class stream_request() expects SSE and must NOT be called here.
	 *
	 * Event types emitted via $on_event:
	 *   'content_delta'  – { 'content': string }   partial text token
	 *   'tool_call_start'– { 'name': string }       a tool invocation was requested
	 *
	 * @param array    $messages  Conversation history in OpenAI message format.
	 * @param array    $tools     Tool definitions (optional).
	 * @param callable $on_event  Callback: fn(string $type, array $payload): void
	 * @param array    $options   Runtime options; supports 'max_tokens' (int).
	 * @return array|WP_Error Final usage/metadata array or WP_Error on failure.
	 */
	public function chat_stream(
		array $messages,
		array $tools,
		callable $on_event,
		array $options = []
	): array|WP_Error {
		$endpoint = $this->settings->get('ollama_endpoint_url');

		if ( empty($endpoint) ) {
			return new WP_Error('ollama_not_configured', 'Ollama endpoint URL is not configured.');
		}

		$body = [
			'model'    => $this->get_model(),
			'messages' => $messages,
			'stream'   => true,
			'options'  => [
				'num_predict' => isset($options['max_tokens']) ? (int) $options['max_tokens'] : 2048,
			],
		];

		if ( ! empty($tools) ) {
			$body['tools'] = $tools;
		}

		// wp_remote_post with stream=>true buffers the full body but allows
		// line-by-line iteration without a dedicated HTTP streaming client.
		$response = wp_remote_post(
			$endpoint,
			[
				'headers'  => $this->build_headers(),
				'body'     => wp_json_encode($body),
				'timeout'  => 120,
			]
		);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);

		if ( $status_code !== 200 ) {
			return new WP_Error(
				'ollama_api_error',
				sprintf(
					'Ollama returned HTTP %d during streaming.',
					$status_code
				)
			);
		}

		$raw_body = wp_remote_retrieve_body($response);

		// Accumulators for usage data surfaced on the final "done" line.
		$input_tokens  = 0;
		$output_tokens = 0;
		$finish_reason = 'stop';
		$all_tool_calls = [];

		// Each NDJSON line is a complete, self-contained JSON object.
		foreach (explode("\n", $raw_body) as $line) {
			$line = trim($line);

			if ( $line === '' ) {
				continue;
			}

			$data = json_decode($line, true);

			if ( ! is_array($data) ) {
				continue;
			}

			$message = $data['message'] ?? [];

			// --- Partial content delta ---
			if ( isset($message['content']) && $message['content'] !== '' ) {
				$on_event('content_delta', ['content' => (string) $message['content']]);
			}

			// --- Tool call notification ---
			if ( ! empty($message['tool_calls']) && is_array($message['tool_calls']) ) {
				$normalized = $this->normalize_tool_calls($message['tool_calls']);
				foreach ($normalized as $tool_call) {
					$on_event('tool_call_start', ['name' => $tool_call['name']]);
				}
				$all_tool_calls = array_merge($all_tool_calls, $normalized);
			}

			// --- Final "done" object carries usage statistics ---
			if ( isset($data['done']) && $data['done'] === true ) {
				$input_tokens  = (int) ($data['prompt_eval_count'] ?? 0);
				$output_tokens = (int) ($data['eval_count'] ?? 0);
				$finish_reason = $data['done_reason'] ?? 'stop';
			}
		}

		return [
			'content'       => '',   // Content was emitted incrementally via $on_event.
			'tool_calls'    => $all_tool_calls,
			'finish_reason' => $finish_reason,
			'usage'         => [
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'total_tokens'  => $input_tokens + $output_tokens,
				'cost'          => $this->estimate_cost($input_tokens, $output_tokens),
			],
			'model'         => $this->get_model(),
			'provider'      => $this->get_id(),
		];
	}
}
