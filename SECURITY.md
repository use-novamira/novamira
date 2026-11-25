# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately, not in public issues: write to [security@novamira.ai](mailto:security@novamira.ai). Tell us the version of Novamira, what you found and how to reproduce it. We will reply as soon as we can.

## What Novamira is

Novamira gives an AI agent the same access to WordPress as the administrator who connects it: it runs PHP, reads and writes files and queries the database. What an administrator can do, their AI can do too. That is by design, and it is not a vulnerability.

Running PHP and WP-CLI commands, and reading, writing, editing and deleting files, are intentional abilities. The file abilities have some limits, such as staying inside the WordPress folder, and the sandbox keeps the PHP files the AI adds in one place: these are conveniences, not security boundaries. Running PHP has no such limits, by design. So a report that one of these abilities can read, change or delete something on the server, or do what another ability cannot, is not a vulnerability.

## Approvals are not a security boundary

Approvals are designed to catch the mistakes of an AI working for you. They are not designed to stop an AI, or a person, that is trying to get around them.

So these are not vulnerabilities: any way the connected AI, or someone with its credentials, avoids an approval, for example by rating an action low, writing code the check does not recognise, reaching the same result another way, or approving a request itself.

The AI change history, held emails and the hiding of secrets in what your AI reads are aids in the same way: they cover what passes through WordPress, and they are not designed to stop code written to get around them.

Reports that a model approved its own request, ignored Novamira's instructions or rated an action too low describe the model's behaviour: report them to the provider of the model or AI client. We are glad to hear about them as feedback, to improve the instructions Novamira gives, but they are not vulnerabilities.

## What we treat as a vulnerability

Issues that reach people other than the administrator and their AI, such as:

- using Novamira or its abilities without being an administrator, or without signing in;
- data exposed to people who are not signed in;
- a web page or link that makes a signed-in administrator's browser perform a Novamira action without their knowledge (such as CSRF).
