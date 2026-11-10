---
title: "Use Squire from your coding agent (ADVISOR)"
description: "Install the ADVISOR module so a coding agent can run Squire's checks from its sandbox with the two read-only tools squire_doctor and squire_drift."
---

# Use Squire from your coding agent (ADVISOR)

Squire ships an [ADVISOR](https://github.com/waynestate/advisor) module, so a coding agent that runs ADVISOR can run Squire's checks without leaving its sandbox. Install ADVISOR in the app, register the Squire module, and the agent gains two read-only tools. Next, see [Doctor](doctor.md) for what the checks cover and [Update and drift](update-and-drift.md) for what the drift report means.

## Install ADVISOR

In the app, install ADVISOR as a dev dependency:

<!-- docs-test: skip needs a real project -->
```bash
composer require --dev waynestate/advisor
```

Then put the module in a manifest of your own (for example `advisor-modules.json` in the app) and point
`ADVISOR_MODULES_FILE` at it. ADVISOR reads its own `modules.json` and that one extra file, nothing else:

```json
{
    "modules": [
        "Waynestate\\Squire\\Advisor\\SquireModule"
    ]
}
```

## The two read-only tools

Two tools appear. Both are read-only, and each takes exactly one argument: a path inside the project root.

### squire_doctor

`squire_doctor` runs Squire's deploy-setup checks (the S-rules, S1 to S78) against the app folder and returns the same JSON report `vendor/bin/squire doctor --format=json` prints. It runs in-process: no subprocess, no network, nothing written.

### squire_drift

`squire_drift` reports which of the files Squire wrote into the app have drifted from the current templates, the same check `vendor/bin/squire init --check` performs: missing, outdated, edited or untracked, per file. It writes nothing; `squire init` is the fix, and it never overwrites an edit.

> [!NOTE]
> Every answer labels its source and says plainly what ran. Squire reports; Squire in CI decides pass or fail. A squire can tell you where the armor is dented; only the knight gets to charge.
