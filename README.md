# AI Provider Hugging Face

Hugging Face provider for the Backdrop CMS AI module.

Adds open-source models hosted on Hugging Face (https://huggingface.co/) to the
providers the `ai` module can route to, through the OpenAI-compatible Inference
Router or a Dedicated Inference Endpoint.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported. JSON mode and JSON schema responses supported. |
| Completions | Yes | Sent as a single chat message; uses the chat endpoint. |
| Tool calling | Yes | Llama 3, Mistral and Qwen models. |
| Thinking | Yes | R1 / reasoning models. |
| Embeddings | Yes | Models whose ID contains `embed`, `bge` or `sentence`. |
| Vision | No | |
| Image generation | No | |
| Moderation | No | |
| Speech-to-text | No | |

Capabilities are inferred from the model ID, so the same rules apply to
models you add yourself.

## Settings

The provider settings at `admin/config/ai/settings` add:

- **Base URL / Inference Endpoint** — defaults to the serverless router
  `https://router.huggingface.co/v1`. Set it to a Dedicated Endpoint URL
  (`https://xxxx.endpoints.huggingface.cloud/v1`) to use your own deployment.
- **Custom / Additional Models** — one model ID per line (or `alias=model_id`),
  appended to the built-in list of popular chat and embedding models.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding a Hugging Face
  access token (https://huggingface.co/settings/tokens).
- Enable and configure the provider at `admin/config/ai/settings`.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_huggingface/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
