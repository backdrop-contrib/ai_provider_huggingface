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
| Tool calling | Yes | Models the router catalog marks `supports_tools` on a live provider. |
| Thinking | Manual | The catalog has no reasoning flag; assign models on the Model capabilities page. |
| Embeddings | Yes | Router: the `hf-inference` feature-extraction pipeline. Dedicated endpoints: `/v1/embeddings`. |
| Vision | Yes | Models the router catalog lists with image input. |
| Image generation | No | |
| Moderation | No | |
| Speech-to-text | No | |

Nothing is hardcoded. Chat models and their tool/vision flags come from the
router catalog (`/v1/models`, which is public). Embedding models are the
most-downloaded `feature-extraction` models that `hf-inference` serves, from the
Hub API. Custom models are offered for chat and embeddings. Adjust any of this
on the Model capabilities page.

## Settings

The provider settings at `admin/config/ai/settings` add:

- **Base URL / Inference Endpoint** — defaults to the serverless router
  `https://router.huggingface.co/v1`. Set it to a Dedicated Endpoint URL
  (`https://xxxx.endpoints.huggingface.cloud/v1`) to use your own deployment.
- **Custom / Additional Models** — one model ID per line (or `alias=model_id`),
  added to the models discovered from the catalog.

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
