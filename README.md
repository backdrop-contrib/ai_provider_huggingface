# AI Provider Hugging Face

Connect Backdrop’s AI features to models hosted by Hugging Face. Use the shared Inference Router or a Dedicated Inference Endpoint that you manage. Use it for chat, text generation, and search embeddings. Some chat models can also analyze images or call tools.

## Requirements

Backdrop CMS 1.x, PHP 8.2 or later, and the AI module, plus the Key module.

## Installation

1. Install and enable this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
2. Open **AI settings** at `admin/config/ai/settings` and enable the provider. Select the service credential you saved with the Key module.
3. Leave **Base URL / Inference Endpoint** at the router default or enter the URL of your dedicated deployment, including `/v1`.

Read the [module wiki](https://github.com/backdrop-contrib/ai_provider_huggingface/wiki) for setup details, supported operations, and troubleshooting.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_huggingface/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
