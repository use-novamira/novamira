---
# SPDX-FileCopyrightText: 2024 Anthropic, PBC
# SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
# SPDX-License-Identifier: Apache-2.0
# Adapted from Anthropic's skill-creator (Apache-2.0) for Novamira's flat
# single-document skill model. Original: https://github.com/anthropics/skills
name: skill-creator
description: Guidance for creating and refining Novamira skills — the single-document markdown skills stored in WordPress that turn the agent into a specialist for a recurring task. Use when the user asks to "create a skill", "make a skill", "add a skill for X", "refine this skill", or wants to extend the agent with reusable WordPress-specific procedural knowledge.
enable_prompt: true
enable_agentic: true
---

# Skill Creator

Guidance for creating effective Novamira skills.

## What a Novamira Skill Is

A Novamira skill is a single Markdown document — frontmatter plus body — stored in the WordPress database. When its `description` matches the user's request, the agent loads the body and gains specialized procedural knowledge for the task.

Unlike file-based skills in other systems, **Novamira skills are flat**: no bundled `scripts/`, `references/`, or `assets/` directories. Everything the agent needs must live in the single body. The body has a 1 MB hard limit but should usually stay well under 5,000 words.

### Anatomy

```
---
name: <slug>                  # Auto-derived from title server-side; informational here.
description: <one-line trigger blurb>  # The ONLY field the agent reads to decide if the skill fires.
enable_prompt: true|false     # Expose as an MCP prompt the user can invoke directly? (Default true.)
enable_agentic: true|false    # Include in the catalog the agent sees? (Default true.)
---

<Markdown body — instructions, examples, templates.>
```

## Core Principles

### Concise Is Key

The body loads into the agent's context window every time the skill fires. Context is a public good — every word competes with the user's request, conversation history, and other skills' metadata.

Claude is already a capable WordPress operator. Only add what it cannot infer from inspecting the site or running a quick probe. Challenge every paragraph: "Does Claude really need this to do the task well?" If the answer is "probably," cut it.

### Set Appropriate Degrees of Freedom

Match instruction specificity to task fragility:

- **High freedom** — multiple valid approaches, decisions depend on context. Use prose guidance.
- **Medium freedom** — a preferred pattern with acceptable variation. Use pseudocode or annotated examples.
- **Low freedom** — a fragile or destructive operation with one correct sequence. Use literal commands and explicit "do not deviate" wording.

### Description Is the Trigger

The `description` is the only thing the agent reads to decide whether to load a skill. Write it so a stranger could tell at a glance both *what the skill does* and *when to invoke it*. Include concrete trigger phrases the user is likely to say.

- Bad: `"Helps with posts."`
- Good: `"Bulk-rewrite WordPress post excerpts in the Acme brand voice. Use when the user asks to revise excerpts across many posts, fix on-brand voice, or generate excerpts where they're missing."`

## Creating a Skill

Use the Novamira MCP abilities — there is no filesystem step, no init script, no packaging.

| Goal                         | Ability                  |
| ---------------------------- | ------------------------ |
| Create or replace a skill    | `novamira/skill-write`   |
| Patch specific fields        | `novamira/skill-edit`    |
| Read an existing skill back  | `novamira/skill-get`     |
| Remove a skill               | `novamira/skill-delete`  |

### Workflow

1. **Understand the task by example.** Before writing anything, ask the user for 1–3 concrete examples of requests this skill should handle and what the agent should do. ("Show me a message that would trigger this skill.")
2. **Identify what belongs in the body.** Focus on procedural knowledge the agent cannot derive from inspecting the site: business rules, naming conventions, content style, schema quirks, preferred ordering of fragile operations, specific Novamira abilities to call. That — and only that — goes in the body.
3. **Write the skill.** Call `novamira/skill-write` with `title`, `description`, and `content`. Leave `on_conflict` at its default (`"fail"`) unless the user has confirmed replacement.
4. **Verify it round-trips.** Call `novamira/skill-get` with the returned slug and review the rendered SKILL.md.
5. **Iterate.** After the user tries the skill, ask what was unclear, wrong, or over-explained, then patch the changed fields with `novamira/skill-edit`.

### Title and Slug

The `title` doubles as the slug. The server lowercases it and replaces non-alphanumerics with dashes (`sanitize_title`). Pick a title that reads well as `skill-name` in the catalog.

If a slug is taken, set `on_conflict`:

- `"fail"` (default) — returns an error and a suggested free slug. Surface this to the user; do not silently rename or overwrite.
- `"rename"` — server appends `-2`, `-3`, etc.
- `"replace"` — overwrites the existing **user** skill. Cannot overwrite skills from built-in or other plugin sources.

### Activation Flags

- `enable_agentic` (default `true`) — skill appears in the agent's catalog and can be loaded by `skill-get`. Turn off only for work-in-progress drafts.
- `enable_prompt` (default `true`) — skill is exposed as an MCP prompt the user can invoke directly (e.g., a slash command in their client). Turn off when only the agent should auto-discover it.

A skill needs a non-empty `description` AND `content` to be discoverable in either mode — empty fields silently hide it from the catalog.

## What to Put in the Body

### Include

- Domain-specific knowledge the agent cannot derive from the site (business rules, naming conventions, content style, schema quirks, taxonomy structure).
- Step-by-step procedures for fragile or multi-step operations.
- Concrete examples of input → expected output.
- Templates the agent should reuse verbatim.
- Pointers to specific Novamira abilities to call for this task (e.g., "use `novamira/execute-php` to run …", "use `novamira/run-wp-cli` for the rewrite").

### Exclude

- Generic WordPress tutorials (`add_action`, `WP_Query`, hook order) — Claude knows these.
- Meta sections like "When to Use This Skill" or "About This Skill" — by the time the body loads, the skill has already triggered; the description did its job.
- Changelogs, author notes, installation instructions, links to internal docs the agent cannot fetch.
- Long preambles restating what the skill is "about".

### Structuring a Longer Body

If the body grows past a few hundred lines, use clear `##`/`###` headings so the agent can scan it quickly. There is no progressive disclosure across files — everything loads at once — so prefer trimming over splitting. If a skill genuinely needs to cover multiple distinct workflows, consider whether it should be two skills with sharper descriptions instead.

## Examples

### Tightly-scoped skill (low freedom)

```markdown
---
name: rebuild-search-index
description: Rebuild the Relevanssi search index after bulk post changes. Use when the user mentions Relevanssi being stale, search returning old results, or right after a large import.
---

# Rebuild Search Index

Run, in this order:

1. `novamira/run-wp-cli` with `wp relevanssi index --throttle`
2. After completion, `wp relevanssi stats`
3. Report the new "indexed documents" count.

Never call `wp relevanssi truncate` — it deletes the index. If the user asks to "reset" the index, confirm what they actually want before running anything destructive.
```

### Higher-freedom skill

```markdown
---
name: brand-voice-rewrite
description: Rewrite WordPress post content (or excerpts) in Acme Corp's brand voice. Use when the user asks to rewrite, polish, or revoice posts in our brand voice.
---

# Brand Voice Rewrite

Acme's brand voice:
- Sentences under 20 words.
- Active voice; no marketing fluff ("revolutionary", "world-class", "synergy").
- Address the reader as "you", not "we" or "our customers".
- One emoji per post maximum, in the lede only.

When rewriting:
- Preserve all factual content and proper nouns.
- Keep headings; rewrite the prose under them.
- For technical posts, keep code blocks unchanged.
```

## Iteration

After the user tries a skill, ask: "What did the agent do wrong, miss, or over-explain?" Then patch only the changed fields via `novamira/skill-edit`. If the same failure mode recurs, add a concrete counter-example to the body rather than just restating the rule.
