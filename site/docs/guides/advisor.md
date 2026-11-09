---
title: "Use Squire from your coding agent (ADVISOR) | Squire docs"
description: "Install the ADVISOR module so a coding agent can run Squire's checks from its sandbox with the two read-only tools squire_doctor and squire_drift."
url: https://squire.apps.wayne.edu/docs/guides/advisor.html
llms: https://squire.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Use Squire from your coding agent (ADVISOR) [#](https://squire.apps.wayne.edu/docs/guides/advisor.html#use-squire-from-your-coding-agent-advisor)

Squire ships an [ADVISOR](https://github.com/waynestate/advisor) module, so a coding agent that runs ADVISOR can run Squire's checks without leaving its sandbox. Install ADVISOR in the app, register the Squire module, and the agent gains two read-only tools. Next, see [Doctor](https://squire.apps.wayne.edu/docs/guides/doctor.html) for what the checks cover and [Update and drift](https://squire.apps.wayne.edu/docs/guides/update-and-drift.html) for what the drift report means.

## Install ADVISOR [#](https://squire.apps.wayne.edu/docs/guides/advisor.html#install-advisor)

In the app, install ADVISOR as a dev dependency:

```bash
composer require --dev waynestate/advisor
```

Then add one line to the app's `modules.json` (or point `ADVISOR_MODULES_FILE` at a file that holds it):

```json
{
    "modules": [
        "Waynestate\\Squire\\Advisor\\SquireModule"
    ]
}
```

## The two read-only tools [#](https://squire.apps.wayne.edu/docs/guides/advisor.html#the-two-read-only-tools)

Two tools appear. Both are read-only, and each takes exactly one argument: a path inside the project root.

### squire_doctor [#](https://squire.apps.wayne.edu/docs/guides/advisor.html#squire-doctor)

`squire_doctor` runs Squire's deploy-setup checks (the S-rules, S1 to S78) against the app folder and returns the same JSON report `vendor/bin/squire doctor --format=json` prints. It runs in-process: no subprocess, no network, nothing written.

### squire_drift [#](https://squire.apps.wayne.edu/docs/guides/advisor.html#squire-drift)

`squire_drift` reports which of the files Squire wrote into the app have drifted from the current templates, the same check `vendor/bin/squire init --check` performs: missing, outdated, edited or untracked, per file. It writes nothing; `squire init` is the fix, and it never overwrites an edit.

Note

Every answer labels its source and says plainly what ran. Squire reports; Squire in CI decides pass or fail. A squire can tell you where the armor is dented; only the knight gets to charge.
