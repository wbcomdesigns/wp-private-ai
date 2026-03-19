# WP Private AI

> Private AI for any WordPress site — zero cloud, zero data leaks, any plugin.

Built on **WordPress Abilities API (WP 6.9+ core)** + **Ollama** (self-hosted LLM).

**Blog post:** [How I Built a Private AI Assistant for WordPress That Never Sends Data to OpenAI](https://vapvarun.com/wordpress-private-ai-self-hosted-ollama/) — full write-up of how this was built, the hallucination problem and fix, multi-site deployment, and what comes next.

---

## What This Is

A proof of concept showing that any WordPress plugin — free or paid — can have AI abilities added to it **without modifying the plugin**. The AI runs entirely on your own server. No OpenAI. No Anthropic. No data leaving your infrastructure.

**GDPR compliant by architecture, not by policy.**

---

## How It Works

```
[Any WordPress Plugin]
        ↓
  WP Abilities API      ← scanned + generated, no plugin changes needed
        ↓
    WP Agent            ← AI orchestration layer
        ↓
      Ollama            ← self-hosted LLM (llama3.1:8b or similar)
        ↓
  Your Server Only      ← data never leaves
```

---

## Key Concepts

| Concept | What It Means |
|---|---|
| **WP Abilities API** | WordPress core (6.9+) standard for registering AI capabilities |
| **Any plugin, zero changes** | Ability adapters live outside the plugin — scan, generate, deploy |
| **Site-specific container** | One Ollama instance per site — no cross-site data leakage |
| **Point of truth** | AI only answers from the site's own data — no hallucination from general web |
| **Access control** | Per-role restrictions on who can ask and what topics are allowed |

---

## Proof of Concept

→ See [`/poc`](./poc/) — scanning a real plugin, generating ability adapters, connecting to Ollama

---

## For Hosting Companies

Bundle as a tier upgrade. One Ollama server, per-site auth tokens via nginx, rate limiting per site. Each customer gets isolated AI that knows only their site's data.

→ See [Wiki: Hosting Company Setup](../../wiki/Hosting-Company-Setup)

---

## For Agencies

Offer private AI as a recurring service for any client site — works with whatever plugins they already use. White-label ready.

→ See [Wiki: Agency Deployment](../../wiki/Agency-Deployment)

---

## For Individual Site Owners

Set up in an afternoon. One VPS. Open source. No monthly AI bill after the server cost.

→ See [Wiki: Self-Hosted Setup](../../wiki/Self-Hosted-Setup)

---

## Status

- [ ] Plugin scanner proof of concept
- [ ] Ollama provider for WP Agent
- [ ] nginx gateway config
- [ ] Wiki documentation
- [ ] Blog post: "We added AI to any WordPress plugin in minutes"

---

## License

GPL v2 or later — same as WordPress core.
