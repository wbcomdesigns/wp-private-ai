# Proof of Concept: Scanning Any Plugin

This directory demonstrates scanning a WordPress plugin's code, identifying its REST endpoints and hooks, and auto-generating WP Abilities API adapter code — without touching the original plugin.

## Steps

1. [Scan a plugin](./01-scan-plugin.md) — read endpoints, hooks, data models
2. [Generate ability adapters](./02-generate-abilities.md) — output ready-to-use ability registration code
3. [Connect to WP Agent](./03-connect-wp-agent.md) — drop the adapter into any site
4. [Test with Ollama](./04-test-ollama.md) — private inference, no cloud

## Target Plugin for Demo

> TBD — pick a popular free plugin (e.g. GravityForms, WP Forms, Fluent CRM)
