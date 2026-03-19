# Privacy & GDPR

## The Problem Today

Every time a member sends a message to your WordPress site's AI assistant using a cloud-hosted provider, the following happens:

1. The user's message is sent over HTTPS to a server operated by a US company (OpenAI, Anthropic, Google)
2. That company's model processes the message along with your system prompt — which may include the user's name, email, role, and activity data
3. The response is returned and the conversation may be logged for safety monitoring, fine-tuning, or abuse detection

Under GDPR Article 28, any company that processes personal data on your behalf is a **data processor**. You are the data controller. This relationship requires:

- A signed **Data Processing Agreement (DPA)** between you and the AI provider
- Documented legal basis for the transfer (the provider's servers are in the US — this is a cross-border transfer)
- Inclusion of the AI provider in your privacy policy
- User consent or legitimate interest analysis for each category of data processed

Most WordPress site owners skip all of this. This is not a theoretical risk — EU data protection authorities have issued substantial fines for exactly this pattern.

---

## The Architecture-Level Fix

WP Private AI eliminates this problem at the infrastructure level, not the policy level.

```
Traditional:  WordPress → user data → Cloud AI (US server) → response
WP Private AI: WordPress → user data → Ollama (your server) → response
```

When inference runs on your own server, no personal data ever leaves your infrastructure. There is no data processor relationship. No DPA is needed. No cross-border transfer occurs.

---

## GDPR Articles This Architecture Addresses

### Article 28 — Data Processor
**Traditional cloud AI:** Requires a DPA with every AI provider you use.
**WP Private AI:** No third-party processor. Ollama runs on your server. No DPA required.

### Article 44–49 — Cross-Border Transfers
**Traditional cloud AI:** User data is transferred to US-based servers. Requires Standard Contractual Clauses or adequacy decision reliance.
**WP Private AI:** No transfer. Data stays within your server's jurisdiction.

### Article 17 — Right to Erasure
**Traditional cloud AI:** Providers may retain conversation logs. Erasure requests require coordination with the provider.
**WP Private AI:** All conversation data is in your WordPress database (`wp_agent_conversations`, `wp_agent_messages`). Erasure is a single `DELETE` query. WP Agent includes a built-in GDPR erasure tool under WordPress's personal data tools.

### Article 25 — Data Protection by Design
**Traditional cloud AI:** Privacy controls are additive — you configure the provider's settings after the fact.
**WP Private AI:** Privacy is structural. The model never sees data it wasn't given. No telemetry. No logging to external services.

---

## What "Data Never Leaves Your Server" Means Technically

When a user sends a message through the WP Agent chat widget:

1. The browser sends the message to your WordPress REST API endpoint (`/wp-json/wp-agent/v1/chat/stream`) over HTTPS
2. PHP builds a system prompt containing: site name, user's name, role, and plugin-specific context (from WP Abilities API adapters)
3. The assembled prompt + message is sent via `wp_remote_post()` (or `curl`) to `http://127.0.0.1:11434/api/chat` — a loopback address on the same machine
4. Ollama processes the request entirely in memory, using model weights stored in `/root/.ollama/models`
5. The response streams back through the same loopback connection to PHP, then via SSE to the browser

At no point does any data leave the server's network interface. Step 3 is a loopback call — it never touches an external network.

---

## Per-Site Container Isolation

When using the Docker Compose deployment model (one container per WordPress site), each site has:

- Its own Ollama container with its own model copy in memory
- Its own conversation history in its own WordPress database
- Its own Bearer token for the nginx gateway

This means:
- A conversation on Site A cannot reach data from Site B, even if both run on the same physical server
- A compromised site's token grants access only to that site's Ollama endpoint
- Conversation histories are stored in each site's own database, not in a shared table

---

## Comparison: Cloud AI vs. Self-Hosted

| Concern | Cloud AI (OpenAI/Anthropic/Google) | WP Private AI (Ollama) |
|---|---|---|
| Data processor relationship | Yes — requires DPA | None |
| Cross-border transfer | Yes — US servers | No — your server |
| Right to erasure | Requires provider coordination | Single DB query |
| Conversation logging | Provider may log | Your DB only |
| Privacy policy update | Required | No change needed |
| GDPR Article 28 compliance | DPA required | Not applicable |
| Data residency | Provider's data center | Your hosting |
| Zero-knowledge by default | No | Yes |

---

## What This Does NOT Cover

Self-hosting Ollama makes the AI inference private. It does not replace your general GDPR obligations:

- You still need a privacy policy covering how you use the WP Agent conversation data stored in your own database
- If you enable GDPR data export/erasure (built into WP Agent), you should document this in your privacy policy
- System prompts that include user data are subject to your existing data minimisation obligations — only inject what the AI needs to answer the question

---

## Practical Checklist

- [ ] Ollama runs on `127.0.0.1` (loopback) — not exposed to the internet
- [ ] nginx gateway uses HTTPS with a valid TLS certificate (Certbot)
- [ ] Bearer tokens are generated with `openssl rand -hex 32` (not guessable)
- [ ] WP Agent GDPR export/erasure tools are enabled (Settings → Advanced)
- [ ] Your privacy policy mentions the AI assistant but does not need to name a third-party processor
- [ ] No `api.openai.com`, `api.anthropic.com`, or `generativelanguage.googleapis.com` calls in browser network tab
